<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use PDO;

/**
 * A migration whose final states can be verified after non-transactional DDL.
 *
 * Implementations must make both up() and down() safe to repeat. MySQL may
 * commit only part of either method before the PHP process is interrupted.
 */
interface RecoverableMigration extends Migration
{
    public function isApplied(PDO $database): bool;

    public function isRolledBack(PDO $database): bool;
}
