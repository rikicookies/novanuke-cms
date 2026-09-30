<?php

declare(strict_types=1);

namespace NovaNuke\Core\Themes;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class ThemeAssetPublisher
{
    private const ALLOWED_EXTENSIONS = [
        'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'map',
    ];

    private readonly Closure $copyFile;
    private readonly Closure $renamePath;

    public function __construct(
        private readonly string $publicThemePath,
        ?Closure $copyFile = null,
        ?Closure $renamePath = null,
    ) {
        $this->copyFile = $copyFile ?? static fn (string $source, string $target): bool => copy($source, $target);
        $this->renamePath = $renamePath ?? static fn (string $from, string $to): bool => rename($from, $to);
    }

    public function publish(ThemeManifest $manifest): void
    {
        $destination = $this->destination($manifest->slug);
        $plan = $this->buildValidatedPlan($manifest);
        $parent = dirname($destination);
        if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
            throw new RuntimeException('Could not create the public theme asset root.');
        }

        $token = bin2hex(random_bytes(8));
        $staging = $parent . '/.' . $manifest->slug . '.staging-' . $token;
        $backup = $parent . '/.' . $manifest->slug . '.backup-' . $token;
        $hadDestination = is_dir($destination);
        $destinationMoved = false;

        try {
            $this->copyPlanToStaging($plan, $staging);
            $this->verifyStaging($plan, $staging);

            if ($hadDestination) {
                if (! ($this->renamePath)($destination, $backup)) {
                    throw new RuntimeException('Could not stage the current theme assets for atomic replacement.');
                }
                $destinationMoved = true;
            }

            if (! ($this->renamePath)($staging, $destination)) {
                if ($destinationMoved && ! ($this->renamePath)($backup, $destination)) {
                    throw new RuntimeException('Theme asset swap failed and the previous assets could not be restored automatically.');
                }
                $destinationMoved = false;
                throw new RuntimeException('Could not atomically publish the staged theme assets.');
            }

            $destinationMoved = false;
            if (is_dir($backup)) {
                $this->removeDirectory($backup);
            }
        } catch (Throwable $error) {
            if ($destinationMoved && is_dir($backup) && ! is_dir($destination)) {
                if (! ($this->renamePath)($backup, $destination)) {
                    throw new RuntimeException(
                        'Theme asset publication failed and rollback could not restore the previous assets.',
                        0,
                        $error,
                    );
                }
            }
            throw $error;
        } finally {
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }
            // A backup is only disposable when the active destination exists. If rollback itself
            // failed, preserve the backup for operator recovery rather than deleting the last copy.
            if (is_dir($backup) && is_dir($destination)) {
                $this->removeDirectory($backup);
            }
        }
    }

    public function remove(string $slug): void
    {
        $this->removeDirectory($this->destination($slug));
    }

    /** @return list<array{source:string,relative:string,sha256:string}> */
    private function buildValidatedPlan(ThemeManifest $manifest): array
    {
        $plan = [];
        $source = $manifest->path . '/assets';
        if (is_link($source)) {
            throw new RuntimeException('Theme assets directory may not be a symbolic link.');
        }
        if (is_dir($source)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    throw new RuntimeException('Theme assets may not contain symbolic links.');
                }
                if ($item->isDir()) {
                    continue;
                }
                if (! $item->isFile()) {
                    throw new RuntimeException('Theme assets may contain only regular files and directories.');
                }
                $extension = strtolower($item->getExtension());
                if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                    throw new RuntimeException("Theme asset extension is not allowed: {$extension}");
                }
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
                $hash = hash_file('sha256', $item->getPathname());
                if ($hash === false) {
                    throw new RuntimeException('Could not hash a theme asset before publication.');
                }
                $plan[] = ['source' => $item->getPathname(), 'relative' => $relative, 'sha256' => $hash];
            }
        }

        if ($manifest->screenshot !== '') {
            $screenshot = basename($manifest->screenshot);
            $sourceScreenshot = $manifest->path . '/' . $screenshot;
            $extension = strtolower(pathinfo($screenshot, PATHINFO_EXTENSION));
            if (is_link($sourceScreenshot) || ! is_file($sourceScreenshot) || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw new RuntimeException('Theme screenshot is missing or unsupported.');
            }
            $hash = hash_file('sha256', $sourceScreenshot);
            if ($hash === false) {
                throw new RuntimeException('Could not hash the theme screenshot before publication.');
            }
            $plan[] = ['source' => $sourceScreenshot, 'relative' => $screenshot, 'sha256' => $hash];
        }

        return $plan;
    }

    /** @param list<array{source:string,relative:string,sha256:string}> $plan */
    private function copyPlanToStaging(array $plan, string $staging): void
    {
        if (! mkdir($staging, 0775, true) && ! is_dir($staging)) {
            throw new RuntimeException('Could not create the theme asset staging directory.');
        }
        foreach ($plan as $file) {
            $target = $staging . '/' . $file['relative'];
            $directory = dirname($target);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException('Could not create a staged theme asset directory.');
            }
            if (! ($this->copyFile)($file['source'], $target)) {
                throw new RuntimeException('Could not copy a theme asset to staging.');
            }
        }
    }

    /** @param list<array{source:string,relative:string,sha256:string}> $plan */
    private function verifyStaging(array $plan, string $staging): void
    {
        foreach ($plan as $file) {
            $target = $staging . '/' . $file['relative'];
            if (is_link($target) || ! is_file($target)) {
                throw new RuntimeException('A staged theme asset is missing or unsafe.');
            }
            $hash = hash_file('sha256', $target);
            if ($hash === false || ! hash_equals($file['sha256'], $hash)) {
                throw new RuntimeException('A staged theme asset failed integrity verification.');
            }
        }
    }

    private function destination(string $slug): string
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $slug)) {
            throw new RuntimeException('Invalid theme asset destination.');
        }

        return rtrim($this->publicThemePath, '/\\') . '/' . $slug;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $root = rtrim(str_replace('\\', '/', $this->publicThemePath), '/');
        $normalized = str_replace('\\', '/', $path);
        if (! str_starts_with($normalized . '/', $root . '/') || $normalized === $root) {
            throw new RuntimeException('Refusing to remove an unsafe theme asset path.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
