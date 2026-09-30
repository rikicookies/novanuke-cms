<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use RuntimeException;

/** Test seam used to model a process dying after MySQL committed DDL. */
final class MigrationInterruption extends RuntimeException
{
}
