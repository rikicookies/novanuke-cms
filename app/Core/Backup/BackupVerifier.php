<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use NovaNuke\Core\Version;
use RuntimeException;
use Throwable;

final class BackupVerifier
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<array{type:string,passed:bool,file:string,detail:string}> */
    public function verifyLatest(): array
    {
        $incomplete = glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '.incomplete-set-*', GLOB_ONLYDIR) ?: [];
        if ($incomplete !== []) {
            $detail = 'Incomplete backup staging exists and must not be treated as a valid set: ' . basename($incomplete[0]);
            return [
                ['type'=>'database','passed'=>false,'file'=>'','detail'=>$detail,'metadata'=>[]],
                ['type'=>'files','passed'=>false,'file'=>'','detail'=>$detail,'metadata'=>[]],
                ['type'=>'pair','passed'=>false,'file'=>'','detail'=>$detail,'metadata'=>[]],
            ];
        }
        $manifestPath = $this->latestManifest();
        if ($manifestPath !== null) return $this->verifyManifestResults($manifestPath);
        $databasePath = $this->latest('novanuke-db-*.sql');
        $filePath = $this->latest('novanuke-files-*.tar');
        $database = $this->verifyCandidate('database', $databasePath, $this->verifyDatabase(...));
        $files = $this->verifyCandidate('files', $filePath, $this->verifyFileArchive(...));

        return [$database, $files, $this->verifyPair($database, $files)];
    }

    /** @return array<string,mixed> */
    public function verifyManifest(string $manifestPath, bool $allowStaging = false): array
    {
        $this->assertRegularFile($manifestPath);
        $raw = file_get_contents($manifestPath);
        if (! is_string($raw) || $raw === '') throw new RuntimeException('Backup-set manifest is empty.');
        try { $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { throw new RuntimeException('Backup-set manifest JSON is invalid.', 0, $error); }
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1) throw new RuntimeException('Backup-set manifest format is unsupported.');
        $cmsVersion = (string) ($manifest['cms_version'] ?? '');
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $cmsVersion) !== 1) throw new RuntimeException('Backup-set CMS version is invalid.');
        if (explode('.', $cmsVersion, 2)[0] !== explode('.', Version::CURRENT, 2)[0]) throw new RuntimeException('Backup set is incompatible with this CMS major version.');
        if (! is_string($manifest['php_version'] ?? null) || $manifest['php_version'] === '') throw new RuntimeException('Backup-set PHP version is missing.');
        $setId = (string) ($manifest['backup_set_id'] ?? '');
        BackupSetId::normalize($setId);
        if (($manifest['status'] ?? null) !== 'complete') throw new RuntimeException('Backup set is incomplete.');
        if (! is_string($manifest['started_at'] ?? null) || strtotime($manifest['started_at']) === false
            || ! is_string($manifest['completed_at'] ?? null) || strtotime($manifest['completed_at']) === false) {
            throw new RuntimeException('Backup-set timestamps are invalid.');
        }
        $parent = basename(dirname($manifestPath));
        if (basename($manifestPath) !== 'manifest.json' || ($parent !== $setId && (! $allowStaging || $parent !== '.incomplete-' . $setId))) {
            throw new RuntimeException('Backup-set manifest location does not match its identifier.');
        }
        $components = $manifest['components'] ?? null;
        if (! is_array($components)) throw new RuntimeException('Backup-set components are missing.');
        $verified = [];
        foreach (['database', 'files'] as $type) {
            $component = $components[$type] ?? null;
            if (! is_array($component) || ! is_string($component['name'] ?? null)
                || basename($component['name']) !== $component['name'] || ! is_int($component['bytes'] ?? null)
                || ! is_string($component['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $component['sha256']) !== 1
                || ($component['backup_set_id'] ?? null) !== $setId) {
                throw new RuntimeException("Backup-set {$type} component metadata is invalid.");
            }
            $path = dirname($manifestPath) . DIRECTORY_SEPARATOR . $component['name'];
            $actual = $type === 'database' ? $this->verifyDatabase($path) : $this->verifyFileArchive($path);
            $artifactBytes = (int) ($actual['artifact_bytes'] ?? $actual['bytes']);
            if ($artifactBytes !== $component['bytes'] || ! hash_equals($actual['sha256'], $component['sha256'])) {
                throw new RuntimeException("Backup-set {$type} component size or checksum does not match.");
            }
            if (! hash_equals($actual['backup_set'], $setId)) throw new RuntimeException("Backup-set {$type} identifier does not match.");
            $actual['path'] = $path;
            $verified[$type] = $actual;
        }
        return ['manifest' => $manifest, 'database' => $verified['database'], 'files' => $verified['files'], 'path' => $manifestPath];
    }

    /** @return list<array{type:string,passed:bool,file:string,detail:string,metadata:array<string,mixed>}> */
    private function verifyManifestResults(string $manifestPath): array
    {
        try {
            $set = $this->verifyManifest($manifestPath);
            $database = ['type'=>'database','passed'=>true,'file'=>basename($set['database']['path']),'detail'=>"1 file(s), {$set['database']['bytes']} source byte(s), SHA-256 {$set['database']['sha256']}",'metadata'=>$set['database']];
            $files = ['type'=>'files','passed'=>true,'file'=>basename($set['files']['path']),'detail'=>"{$set['files']['files']} file(s), {$set['files']['bytes']} source byte(s), SHA-256 {$set['files']['sha256']}",'metadata'=>$set['files']];
            $pair = ['type'=>'pair','passed'=>true,'file'=>basename($manifestPath),'detail'=>'Verified complete backup set '.$set['manifest']['backup_set_id'].'.','metadata'=>['backup_set'=>$set['manifest']['backup_set_id'],'manifest'=>$manifestPath]];
            return [$database, $files, $pair];
        } catch (Throwable $error) {
            return [
                ['type'=>'database','passed'=>false,'file'=>'','detail'=>'Backup set verification failed before restore: '.$error->getMessage(),'metadata'=>[]],
                ['type'=>'files','passed'=>false,'file'=>'','detail'=>'Backup set verification failed before restore: '.$error->getMessage(),'metadata'=>[]],
                ['type'=>'pair','passed'=>false,'file'=>basename($manifestPath),'detail'=>$error->getMessage(),'metadata'=>['manifest'=>$manifestPath]],
            ];
        }
    }

    private function latestManifest(): ?string
    {
        if (! is_dir($this->directory) || is_link($this->directory)) return null;
        $paths = glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . 'set-*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [];
        usort($paths, static fn (string $a, string $b): int => strcmp($b, $a));
        foreach ($paths as $path) if (is_file($path) && ! is_link($path)) return $path;
        return null;
    }

    /** @return array{files:int,bytes:int,sha256:string,backup_set:string,snapshot_consistent:bool,snapshot:string} */
    public function verifyDatabase(string $path): array
    {
        $this->assertRegularFile($path);
        $size = filesize($path);
        if ($size === false || $size < 64) throw new RuntimeException('Database backup is empty or truncated.');
        $stream = fopen($path, 'rb');
        if ($stream === false) throw new RuntimeException('Database backup is not readable.');
        try {
            $header = fread($stream, 128);
            if (! is_string($header) || ! str_starts_with($header, "-- NovaNuke database backup\n-- Created: ")) {
                throw new RuntimeException('Database backup header is invalid.');
            }
            if (preg_match('/^-- NovaNuke database backup\n-- Created: ([^\r\n]+)\n/', $header, $matches) !== 1
                || strtotime($matches[1]) === false) {
                throw new RuntimeException('Database backup creation date is invalid.');
            }
            if (fseek($stream, max(0, $size - 128)) !== 0) throw new RuntimeException('Database backup cannot be inspected.');
            $tail = stream_get_contents($stream);
            if (! is_string($tail) || ! str_ends_with($tail, "SET FOREIGN_KEY_CHECKS=1;\n")) {
                throw new RuntimeException('Database backup is incomplete.');
            }
        } finally {
            fclose($stream);
        }
        $prefix = file_get_contents($path, false, null, 0, min(2048, $size));
        if (! is_string($prefix)) throw new RuntimeException('Database backup metadata cannot be read.');
        preg_match('/^-- Backup-Set: ([^\r\n]+)$/m', $prefix, $setMatch);
        preg_match('/^-- Snapshot: ([^\r\n]+)$/m', $prefix, $snapshotMatch);
        $backupSet = (string) ($setMatch[1] ?? '');
        $snapshot = (string) ($snapshotMatch[1] ?? 'legacy-unverified');
        if ($backupSet !== '' && preg_match('/^set-[0-9]{14}-[a-f0-9]{24}$/', $backupSet) !== 1) {
            throw new RuntimeException('Database backup set identifier is invalid.');
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) throw new RuntimeException('Database backup cannot be fingerprinted.');
        return [
            'files' => 1,
            'bytes' => $size,
            'sha256' => $hash,
            'backup_set' => $backupSet,
            'snapshot_consistent' => $snapshot === 'consistent-inno-db',
            'snapshot' => $snapshot,
        ];
    }

    /** @return array{files:int,bytes:int,artifact_bytes:int|false,sha256:string,backup_set:string} */
    public function verifyFileArchive(string $path): array
    {
        $this->assertRegularFile($path);
        $stream = fopen($path, 'rb');
        if ($stream === false) throw new RuntimeException('File backup is not readable.');
        $entries = [];
        $manifest = null;
        $terminated = false;
        try {
            while (($header = fread($stream, 512)) !== false && $header !== '') {
                if (strlen($header) !== 512) throw new RuntimeException('File backup has a truncated TAR header.');
                if ($header === str_repeat("\0", 512)) {
                    $second = fread($stream, 512);
                    if (! is_string($second) || $second !== str_repeat("\0", 512)) {
                        throw new RuntimeException('File backup has an invalid TAR terminator.');
                    }
                    if (fread($stream, 1) !== '') throw new RuntimeException('File backup contains data after its TAR terminator.');
                    $terminated = true;
                    break;
                }
                $this->assertTarChecksum($header);
                if (substr($header, 257, 6) !== "ustar\0" || substr($header, 263, 2) !== '00') {
                    throw new RuntimeException('File backup does not use the expected USTAR format.');
                }
                if (substr($header, 156, 1) !== '0') throw new RuntimeException('File backup contains a non-regular TAR entry.');
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $archivePath = $prefix === '' ? $name : $prefix . '/' . $name;
                $this->assertArchivePath($archivePath);
                if (isset($entries[$archivePath]) || ($archivePath === 'NOVANUKE-BACKUP.json' && $manifest !== null)) {
                    throw new RuntimeException("Duplicate backup entry: {$archivePath}");
                }
                $sizeField = trim(substr($header, 124, 12), "\0 ");
                if ($sizeField === '' || preg_match('/^[0-7]+$/', $sizeField) !== 1) {
                    throw new RuntimeException("Invalid TAR size for: {$archivePath}");
                }
                $size = octdec($sizeField);
                $hash = hash_init('sha256');
                $remaining = $size;
                $captured = '';
                if ($archivePath === 'NOVANUKE-BACKUP.json' && $size > 16 * 1024 * 1024) {
                    throw new RuntimeException('Backup manifest is unreasonably large.');
                }
                while ($remaining > 0) {
                    $chunk = fread($stream, min(1048576, $remaining));
                    if (! is_string($chunk) || $chunk === '') throw new RuntimeException("Truncated backup entry: {$archivePath}");
                    hash_update($hash, $chunk);
                    if ($archivePath === 'NOVANUKE-BACKUP.json') $captured .= $chunk;
                    $remaining -= strlen($chunk);
                }
                $padding = (512 - ($size % 512)) % 512;
                if ($padding > 0) {
                    $paddingBytes = fread($stream, $padding);
                    if (! is_string($paddingBytes) || $paddingBytes !== str_repeat("\0", $padding)) {
                        throw new RuntimeException("Invalid TAR padding for: {$archivePath}");
                    }
                }
                if ($archivePath === 'NOVANUKE-BACKUP.json') $manifest = $captured;
                else $entries[$archivePath] = ['bytes' => $size, 'sha256' => hash_final($hash)];
            }
        } finally {
            fclose($stream);
        }
        if (! $terminated) throw new RuntimeException('File backup has no complete TAR terminator.');
        if ($manifest === null) throw new RuntimeException('File backup manifest is missing.');
        $record = json_decode($manifest, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($record) || ! in_array(($record['format'] ?? null), [1, 2], true) || ! is_array($record['files'] ?? null)
            || ! is_string($record['created_at'] ?? null) || strtotime($record['created_at']) === false) {
            throw new RuntimeException('File backup manifest is invalid.');
        }
        $backupSet = (string) ($record['backup_set'] ?? '');
        if ($backupSet !== '' && preg_match('/^set-[0-9]{14}-[a-f0-9]{24}$/', $backupSet) !== 1) {
            throw new RuntimeException('File backup set identifier is invalid.');
        }
        $expected = [];
        foreach ($record['files'] as $item) {
            if (! is_array($item) || ! is_string($item['path'] ?? null) || ! is_int($item['bytes'] ?? null)
                || $item['bytes'] < 0 || ! is_string($item['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $item['sha256']) !== 1) {
                throw new RuntimeException('File backup manifest contains an invalid entry.');
            }
            $this->assertArchivePath($item['path']);
            if (isset($expected[$item['path']])) throw new RuntimeException("Duplicate manifest entry: {$item['path']}");
            $expected[$item['path']] = ['bytes' => $item['bytes'], 'sha256' => $item['sha256']];
        }
        ksort($entries, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($entries !== $expected) throw new RuntimeException('File backup contents do not match its manifest.');
        $hash = hash_file('sha256', $path);
        if ($hash === false) throw new RuntimeException('File backup cannot be fingerprinted.');
        return [
            'files' => count($entries),
            'bytes' => array_sum(array_column($entries, 'bytes')),
            'artifact_bytes' => filesize($path),
            'sha256' => $hash,
            'backup_set' => $backupSet,
        ];
    }

    /** @param callable(string):array<string,mixed> $verifier
     *  @return array{type:string,passed:bool,file:string,detail:string,metadata:array<string,mixed>}
     */
    private function verifyCandidate(string $type, ?string $path, callable $verifier): array
    {
        if ($path === null) return ['type' => $type, 'passed' => false, 'file' => '', 'detail' => 'No backup found.', 'metadata' => []];
        try {
            $result = $verifier($path);
            $detail = "{$result['files']} file(s), {$result['bytes']} source byte(s), SHA-256 {$result['sha256']}";
            if ($type === 'database') {
                $detail .= '; snapshot ' . (($result['snapshot_consistent'] ?? false) ? 'consistent' : 'NOT VERIFIED (' . ($result['snapshot'] ?? 'unknown') . ')');
            }
            return [
                'type' => $type,
                'passed' => true,
                'file' => basename($path),
                'detail' => $detail,
                'metadata' => $result,
            ];
        } catch (Throwable $error) {
            return ['type' => $type, 'passed' => false, 'file' => basename($path), 'detail' => $error->getMessage(), 'metadata' => []];
        }
    }

    /** @param array{type:string,passed:bool,file:string,detail:string,metadata:array<string,mixed>} $database
     *  @param array{type:string,passed:bool,file:string,detail:string,metadata:array<string,mixed>} $files
     *  @return array{type:string,passed:bool,file:string,detail:string,metadata:array<string,mixed>}
     */
    private function verifyPair(array $database, array $files): array
    {
        $file = $database['file'] !== '' && $files['file'] !== '' ? $database['file'] . ' + ' . $files['file'] : '';
        if (! $database['passed'] || ! $files['passed']) {
            return ['type' => 'pair', 'passed' => false, 'file' => $file, 'detail' => 'Both backups must pass individual integrity verification.', 'metadata' => []];
        }
        $databaseSet = (string) ($database['metadata']['backup_set'] ?? '');
        $fileSet = (string) ($files['metadata']['backup_set'] ?? '');
        if ($databaseSet === '' || $fileSet === '') {
            return ['type' => 'pair', 'passed' => false, 'file' => $file, 'detail' => 'Backup set ID is missing; legacy timestamp pairing is not accepted for release recovery.', 'metadata' => []];
        }
        if (! hash_equals($databaseSet, $fileSet)) {
            return ['type' => 'pair', 'passed' => false, 'file' => $file, 'detail' => "Backup set IDs do not match ({$databaseSet} vs {$fileSet}).", 'metadata' => []];
        }
        return [
            'type' => 'pair',
            'passed' => true,
            'file' => $file,
            'detail' => "Matched backup set {$databaseSet}.",
            'metadata' => ['backup_set' => $databaseSet],
        ];
    }

    private function latest(string $pattern): ?string
    {
        if (! is_dir($this->directory) || is_link($this->directory)) return null;
        $latest = null;
        $latestTime = -1;
        foreach (glob(rtrim($this->directory, '/') . '/' . $pattern) ?: [] as $path) {
            if (! is_file($path) || is_link($path)) continue;
            $modified = filemtime($path);
            if ($modified !== false && $modified > $latestTime) {
                $latest = $path;
                $latestTime = $modified;
            }
        }
        return $latest;
    }

    private function assertRegularFile(string $path): void
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new RuntimeException('Backup is not a regular readable file.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $permissions = fileperms($path);
            if ($permissions !== false && (($permissions & 0077) !== 0)) {
                throw new RuntimeException('Backup file permissions are too permissive; expected owner-only access.');
            }
        }
    }

    private function assertTarChecksum(string $header): void
    {
        $field = trim(substr($header, 148, 8), "\0 ");
        if ($field === '' || preg_match('/^[0-7]+$/', $field) !== 1) throw new RuntimeException('TAR checksum is invalid.');
        $unsigned = substr_replace($header, str_repeat(' ', 8), 148, 8);
        if (array_sum(unpack('C*', $unsigned)) !== octdec($field)) throw new RuntimeException('TAR checksum does not match.');
    }

    private function assertArchivePath(string $path): void
    {
        if ($path === '' || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || str_starts_with($path, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new RuntimeException('Backup archive path is unsafe.');
        }
    }
}
