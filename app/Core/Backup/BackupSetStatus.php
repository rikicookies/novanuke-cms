<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use Throwable;

final class BackupSetStatus
{
    public function __construct(private readonly string $directory) {}

    /** @return list<array<string,mixed>> */
    public function inspect(?string $passphrase = null): array
    {
        if (! is_dir($this->directory) || is_link($this->directory)) return [];
        $items = [];
        $entries = new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($entries as $entry) {
            if (! $entry->isDir() || $entry->isLink()) continue;
            $name = $entry->getFilename();
            if (! str_starts_with($name, 'set-') && ! str_starts_with($name, '.incomplete-set-')) continue;
            $path = $entry->getPathname();
            $manifest = $path.DIRECTORY_SEPARATOR.'manifest.json';
            $record = ['backup_set_id'=>str_replace('.incomplete-','',$name),'path'=>$manifest,'status'=>'incomplete','detail'=>'Complete manifest is missing.'];
            if (! str_starts_with($name, '.incomplete-') && is_file($manifest) && ! is_link($manifest)) {
                try {
                    $verified=(new BackupVerifier($this->directory))->verifyManifest($manifest, false, $passphrase);
                    $data=$verified['manifest'];
                    $record=[
                        'backup_set_id'=>$data['backup_set_id'], 'path'=>$manifest, 'status'=>'valid',
                        'format'=>$data['format'], 'timestamp'=>$data['completed_at'],
                        'components'=>array_keys($data['components']),
                        'bytes'=>array_sum(array_column($data['components'],'bytes')),
                        'checksums'=>'valid', 'warnings'=>$data['warnings'] ?? [], 'compatibility'=>'compatible',
                        'encrypted'=>((bool)($data['components']['database']['encrypted']??false)||(bool)($data['components']['files']['encrypted']??false)),
                        'detail'=>'Manifest and both components verified.',
                    ];
                } catch (Throwable $error) {
                    $message = strtolower($error->getMessage());
                    $incompatible=str_contains($message,'incompatible')||str_contains($message,'unsupported');
                    $secret=str_contains($message,'passphrase is required');
                    $record['status']=$secret?'secret-required':($incompatible?'incompatible':'corrupt');
                    $record['encrypted']=$secret;
                    $record['detail']=$secret?'An encryption passphrase is required to verify this backup set.':$error->getMessage();
                }
            }
            $items[]=$record;
        }
        usort($items,static fn(array $a,array $b):int=>strcmp((string)$b['backup_set_id'],(string)$a['backup_set_id']));
        return $items;
    }
}
