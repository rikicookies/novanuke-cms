<?php

declare(strict_types=1);

namespace NovaNuke\Core\Security;

use InvalidArgumentException;
use RuntimeException;

final class SessionManager
{
    private const STARTED_AT = '_started_at';
    private const LAST_ACTIVITY_AT = '_last_activity_at';
    private const LAST_REGENERATED_AT = '_last_regenerated_at';

    public function __construct(
        private readonly string $name,
        private readonly bool $secure,
        private readonly string $sameSite = 'Lax',
        private readonly int $lifetime = 7200,
        private readonly int $idleTimeout = 1800,
        private readonly int $rotationInterval = 900,
        private readonly string $cookiePath = '/',
        private readonly string $cookieDomain = '',
    ) {
        if (! in_array($this->sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException('SESSION_SAME_SITE must be Lax, Strict or None.');
        }
        if ($this->sameSite === 'None' && ! $this->secure) {
            throw new InvalidArgumentException('SESSION_SAME_SITE=None requires SESSION_SECURE=true.');
        }
        if ($this->lifetime < 300 || $this->idleTimeout < 60 || $this->rotationInterval < 60) {
            throw new InvalidArgumentException('Session lifetime, idle timeout and rotation interval are below safe minimums.');
        }
        if ($this->cookiePath === '' || ! str_starts_with($this->cookiePath, '/')
            || preg_match('/[\r\n;]/', $this->cookiePath) === 1) {
            throw new InvalidArgumentException('SESSION_PATH must be an absolute cookie path without control or separator characters.');
        }
        if (preg_match('/[\r\n;\/]/', $this->cookieDomain) === 1) {
            throw new InvalidArgumentException('SESSION_DOMAIN contains unsafe cookie characters.');
        }
        if (str_starts_with($this->name, '__Host-')
            && (! $this->secure || $this->cookiePath !== '/' || $this->cookieDomain !== '')) {
            throw new InvalidArgumentException('__Host- session cookies require Secure, path=/ and no Domain.');
        }
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException('Cannot start a session after headers have been sent.');
        }

        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'domain' => $this->cookieDomain,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        if (! session_start()) {
            throw new RuntimeException('The session could not be started.');
        }

        $now = time();
        $startedAt = (int) ($_SESSION[self::STARTED_AT] ?? 0);
        $lastActivityAt = (int) ($_SESSION[self::LAST_ACTIVITY_AT] ?? 0);
        $lastRegeneratedAt = (int) ($_SESSION[self::LAST_REGENERATED_AT] ?? 0);

        if ($startedAt <= 0) {
            $_SESSION[self::STARTED_AT] = $now;
            $_SESSION[self::LAST_ACTIVITY_AT] = $now;
            $_SESSION[self::LAST_REGENERATED_AT] = $now;
            return;
        }

        $expired = ($now - $startedAt) > $this->lifetime
            || ($lastActivityAt > 0 && ($now - $lastActivityAt) > $this->idleTimeout);

        if ($expired) {
            $this->renew($now, true);
            return;
        }

        if ($lastRegeneratedAt <= 0 || ($now - $lastRegeneratedAt) > $this->rotationInterval) {
            session_regenerate_id(true);
            $_SESSION[self::LAST_REGENERATED_AT] = $now;
        }

        $_SESSION[self::LAST_ACTIVITY_AT] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    public function regenerate(): void
    {
        $now = time();
        session_regenerate_id(true);
        $_SESSION[self::STARTED_AT] = $now;
        $_SESSION[self::LAST_ACTIVITY_AT] = $now;
        $_SESSION[self::LAST_REGENERATED_AT] = $now;
    }

    public function invalidate(): void
    {
        $this->renew(time(), true);
    }

    private function renew(int $now, bool $destroyOld): void
    {
        $_SESSION = [];
        session_regenerate_id($destroyOld);
        $_SESSION[self::STARTED_AT] = $now;
        $_SESSION[self::LAST_ACTIVITY_AT] = $now;
        $_SESSION[self::LAST_REGENERATED_AT] = $now;
    }
}
