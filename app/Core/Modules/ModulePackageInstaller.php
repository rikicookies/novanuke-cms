<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ModulePackageInstaller
{
    private const MAX_ARCHIVE_BYTES = 52_428_800;
    private const MAX_UNCOMPRESSED_BYTES = 104_857_600;
    private const MAX_FILES = 2_000;

    public function __construct(
        private readonly string $modulesPath,
        private readonly ModuleCompatibilityChecker $compatibility,
    ) {
    }

    /** @param array<string, array<string, mixed>> $installed */
    public function install(string $archivePath, array $installed): ModuleManifest
    {
        $package = $this->inspect($archivePath, $installed);
        $destination = $this->modulesRoot() . DIRECTORY_SEPARATOR . $package->directory;
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException("Module destination already exists: {$package->directory}");
        }

        $stagingRoot = $this->modulesRoot() . DIRECTORY_SEPARATOR . '.install-' . bin2hex(random_bytes(8));
        $stagedModule = $stagingRoot . DIRECTORY_SEPARATOR . $package->directory;
        if (! mkdir($stagingRoot, 0700)) {
            throw new RuntimeException('Unable to create module package staging directory.');
        }

        try {
            $this->extract($archivePath, $stagingRoot);
            $this->manifest($stagedModule, $package->directory);
            if (! rename($stagedModule, $destination)) {
                throw new RuntimeException('Unable to publish the module package atomically.');
            }
        } catch (\Throwable $error) {
            $this->removeDirectory($stagingRoot);
            throw $error;
        }

        $this->removeDirectory($stagingRoot);
        return ModuleManifest::fromArray($package->manifest->toArray(), $destination);
    }

    /** @param array<string, array<string, mixed>> $installed */
    public function inspect(string $archivePath, array $installed): ModulePackage
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The ZIP extension is required to install module packages.');
        }
        if (! is_file($archivePath) || is_link($archivePath) || ! is_readable($archivePath)) {
            throw new RuntimeException('Module package is not a readable regular file.');
        }
        $archiveBytes = filesize($archivePath);
        if ($archiveBytes === false || $archiveBytes <= 0 || $archiveBytes > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Module package exceeds the allowed archive size.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Unable to open the module package.');
        }

        try {
            [$directory, $files, $bytes] = $this->validateEntries($zip);
            $manifestIndex = $zip->locateName($directory . '/module.json', 0);
            if ($manifestIndex === false) {
                throw new RuntimeException('Module package must contain module.json in its top-level module directory.');
            }
            $manifestJson = $zip->getFromIndex($manifestIndex);
            if (! is_string($manifestJson) || strlen($manifestJson) > 262_144) {
                throw new RuntimeException('Module manifest is missing or too large.');
            }
            try {
                $data = json_decode($manifestJson, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('Invalid module manifest JSON.', previous: $error);
            }
            if (! is_array($data)) {
                throw new RuntimeException('Module manifest must contain an object.');
            }
            $manifest = ModuleManifest::fromArray($data, $this->modulesRoot() . DIRECTORY_SEPARATOR . $directory);
            $providerRelative = str_replace('\\', '/', substr($manifest->provider, strlen('Modules\\' . $directory . '\\'))) . '.php';
            if ($providerRelative === '.php' || $zip->locateName($directory . '/' . $providerRelative, 0) === false) {
                throw new RuntimeException('Module package does not contain its declared provider class file.');
            }
            $check = $this->compatibility->check($manifest, $installed);
            if (! $check->compatible) {
                throw new RuntimeException((string) $check->reason);
            }
            return new ModulePackage($manifest, $directory, $files, $bytes);
        } finally {
            $zip->close();
        }
    }

    /** @return array{string,int,int} */
    private function validateEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException('Module package contains an invalid number of entries.');
        }
        $topLevel = null;
        $files = 0;
        $bytes = 0;
        $seen = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (! is_array($stat) || ! isset($stat['name'])) {
                throw new RuntimeException('Unable to inspect a module package entry.');
            }
            $name = str_replace('\\', '/', (string) $stat['name']);
            $trimmed = rtrim($name, '/');
            if ($trimmed === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name)) {
                throw new RuntimeException('Module package contains an unsafe path.');
            }
            $segments = explode('/', $trimmed);
            if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
                throw new RuntimeException('Module package contains an unsafe path.');
            }
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $segments[0])) {
                throw new RuntimeException('Module package top-level directory is invalid.');
            }
            $topLevel ??= $segments[0];
            if ($topLevel !== $segments[0]) {
                throw new RuntimeException('Module package must contain exactly one top-level directory.');
            }
            $key = strtolower($trimmed);
            if (isset($seen[$key])) {
                throw new RuntimeException('Module package contains duplicate paths.');
            }
            $seen[$key] = true;

            $operations = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $operations, $attributes)) {
                $type = ($attributes >> 16) & 0xF000;
                if ($type === 0xA000) {
                    throw new RuntimeException('Module package may not contain symbolic links.');
                }
            }

            if (! str_ends_with($name, '/')) {
                $files++;
                $bytes += max(0, (int) ($stat['size'] ?? 0));
                if ($bytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('Module package exceeds the allowed extracted size.');
                }
            }
        }

        if ($topLevel === null || $files === 0) {
            throw new RuntimeException('Module package is empty.');
        }
        return [$topLevel, $files, $bytes];
    }

    private function extract(string $archivePath, string $stagingRoot): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Unable to reopen the module package.');
        }
        try {
            // Revalidate after reopening so a replaced upload cannot introduce
            // an unsafe path between inspection and extraction.
            $this->validateEntries($zip);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $relative = rtrim($name, '/');
                $destination = $stagingRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (str_ends_with($name, '/')) {
                    if (! is_dir($destination) && ! mkdir($destination, 0700, true)) {
                        throw new RuntimeException('Unable to create a module package directory.');
                    }
                    continue;
                }
                $parent = dirname($destination);
                if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
                    throw new RuntimeException('Unable to create a module package directory.');
                }
                $input = $zip->getStream((string) $stat['name']);
                $output = fopen($destination, 'xb');
                if (! is_resource($input) || ! is_resource($output)) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new RuntimeException('Unable to extract a module package file.');
                }
                $copied = stream_copy_to_stream($input, $output, self::MAX_UNCOMPRESSED_BYTES + 1);
                fclose($input);
                fclose($output);
                if ($copied === false || $copied > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('Unable to extract a module package file safely.');
                }
                @chmod($destination, 0600);
            }
        } finally {
            $zip->close();
        }
    }

    private function manifest(string $modulePath, string $directory): ModuleManifest
    {
        $file = $modulePath . DIRECTORY_SEPARATOR . 'module.json';
        if (! is_file($file)) {
            throw new RuntimeException('Extracted module manifest is missing.');
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Extracted module manifest is invalid.', previous: $error);
        }
        if (! is_array($data)) {
            throw new RuntimeException('Extracted module manifest must contain an object.');
        }
        return ModuleManifest::fromArray($data, $this->modulesRoot() . DIRECTORY_SEPARATOR . $directory);
    }

    private function modulesRoot(): string
    {
        $path = rtrim($this->modulesPath, '/\\');
        if ($path === '' || ! is_dir($path) || is_link($path) || ! is_writable($path)) {
            throw new RuntimeException('Modules directory is not writable or is unsafe.');
        }
        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException('Unable to resolve the modules directory.');
        }
        return $real;
    }

    private function removeDirectory(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) return;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
