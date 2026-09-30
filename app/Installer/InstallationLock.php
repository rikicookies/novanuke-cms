<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

use RuntimeException;

final class InstallationLock
{
    public function create(string $path, string $version): void
    {
        if (file_exists($path) || is_link($path)) throw new RuntimeException('NovaNuke is already installed.');
        $payload = json_encode([
            'installed_at' => gmdate('c'),
            'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $stream = fopen($temporary, 'xb');
        if ($stream === false) throw new RuntimeException('The installation lock could not be created.');
        try {
            if (fwrite($stream, $payload) !== strlen($payload) || ! fflush($stream)) {
                throw new RuntimeException('The installation lock could not be written.');
            }
            fclose($stream);
            $stream = null;
            @chmod($temporary, 0600);
            if (file_exists($path) || is_link($path) || ! rename($temporary, $path)) {
                throw new RuntimeException('The installation lock could not be finalized.');
            }
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (is_file($temporary)) @unlink($temporary);
        }
    }
}
