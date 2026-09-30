<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

final readonly class InstallationData
{
    public function __construct(
        public string $siteName,
        public string $siteUrl,
        public string $locale,
        public string $timezone,
        public string $databaseHost,
        public int $databasePort,
        public string $databaseName,
        public string $databaseUsername,
        public string $databasePassword,
        public string $adminUsername,
        public string $adminEmail,
        public string $adminPassword,
    ) {
    }
}
