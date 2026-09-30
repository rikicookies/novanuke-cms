<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Security\SessionManager;
use PDO;

final class AuthManager
{
    private const USER_KEY = '_auth_user_id';
    private const VERSION_KEY = '_auth_version';

    public function __construct(
        private readonly PDO $database,
        private readonly SessionManager $session,
        private readonly EventDispatcher $events,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function attempt(string $login, string $password, string $ip, string $userAgent): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, username, email, password_hash, must_change_password, auth_version, status FROM users '
            . 'WHERE deleted_at IS NULL AND (email = :email OR username = :username) LIMIT 1'
        );
        $statement->execute(['email' => strtolower($login), 'username' => $login]);
        $user = $statement->fetch();

        if (! is_array($user) || ! password_verify($password, (string) $user['password_hash'])) {
            password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return null;
        }

        if ($user['status'] !== 'active') {
            return null;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->database->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }

        $this->session->regenerate();
        $this->session->put(self::USER_KEY, (int) $user['id']);
        $this->session->put(self::VERSION_KEY, (int) $user['auth_version']);
        $this->recordLogin((int) $user['id'], $ip, $userAgent);
        $this->dispatchSafely(\NovaNuke\Core\Events\EventName::USER_LOGGED_IN, new UserLoggedIn((int) $user['id']));
        unset($user['password_hash']);

        return $user;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        $id = $this->session->get(self::USER_KEY);
        if (! is_int($id) && ! ctype_digit((string) $id)) {
            return null;
        }

        $statement = $this->database->prepare(
            'SELECT id, username, email, status, must_change_password, auth_version FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['id' => (int) $id]);
        $user = $statement->fetch();

        $sessionVersion = (int) $this->session->get(self::VERSION_KEY, 0);
        if (! is_array($user) || $user['status'] !== 'active' || (int) $user['auth_version'] !== $sessionVersion) {
            $this->logout();
            return null;
        }

        return $user;
    }

    public function logout(): void
    {
        $this->session->remove(self::USER_KEY);
        $this->session->remove(self::VERSION_KEY);
        $this->session->invalidate();
    }

    public function isSuperAdministrator(int $userId): bool
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :user_id AND r.slug = :slug'
        );
        $statement->execute(['user_id' => $userId, 'slug' => 'super-administrator']);

        return (int) $statement->fetchColumn() > 0;
    }

    private function recordLogin(int $userId, string $ip, string $userAgent): void
    {
        $this->database->beginTransaction();
        try {
            $update = $this->database->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
            $update->execute(['id' => $userId]);
            $history = $this->database->prepare(
                'INSERT INTO user_login_history (user_id, ip_address, user_agent, logged_in_at) '
                . 'VALUES (:user_id, :ip_address, :user_agent, UTC_TIMESTAMP())'
            );
            $history->execute(['user_id' => $userId, 'ip_address' => $ip, 'user_agent' => $userAgent]);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function dispatchSafely(string $name, object $event): void
    {
        try {
            $this->events->dispatch($name, $event);
        } catch (\Throwable $error) {
            error_log("A {$name} listener failed: " . $error->getMessage());
        }
    }
}
