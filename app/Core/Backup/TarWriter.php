<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use RuntimeException;

final class TarWriter
{
    /** @var resource */
    private $stream;

    /** @param resource $stream */
    public function __construct($stream)
    {
        if (! is_resource($stream)) throw new RuntimeException('Archive stream is invalid.');
        $this->stream = $stream;
    }

    public function addFile(string $archivePath, string $sourcePath): void
    {
        if (! is_file($sourcePath) || is_link($sourcePath)) throw new RuntimeException("Backup source is not a regular file: {$archivePath}");
        $size = filesize($sourcePath);
        if ($size === false) throw new RuntimeException("Unable to inspect backup source: {$archivePath}");
        $input = fopen($sourcePath, 'rb');
        if ($input === false) throw new RuntimeException("Unable to read backup source: {$archivePath}");
        try {
            $this->writeHeader($archivePath, $size, filemtime($sourcePath) ?: time());
            if (stream_copy_to_stream($input, $this->stream) !== $size) throw new RuntimeException("Unable to archive file: {$archivePath}");
            $this->pad($size);
        } finally {
            fclose($input);
        }
    }

    public function addString(string $archivePath, string $contents): void
    {
        $this->writeHeader($archivePath, strlen($contents), time());
        $this->write($contents);
        $this->pad(strlen($contents));
    }

    public function finish(): void
    {
        $this->write(str_repeat("\0", 1024));
    }

    private function writeHeader(string $path, int $size, int $modifiedAt): void
    {
        [$name, $prefix] = $this->splitPath($path);
        $header = str_pad($name, 100, "\0")
            . $this->octal(0600, 8)
            . $this->octal(0, 8)
            . $this->octal(0, 8)
            . $this->octal($size, 12)
            . $this->octal($modifiedAt, 12)
            . str_repeat(' ', 8)
            . '0'
            . str_repeat("\0", 100)
            . "ustar\0"
            . "00"
            . str_pad('novanuke', 32, "\0")
            . str_pad('novanuke', 32, "\0")
            . $this->octal(0, 8)
            . $this->octal(0, 8)
            . str_pad($prefix, 155, "\0")
            . str_repeat("\0", 12);
        $checksum = array_sum(unpack('C*', $header));
        $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
        $this->write($header);
    }

    /** @return array{string,string} */
    private function splitPath(string $path): array
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        if ($path === '' || str_contains($path, '../') || str_starts_with($path, '..')) throw new RuntimeException('Archive path is invalid.');
        if (strlen($path) <= 100) return [$path, ''];
        for ($offset = strlen($path) - 1; $offset > 0; $offset--) {
            if ($path[$offset] !== '/') continue;
            $prefix = substr($path, 0, $offset);
            $name = substr($path, $offset + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) return [$name, $prefix];
        }
        throw new RuntimeException("Archive path is too long: {$path}");
    }

    private function octal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT) . "\0";
    }

    private function pad(int $size): void
    {
        $padding = (512 - ($size % 512)) % 512;
        if ($padding > 0) $this->write(str_repeat("\0", $padding));
    }

    private function write(string $contents): void
    {
        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($this->stream, substr($contents, $written));
            if ($result === false || $result === 0) throw new RuntimeException('Unable to write file backup.');
            $written += $result;
        }
    }
}
