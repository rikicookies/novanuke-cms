<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use InvalidArgumentException;
use NovaNuke\Core\Backup\BackupExportBundle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupExportBundleTest extends TestCase
{
    public function testInvalidBackupSetIdIsRejectedBeforeFilesystemAccess(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BackupExportBundle(sys_get_temp_dir()))->create('../outside', sys_get_temp_dir() . '/invalid-export.tar');
    }

    public function testCorruptBundleIsRejectedWithoutExtraction(): void
    {
        $path = sys_get_temp_dir() . '/novanuke-corrupt-export-' . bin2hex(random_bytes(5)) . '.tar';
        file_put_contents($path, 'not a tar');
        try {
            $this->expectException(RuntimeException::class);
            (new BackupExportBundle(sys_get_temp_dir()))->verify($path);
        } finally { @unlink($path); }
    }
}
