<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ReleaseChecklist
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @return list<array{name:string,passed:bool,detail:string}> */
    public function run(): array
    {
        $checks = [];
        $required = [
            'public/index.php', 'public/.htaccess', 'public/.user.ini', 'public/uploads/.htaccess',
            'bootstrap/app.php', 'composer.json', 'composer.lock', '.env.example',
            'storage/cache', 'storage/logs', 'storage/sessions', 'storage/private', 'storage/private/.htaccess',
            'docs/INSTALLATION.md', 'docs/PRODUCTION.md', 'docs/PRODUCTION_HARDENING.md',
        ];
        $missing = array_values(array_filter($required, fn (string $path): bool => ! file_exists($this->rootPath . '/' . $path)));
        $checks[] = [
            'name' => 'Required distribution files',
            'passed' => $missing === [],
            'detail' => $missing === [] ? 'All required files and directories exist.' : 'Missing: ' . implode(', ', $missing),
        ];

        $public = realpath($this->rootPath . '/public');
        $app = realpath($this->rootPath . '/app');
        $separated = $public !== false && $app !== false
            && ! str_starts_with($app . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR);
        $checks[] = [
            'name' => 'Public root isolation',
            'passed' => $separated,
            'detail' => $separated ? 'Application source is outside public/.' : 'Application source must not be inside public/.',
        ];

        $unsafe = $this->unsafePublicFiles($public === false ? null : $public);
        $checks[] = [
            'name' => 'Public file safety',
            'passed' => $unsafe === [],
            'detail' => $unsafe === [] ? 'No sensitive or executable files were detected.' : 'Unsafe: ' . implode(', ', $unsafe),
        ];

        $example = @file_get_contents($this->rootPath . '/.env.example');
        $safeExample = is_string($example)
            && preg_match('/^DB_PASSWORD=(?:""|\'\')?\s*$/m', $example) === 1
            && preg_match('/^MAIL_PASSWORD=(?:""|\'\')?\s*$/m', $example) === 1
            && preg_match('/^APP_KEY=(?:""|\'\')?\s*$/m', $example) === 1;
        $checks[] = [
            'name' => 'Example secrets',
            'passed' => $safeExample,
            'detail' => $safeExample ? '.env.example contains no credential values.' : 'Example secret fields must remain empty.',
        ];

        $userIni=@file_get_contents($this->rootPath.'/public/.user.ini');$safeIni=is_string($userIni)&&preg_match('/^display_errors=Off$/m',$userIni)===1&&preg_match('/^session\.use_strict_mode=1$/m',$userIni)===1;
        $checks[]=['name'=>'Shared-host PHP hardening','passed'=>$safeIni,'detail'=>$safeIni?'public/.user.ini contains production-safe defaults.':'public/.user.ini must disable displayed errors and enable strict sessions.'];

        return $checks;
    }

    public function passed(): bool
    {
        foreach ($this->run() as $check) if (! $check['passed']) return false;
        return true;
    }

    /** @return list<string> */
    private function unsafePublicFiles(?string $public): array
    {
        if ($public === null || ! is_dir($public)) return ['public/'];
        $unsafe = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($public) + 1));
            if ($item->isLink()) {
                $unsafe[] = $relative . ' (symlink)';
                continue;
            }
            if (! $item->isFile()) continue;
            $basename = strtolower($item->getBasename());
            $extension = strtolower($item->getExtension());
            if (($extension === 'php' && $relative !== 'index.php')
                || in_array($extension, ['sql', 'log', 'zip', 'bak'], true)
                || in_array($basename, ['.env', 'composer.json', 'composer.lock'], true)) {
                $unsafe[] = $relative;
            }
        }
        sort($unsafe, SORT_STRING);
        return $unsafe;
    }
}
