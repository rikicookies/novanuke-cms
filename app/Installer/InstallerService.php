<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

use NovaNuke\Core\Version;
use NovaNuke\Core\Database\Migrator;
use PDO;
use RuntimeException;
use Throwable;

final class InstallerService
{
    private readonly InstallationLock $installationLock;

    public function __construct(
        private readonly string $rootPath,
        private readonly EnvWriter $envWriter,
        ?InstallationLock $installationLock = null,
    ) {
        $this->installationLock = $installationLock ?? new InstallationLock();
    }

    /** @return list<string> */
    public function install(InstallationData $data): array
    {
        $lockPath = $this->rootPath . '/storage/installed.lock';
        $envPath = $this->rootPath . '/.env';

        if (file_exists($lockPath) || is_link($lockPath)) {
            throw new RuntimeException('NovaNuke is already installed.');
        }
        if (file_exists($envPath) || is_link($envPath)) {
            throw new RuntimeException('The environment file already exists and will not be overwritten.');
        }

        (new StorageProvisioner())->provision($this->rootPath);

        $database = $this->connectAndCreateDatabase($data);
        $this->assertDatabaseIsEmpty($database);

        try {
            $migrations = (new Migrator($database))->run($this->rootPath . '/database/migrations');

            $database->beginTransaction();
            try {
                $this->createAdministrator($database, $data);
                $this->saveInitialSettings($database, $data);
                $database->commit();
            } catch (Throwable $error) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $error;
            }

            (new FreshInstallProvisioner($this->rootPath, $database))->provision();

            $this->envWriter->write($envPath, [
            'APP_NAME' => $data->siteName,
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'APP_URL' => rtrim($data->siteUrl, '/'),
            'APP_TIMEZONE' => $data->timezone,
            'APP_LOCALE' => $data->locale,
            'APP_FALLBACK_LOCALE' => 'en',
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => $data->databaseHost,
            'DB_PORT' => $data->databasePort,
            'DB_DATABASE' => $data->databaseName,
            'DB_USERNAME' => $data->databaseUsername,
            'DB_PASSWORD' => $data->databasePassword,
            'DB_CHARSET' => 'utf8mb4',
            'SESSION_NAME' => 'novanuke_session',
            'SESSION_LIFETIME' => 7200,
            'SESSION_IDLE_TIMEOUT' => 1800,
            'SESSION_ROTATION_INTERVAL' => 900,
            'SESSION_SECURE' => str_starts_with(strtolower($data->siteUrl), 'https://'),
            'SESSION_SAME_SITE' => 'Lax',
            'SECURITY_HEADERS_ENABLED' => true,
            'SECURITY_HSTS_ENABLED' => false,
            'SECURITY_HSTS_MAX_AGE' => 31536000,
            'MAIL_MAILER' => 'log',
            'MAIL_FROM_ADDRESS' => 'noreply@localhost',
            'MAIL_FROM_NAME' => $data->siteName,
            ]);

            $this->installationLock->create($lockPath, Version::CURRENT);

            return $migrations;
        } catch (Throwable $error) {
            if (is_file($envPath) && ! is_link($envPath)) {
                @unlink($envPath);
            }
            $this->rollbackOwnedSchema($database);
            throw $error;
        }
    }

    private function rollbackOwnedSchema(PDO $database): void
    {
        try {
            $database->exec('SET FOREIGN_KEY_CHECKS=0');
            $tables = $database->query(
                "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                if (! is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                    continue;
                }
                $database->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
        } catch (Throwable) {
            // Preserve the original installer failure. A retry will report any
            // remaining schema through the normal empty-database guard.
        } finally {
            try {
                $database->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
            }
        }
    }

    private function connectAndCreateDatabase(InstallationData $data): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $serverDsn = "mysql:host={$data->databaseHost};port={$data->databasePort};charset=utf8mb4";
        $server = new PDO($serverDsn, $data->databaseUsername, $data->databasePassword, $options);
        $databaseName = $data->databaseName;

        if (! preg_match('/^[a-zA-Z0-9_]{1,64}$/', $databaseName)) {
            throw new RuntimeException('Invalid database name.');
        }

        // Prefer an already-provisioned database. Shared-hosting database users often
        // have permission to use a schema created in the control panel but do not have
        // CREATE DATABASE permission. Only attempt creation when the schema is actually
        // missing.
        try {
            return new PDO(
                $serverDsn . ";dbname={$databaseName}",
                $data->databaseUsername,
                $data->databasePassword,
                $options,
            );
        } catch (\PDOException $error) {
            $driverCode = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : 0;
            if ($driverCode !== 1049) {
                throw $error;
            }
        }

        $server->exec(
            "CREATE DATABASE `{$databaseName}` "
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        return new PDO(
            $serverDsn . ";dbname={$databaseName}",
            $data->databaseUsername,
            $data->databasePassword,
            $options,
        );
    }

    private function createAdministrator(PDO $database, InstallationData $data): void
    {
        $existing = $database->prepare('SELECT COUNT(*) FROM users WHERE email = :email OR username = :username');
        $existing->execute(['email' => strtolower($data->adminEmail), 'username' => $data->adminUsername]);

        if ((int) $existing->fetchColumn() > 0) {
            throw new RuntimeException('The administrator username or email already exists.');
        }

        $statement = $database->prepare(
            'INSERT INTO users (username, email, password_hash, status, email_verified_at, created_at, updated_at) '
            . 'VALUES (:username, :email, :password_hash, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'username' => $data->adminUsername,
            'email' => strtolower($data->adminEmail),
            'password_hash' => password_hash($data->adminPassword, PASSWORD_DEFAULT),
            'status' => 'active',
        ]);
        $userId = (int) $database->lastInsertId();

        $role = $database->prepare('SELECT id FROM roles WHERE slug = :slug');
        $role->execute(['slug' => 'super-administrator']);
        $roleId = $role->fetchColumn();

        if ($roleId === false) {
            throw new RuntimeException('The Super Administrator role was not created.');
        }

        $assignment = $database->prepare(
            'INSERT INTO user_roles (user_id, role_id, created_at) VALUES (:user_id, :role_id, UTC_TIMESTAMP())'
        );
        $assignment->execute(['user_id' => $userId, 'role_id' => (int) $roleId]);

        $profile = $database->prepare(
            'INSERT INTO user_profiles (user_id, display_name, locale, timezone, created_at, updated_at) '
            . 'VALUES (:user_id, :display_name, :locale, :timezone, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $profile->execute([
            'user_id' => $userId,
            'display_name' => $data->adminUsername,
            'locale' => $data->locale,
            'timezone' => $data->timezone,
        ]);
    }

    private function assertDatabaseIsEmpty(PDO $database): void
    {
        $tables = (int) $database->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'
        )->fetchColumn();
        if ($tables !== 0) {
            throw new RuntimeException('The selected database is not empty. Choose an empty database to avoid overwriting existing data.');
        }
    }

    private function saveInitialSettings(PDO $database, InstallationData $data): void
    {
        $statement = $database->prepare(
            'INSERT INTO settings (`key`, `value`, `type`, `group_name`, created_at, updated_at) '
            . 'VALUES (:key, :value, :type, :group_name, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP()'
        );

        foreach ([
            ['site.name', $data->siteName, 'string', 'site'],
            ['site.description', 'A modern modular CMS with an old-school spirit.', 'string', 'site'],
            ['site.url', rtrim($data->siteUrl, '/'), 'string', 'site'],
            ['site.admin_email', strtolower($data->adminEmail), 'string', 'site'],
            ['site.locale', $data->locale, 'string', 'site'],
            ['site.timezone', $data->timezone, 'string', 'site'],
            ['site.date_format', 'F j, Y', 'string', 'site'],
            ['site.per_page', '10', 'integer', 'site'],
            ['site.homepage', 'home', 'string', 'site'],
            ['system.maintenance', '0', 'boolean', 'system'],
            ['system.core_version', Version::CURRENT, 'string', 'system'],
            ['system.core_updated_at', gmdate(DATE_ATOM), 'string', 'system'],
            ['users.registration_open', '0', 'boolean', 'users'],
        ] as [$key, $value, $type, $group]) {
            $statement->execute([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'group_name' => $group,
            ]);
        }
    }
}
