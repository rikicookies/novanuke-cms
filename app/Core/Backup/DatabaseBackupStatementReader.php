<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use RuntimeException;

/**
 * Reads only the statement grammar emitted by DatabaseBackup.
 *
 * The reader retains at most one SQL statement plus a small input chunk.
 * It is deliberately not a general-purpose SQL dump parser.
 */
final class DatabaseBackupStatementReader
{
    public const DEFAULT_MAX_STATEMENT_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly int $maxStatementBytes = self::DEFAULT_MAX_STATEMENT_BYTES)
    {
        if ($this->maxStatementBytes < 1024) {
            throw new RuntimeException('Database backup statement limit is too small.');
        }
    }

    /** @return iterable<string> */
    public function read(string $path): iterable
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new RuntimeException('Database backup is not a regular readable file.');
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Database backup SQL cannot be opened.');
        }

        $buffer = '';
        $quote = null;
        $escaped = false;
        $comment = false;
        $bytes = 0;
        $sawStatement = false;
        try {
            $pending = '';
            while (true) {
                if ($pending !== '') {
                    $char = $pending[0];
                    $pending = substr($pending, 1);
                } else {
                    $char = fread($stream, 1);
                }
                if ($char === false || $char === '') {
                    break;
                }

                if ($comment) {
                    if ($char === "\n") {
                        $comment = false;
                        $this->append($buffer, "\n", $bytes);
                    }
                    continue;
                }

                if ($quote !== null) {
                    $this->append($buffer, $char, $bytes);
                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }
                    if ($char === '\\' && $quote !== '`') {
                        $escaped = true;
                        continue;
                    }
                    if ($char === $quote) {
                        $next = fread($stream, 1);
                        if ($next === $quote) {
                            $this->append($buffer, $next, $bytes);
                            continue;
                        }
                        if (is_string($next) && $next !== '') $pending = $next . $pending;
                        $quote = null;
                    }
                    continue;
                }

                    if ($char === '-') {
                        $next = fread($stream, 1);
                        $third = $next === '-' ? fread($stream, 1) : false;
                        if ($next === '-' && is_string($third) && ctype_space($third)) {
                            if ($third === "\n") {
                                $this->append($buffer, "\n", $bytes);
                            } else {
                                $comment = true;
                            }
                            continue;
                        }
                        $this->append($buffer, $char, $bytes);
                        if (is_string($next) && $next !== '') {
                            $pending = $next . (is_string($third) ? $third : '') . $pending;
                        }
                        continue;
                    }
                    if ($char === "'" || $char === '"' || $char === '`') {
                        $quote = $char;
                        $this->append($buffer, $char, $bytes);
                        continue;
                    }
                    if ($char === ';') {
                        $statement = trim($buffer);
                        $buffer = '';
                        $bytes = 0;
                        if ($statement === '') {
                            continue;
                        }
                        $this->assertSupported($statement);
                        $sawStatement = true;
                        yield $statement;
                        continue;
                    }
                    $this->append($buffer, $char, $bytes);
            }

            if ($quote !== null) {
                throw new RuntimeException($escaped ? 'Database backup contains a truncated escape sequence.' : 'Database backup contains an unterminated quoted value.');
            }
            if ($comment) {
                $comment = false;
            }
            if (trim($buffer) !== '') {
                throw new RuntimeException('Database backup contains a trailing unterminated SQL statement.');
            }
            if (! $sawStatement) {
                throw new RuntimeException('Database backup contains no executable SQL.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function append(string &$buffer, string $text, int &$bytes): void
    {
        $bytes += strlen($text);
        if ($bytes > $this->maxStatementBytes) {
            throw new RuntimeException("Database backup statement exceeds the {$this->maxStatementBytes}-byte limit.");
        }
        $buffer .= $text;
    }

    private function assertSupported(string $statement): void
    {
        if (preg_match('/^SET NAMES utf8mb4$/', $statement) === 1
            || preg_match('/^SET FOREIGN_KEY_CHECKS=[01]$/', $statement) === 1) {
            return;
        }
        if (preg_match('/^DROP TABLE IF EXISTS `(?:``|[^`])+`$/', $statement) === 1) {
            return;
        }
        if (preg_match('/^CREATE TABLE `(?:``|[^`])+`\s*\(/s', $statement) === 1) {
            return;
        }
        if ($this->isSingleRowInsert($statement)) return;
        throw new RuntimeException('Database backup contains unsupported SQL syntax.');
    }

    private function isSingleRowInsert(string $statement): bool
    {
        $identifier = '`(?:``|[^`])+`';
        $pattern = '/^INSERT INTO ' . $identifier . '\s*\((' . $identifier . '(?:\s*,\s*' . $identifier . ')*)\)\s*VALUES\s*(?<values>\(.+\))$/s';
        if (preg_match($pattern, $statement, $matches) !== 1) return false;

        $values = (string) $matches['values'];
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($values);
        for ($i = 0; $i < $length; $i++) {
            $char = $values[$i];
            if ($quote !== null) {
                if ($escaped) { $escaped = false; continue; }
                if ($char === '\\' && $quote !== '`') { $escaped = true; continue; }
                if ($char === $quote) {
                    if ($i + 1 < $length && $values[$i + 1] === $quote) { $i++; continue; }
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; continue; }
            if ($char === '(') {
                $depth++;
                if ($depth > 1) return false;
                continue;
            }
            if ($char === ')') {
                $depth--;
                if ($depth < 0) return false;
                continue;
            }
            if ($depth === 0 && ! ctype_space($char)) return false;
        }
        if ($quote !== null || $escaped || $depth !== 0) return false;
        $inner = trim(substr($values, 1, -1));
        $literal = '(?:NULL|\'(?:\\\\[\\s\\S]|\'\'|[^\'\\\\])*\')';
        $valuesPattern = '/^' . $literal . '(?:\\s*,\\s*' . $literal . ')*$/s';

        return preg_match($valuesPattern, $inner) === 1;
    }
}
