<?php

declare(strict_types=1);

namespace NovaNuke\Core\Menus;

use RuntimeException;

final class MenuUrlResolver
{
    public function resolve(string $type, string $target): string
    {
        $target = trim($target);
        if ($target === '' || strlen($target) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $target)) {
            throw new RuntimeException('Enter a valid menu destination.');
        }

        return match ($type) {
            'internal' => $this->internal($target),
            'external' => $this->external($target),
            'module' => $this->module($target),
            default => throw new RuntimeException('Unsupported menu link type.'),
        };
    }

    private function internal(string $target): string
    {
        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            throw new RuntimeException('Internal links must begin with one slash.');
        }
        return $target;
    }

    private function external(string $target): string
    {
        if (! filter_var($target, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter a valid external URL.');
        }
        $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('External menu links support HTTP and HTTPS only.');
        }
        return $target;
    }

    private function module(string $target): string
    {
        $slug = strtolower($target);
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $slug)) {
            throw new RuntimeException('Enter a valid module slug.');
        }
        return '/' . $slug;
    }
}
