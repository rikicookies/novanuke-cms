<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Storage\SafeStorageBoundary;
use RuntimeException;

final class AvatarStorage
{
    public function __construct(private readonly string $directory)
    {
    }

    public function store(ValidatedAvatar $avatar): string
    {
        try {
            SafeStorageBoundary::ensureDirectory($this->directory, 0750);
        } catch (RuntimeException) {
            throw new RuntimeException('Avatar storage is unavailable.');
        }
        if (! is_uploaded_file($avatar->temporaryPath)) throw new RuntimeException('Avatar storage is unavailable.');
        $filename = bin2hex(random_bytes(20)) . '.' . $avatar->extension;
        if (! move_uploaded_file($avatar->temporaryPath, $this->directory . '/' . $filename)) throw new RuntimeException('Avatar could not be stored.');
        return '/avatars/' . $filename;
    }

    /** @return array{path:string,mime:string,size:int} */
    public function resolve(string $filename): array
    {
        if (! preg_match('/^[a-f0-9]{40}\.(?:jpg|png|webp)$/', $filename)) throw new RuntimeException('Avatar not found.');
        try { $path = SafeStorageBoundary::existingFile($this->directory, $filename); }
        catch (RuntimeException) { throw new RuntimeException('Avatar not found.'); }
        $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        return ['path' => $path, 'mime' => $mimes[pathinfo($filename, PATHINFO_EXTENSION)], 'size' => (int) filesize($path)];
    }

    public function remove(?string $publicPath): void
    {
        if ($publicPath === null || ! preg_match('#^/avatars/([a-f0-9]{40}\.(?:jpg|png|webp))$#', $publicPath, $match)) return;
        try { $avatar = $this->resolve($match[1]); } catch (RuntimeException) { return; }
        if (! unlink($avatar['path'])) throw new RuntimeException('Previous avatar could not be removed.');
    }
}
