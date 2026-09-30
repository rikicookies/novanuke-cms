<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['welcome_messages'];
    private const MIGRATION_VALUES = [['welcome_messages','message','The Welcome module is installed, enabled and rendering its own Twig view.']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS welcome_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $count = (int) $database->query('SELECT COUNT(*) FROM welcome_messages')->fetchColumn();
        if ($count === 0) {
            $statement = $database->prepare(
                'INSERT INTO welcome_messages (message, created_at) VALUES (:message, UTC_TIMESTAMP())'
            );
            $statement->execute(['message' => 'The Welcome module is installed, enabled and rendering its own Twig view.']);
        }
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS welcome_messages');
    }
};
