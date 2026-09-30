<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['friendships','friend_blocks'];
    public function up(PDO $database): void
    {
        $database->exec("CREATE TABLE IF NOT EXISTS friendships (user_one_id BIGINT UNSIGNED NOT NULL,user_two_id BIGINT UNSIGNED NOT NULL,requested_by BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(user_one_id,user_two_id),KEY friendships_requested_index(requested_by,status),CONSTRAINT friendships_one_fk FOREIGN KEY(user_one_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT friendships_two_fk FOREIGN KEY(user_two_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT friendships_requester_fk FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $database->exec("CREATE TABLE IF NOT EXISTS friend_blocks (blocker_user_id BIGINT UNSIGNED NOT NULL,blocked_user_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(blocker_user_id,blocked_user_id),CONSTRAINT friend_blocks_blocker_fk FOREIGN KEY(blocker_user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT friend_blocks_blocked_fk FOREIGN KEY(blocked_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(PDO $database): void { $database->exec('DROP TABLE IF EXISTS friend_blocks');$database->exec('DROP TABLE IF EXISTS friendships'); }
};
