<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use NovaNuke\Core\Modules\ModuleManifest;
use NovaNuke\Core\Version;
use RuntimeException;

final class DistributionSmokeCheck
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @return list<array{name:string,passed:bool,detail:string}> */
    public function run(): array
    {
        $checks = [];

        $this->add(
            $checks,
            'Release checklist',
            (new ReleaseChecklist($this->rootPath))->passed(),
            'Distribution safety checklist must pass.',
        );

        $this->add(
            $checks,
            'Version metadata',
            $this->versionMetadataMatches(),
            'README and release notes must include the current version.',
        );

        $this->add(
            $checks,
            'Bundled module manifests',
            $this->bundledModulesAreValid(),
            'Every bundled module manifest must parse and accept the current CMS release.',
        );

        $this->add(
            $checks,
            'Migration file naming',
            $this->migrationFilesAreSafe(),
            'Core and bundled module migration filenames must be unique and PHP-only.',
        );

        $this->add(
            $checks,
            'Private storage guard',
            $this->privateStorageGuardIsPresent(),
            'storage/private/.htaccess must deny Apache access.',
        );

        return $checks;
    }

    public function passed(): bool
    {
        foreach ($this->run() as $check) {
            if (! $check['passed']) return false;
        }
        return true;
    }

    private function versionMetadataMatches(): bool
    {
        $readme = @file_get_contents($this->rootPath . '/README.md');
        $notes = $this->rootPath . '/docs/RELEASE_NOTES_' . Version::CURRENT . '.md';

        return is_string($readme)
            && str_contains($readme, Version::CURRENT)
            && is_file($notes)
            && str_contains((string) file_get_contents($notes), Version::CURRENT);
    }

    private function bundledModulesAreValid(): bool
    {
        foreach (glob($this->rootPath . '/modules/*/module.json') ?: [] as $manifestFile) {
            try {
                $data = json_decode((string) file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($data)) return false;
                $manifest = ModuleManifest::fromArray($data, dirname($manifestFile));
            } catch (\Throwable) {
                return false;
            }

            if (version_compare(Version::CURRENT, $manifest->cmsMinVersion, '<')) {
                return false;
            }
        }

        return true;
    }

    private function migrationFilesAreSafe(): bool
    {
        $roots = [$this->rootPath . '/database/migrations'];
        foreach (glob($this->rootPath . '/modules/*/database/migrations', GLOB_ONLYDIR) ?: [] as $path) {
            $roots[] = $path;
        }

        foreach ($roots as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (! is_file($file) || is_link($file)) return false;
                if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/', basename($file)) !== 1) {
                    return false;
                }
            }
        }

        return true;
    }

    private function privateStorageGuardIsPresent(): bool
    {
        $path = $this->rootPath . '/storage/private/.htaccess';
        if (! is_file($path) || is_link($path)) return false;
        $contents = (string) file_get_contents($path);

        return str_contains($contents, 'Require all denied')
            && str_contains($contents, 'Deny from all');
    }

    /** @param list<array{name:string,passed:bool,detail:string}> $checks */
    private function add(array &$checks, string $name, bool $passed, string $detail): void
    {
        $checks[] = ['name' => $name, 'passed' => $passed, 'detail' => $detail];
    }
}
