<?php

declare(strict_types=1);

namespace NovaNuke\Core\Http;

use Closure;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string|Closure $content = '',
        private readonly int $status = 200,
        private readonly array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /** @param array<string,string> $headers */
    public static function xml(string $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, array_replace([
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ], $headers));
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if ($location === ''
            || ! str_starts_with($location, '/')
            || str_starts_with($location, '//')
            || str_contains($location, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $location) === 1) {
            throw new \InvalidArgumentException('Redirects must use a safe local absolute path.');
        }

        return new self('', $status, ['Location' => $location]);
    }

    public static function externalRedirect(string $location, int $status = 302): self
    {
        $scheme = strtolower((string) parse_url($location, PHP_URL_SCHEME));
        $host = parse_url($location, PHP_URL_HOST);
        $port = parse_url($location, PHP_URL_PORT);
        $expectedPort = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
        if (! filter_var($location, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host) || $host === ''
            || parse_url($location, PHP_URL_USER) !== null || parse_url($location, PHP_URL_PASS) !== null
            || ($port !== null && $port !== $expectedPort)
            || preg_match('/[\x00-\x1F\x7F]/', $location)) {
            throw new \InvalidArgumentException('External redirects require a safe HTTP or HTTPS URL.');
        }
        return new self('', $status, ['Location' => $location, 'Referrer-Policy' => 'no-referrer']);
    }

    public static function download(string $path, string $filename, string $mimeType): self
    {
        if (! is_file($path) || ! is_readable($path)) throw new \RuntimeException('Download file is unavailable.');
        if (! preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~i', $mimeType)) $mimeType = 'application/octet-stream';
        $safeName = preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename($filename)) ?: 'download';
        $disposition = "attachment; filename=\"{$safeName}\"; filename*=UTF-8''" . rawurlencode($safeName);
        return new self(static function () use ($path): void { readfile($path); }, 200, [
            'Content-Type' => $mimeType, 'Content-Length' => (string) filesize($path),
            'Content-Disposition' => $disposition, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return is_string($this->content) ? $this->content : '';
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) return $value;
        }
        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        if (! preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name)
            || preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException('Invalid response header.');
        }

        $headers = [];
        foreach ($this->headers as $header => $existing) {
            if (strcasecmp($header, $name) !== 0) {
                $headers[$header] = $existing;
            }
        }
        $headers[$name] = $value;

        return new self($this->content, $this->status, $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true);
        }

        if ($this->content instanceof Closure) ($this->content)(); else echo $this->content;
    }
}
