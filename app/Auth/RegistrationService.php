<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Mail\Mailer;
use NovaNuke\Core\Settings\SettingsRepository;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class RegistrationService
{
    private const VERIFICATION_MINUTES = 1440;

    public function __construct(
        private readonly PDO $database,
        private readonly SettingsRepository $settings,
        private readonly Mailer $mailer,
        private readonly EventDispatcher $events,
        private readonly string $applicationUrl,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->settings->boolean('users.registration_open', false);
    }

    public function verificationRequired(): bool
    {
        return $this->settings->boolean('users.email_verification_required', true);
    }

    public function register(string $username, string $email, string $password, string $locale, string $timezone): bool
    {
        if (! $this->isOpen()) {
            throw new RuntimeException('Public registration is closed.');
        }

        $verificationRequired = $this->verificationRequired();
        $token = $verificationRequired ? ResetToken::generate() : null;
        $this->database->beginTransaction();
        try {
            $user = $this->database->prepare(
                'INSERT INTO users (username, email, password_hash, auth_version, status, email_verified_at, created_at, updated_at) '
                . 'VALUES (:username, :email, :password_hash, 1, :status, '
                . ($verificationRequired ? 'NULL' : 'UTC_TIMESTAMP()')
                . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $user->execute([
                'username' => $username,
                'email' => strtolower($email),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => $verificationRequired ? 'pending_verification' : 'active',
            ]);
            $userId = (int) $this->database->lastInsertId();

            $profile = $this->database->prepare(
                'INSERT INTO user_profiles (user_id, display_name, locale, timezone, created_at, updated_at) '
                . 'VALUES (:user_id, :display_name, :locale, :timezone, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $profile->execute([
                'user_id' => $userId,
                'display_name' => $username,
                'locale' => $locale,
                'timezone' => $timezone,
            ]);

            $role = $this->database->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
            $role->execute(['slug' => 'member']);
            $roleId = $role->fetchColumn();
            if ($roleId === false) {
                throw new RuntimeException('The Member role is unavailable.');
            }
            $assignment = $this->database->prepare(
                'INSERT INTO user_roles (user_id, role_id, created_at) VALUES (:user_id, :role_id, UTC_TIMESTAMP())'
            );
            $assignment->execute(['user_id' => $userId, 'role_id' => $roleId]);

            if ($token !== null) {
                $verification = $this->database->prepare(
                    'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at) '
                    . 'VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1440 MINUTE), UTC_TIMESTAMP())'
                );
                $verification->execute(['user_id' => $userId, 'token_hash' => ResetToken::hash($token)]);
                $url = rtrim($this->applicationUrl, '/') . '/verify-email/' . rawurlencode($token);
                $this->mailer->sendEmailVerification(strtolower($email), $url, self::VERIFICATION_MINUTES);
            }
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if ($error instanceof PDOException && in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new RuntimeException('That username or email is already registered.');
            }
            throw $error;
        }

        $this->dispatchSafely(\NovaNuke\Core\Events\EventName::USER_REGISTERED, new UserRegistered($userId, $verificationRequired));
        return $verificationRequired;
    }

    public function verify(string $token): bool
    {
        if (! ResetToken::isWellFormed($token)) {
            return false;
        }

        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'SELECT id, user_id FROM email_verification_tokens WHERE token_hash = :token_hash '
                . 'AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE'
            );
            $statement->execute(['token_hash' => ResetToken::hash($token)]);
            $record = $statement->fetch();
            if (! is_array($record)) {
                $this->database->rollBack();
                return false;
            }

            $user = $this->database->prepare(
                'UPDATE users SET status = :status, email_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() '
                . 'WHERE id = :id AND status = :pending'
            );
            $user->execute(['status' => 'active', 'id' => $record['user_id'], 'pending' => 'pending_verification']);
            $used = $this->database->prepare(
                'UPDATE email_verification_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $used->execute(['id' => $record['id']]);
            $this->database->commit();

            $verified = $user->rowCount() === 1;
            if ($verified) $this->dispatchSafely(\NovaNuke\Core\Events\EventName::USER_EMAIL_VERIFIED, new UserEmailVerified((int) $record['user_id']));
            return $verified;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function resendVerification(string $email): void
    {
        $normalized = strtolower(trim($email));
        $statement = $this->database->prepare(
            "SELECT id FROM users WHERE email=:email AND status='pending_verification' AND deleted_at IS NULL LIMIT 1"
        );
        $statement->execute(['email' => $normalized]);
        $userId = $statement->fetchColumn();
        if ($userId === false) return;

        $token = ResetToken::generate();
        $this->database->beginTransaction();
        try {
            $this->database->prepare('DELETE FROM email_verification_tokens WHERE user_id=:id')->execute(['id' => $userId]);
            $insert = $this->database->prepare(
                'INSERT INTO email_verification_tokens (user_id,token_hash,expires_at,created_at) '
                . 'VALUES (:id,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1440 MINUTE),UTC_TIMESTAMP())'
            );
            $insert->execute(['id' => $userId, 'hash' => ResetToken::hash($token)]);
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }

        $url = rtrim($this->applicationUrl, '/') . '/verify-email/' . rawurlencode($token);
        $this->mailer->sendEmailVerification($normalized, $url, self::VERIFICATION_MINUTES);
    }

    private function dispatchSafely(string $name, object $event): void
    {
        try {
            $this->events->dispatch($name, $event);
        } catch (Throwable $error) {
            error_log("A {$name} listener failed: " . $error->getMessage());
        }
    }
}
