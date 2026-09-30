<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

use NovaNuke\Core\ModuleApi;

final class ModuleCompatibilityChecker
{
    public function __construct(
        private readonly string $cmsVersion,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly string $apiVersion = ModuleApi::VERSION,
    ) {
    }

    /** @param array<string, array<string, mixed>> $installed */
    public function check(ModuleManifest $manifest, array $installed): ModuleCompatibility
    {
        [$requiredMajor,$requiredMinor]=array_map('intval',explode('.',$manifest->apiVersion));
        [$availableMajor,$availableMinor]=array_map('intval',explode('.',$this->apiVersion));
        if($requiredMajor!==$availableMajor||$requiredMinor>$availableMinor)return new ModuleCompatibility(false,"Requires NovaNuke extension API {$manifest->apiVersion}; available API is {$this->apiVersion}.");
        if (version_compare($this->phpVersion, $manifest->phpMinVersion, '<')) {
            return new ModuleCompatibility(false, "Requires PHP {$manifest->phpMinVersion} or newer.");
        }
        if (version_compare($this->cmsVersion, $manifest->cmsMinVersion, '<')) {
            return new ModuleCompatibility(false, "Requires NovaNuke {$manifest->cmsMinVersion} or newer.");
        }
        foreach ($manifest->dependencies as $slug => $minimumVersion) {
            if (! isset($installed[$slug])) {
                return new ModuleCompatibility(false, "Missing dependency: {$slug}");
            }
            if (version_compare((string) $installed[$slug]['installed_version'], $minimumVersion, '<')) {
                return new ModuleCompatibility(false, "Dependency {$slug} must be {$minimumVersion} or newer.");
            }
        }

        return new ModuleCompatibility(true);
    }
}
