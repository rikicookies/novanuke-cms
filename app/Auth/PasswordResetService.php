<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Mail\Mailer;
use PDO;
use RuntimeException;
use Throwable;

final class PasswordResetService
{
    public const EXPIRATION_MINUTES = 60;

    public function __construct(
        private readonly PDO $database,
        private readonly Mailer $mailer,
        private readonly string $applicationUrl,
    ) {
    }

    public function request(string $email, string $requestIp): void
    {
        $statement = $this->database->prepare(
            'SELECT id, email FROM users WHERE email = :email AND status = :status AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['email' => strtolower($email), 'status' => 'active']);
        $user = $statement->fetch();

        if (! is_array($user)) {
            return;
        }

        $token = ResetToken::generate();
        $this->database->beginTransaction();
        try {
            $delete = $this->database->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
            $delete->execute(['user_id' => $user['id']]);
            $insert = $this->database->prepare(
                'INSERT INTO password_reset_tokens '
                . '(user_id, token_hash, request_ip, expires_at, created_at) '
                . 'VALUES (:user_id, :token_hash, :request_ip, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 MINUTE), UTC_TIMESTAMP())'
            );
            $insert->execute([
                'user_id' => $user['id'],
                'token_hash' => ResetToken::hash($token),
                'request_ip' => $requestIp,
            ]);
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }

        $url = rtrim($this->applicationUrl, '/') . '/reset-password/' . rawurlencode($token)
            . '?email=' . rawurlencode((string) $user['email']);
        $this->mailer->sendPasswordReset((string) $user['email'], $url, self::EXPIRATION_MINUTES);
    }

    public function reset(string $email, string $token, string $newPassword): void
    {
        if (! ResetToken::isWellFormed($token)) {
            throw new RuntimeException('This password reset link is invalid or has expired.');
        }

        $statement = $this->database->prepare(
            'SELECT prt.id AS token_id, prt.user_id FROM password_reset_tokens prt '
            . 'INNER JOIN users u ON u.id = prt.user_id '
            . 'WHERE u.email = :email AND prt.token_hash = :token_hash '
            . 'AND prt.used_at IS NULL AND prt.expires_at > UTC_TIMESTAMP() '
            . 'AND u.status = :status AND u.deleted_at IS NULL LIMIT 1'
        );
        $statement->execute([
            'email' => strtolower($email),
            'token_hash' => ResetToken::hash($token),
            'status' => 'active',
        ]);
        $record = $statement->fetch();

        if (! is_array($record)) {
            throw new RuntimeException('This password reset link is invalid or has expired.');
        }

        $this->database->beginTransaction();
        try {
            $update = $this->database->prepare(
                'UPDATE users SET password_hash = :password_hash, must_change_password = 0, auth_version = auth_version + 1, '
                . 'updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $update->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $record['user_id'],
            ]);
            $used = $this->database->prepare(
                'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL'
            );
            $used->execute(['id' => $record['token_id']]);
            if ($used->rowCount() !== 1) {
                throw new RuntimeException('This password reset link has already been used.');
            }
            $delete = $this->database->prepare(
                'DELETE FROM password_reset_tokens WHERE user_id = :user_id AND id <> :id'
            );
            $delete->execute(['user_id' => $record['user_id'], 'id' => $record['token_id']]);
            $pendingEmail = $this->database->prepare('DELETE FROM email_change_tokens WHERE user_id = :user_id');
            $pendingEmail->execute(['user_id' => $record['user_id']]);
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function isValid(string $email, string $token): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! ResetToken::isWellFormed($token)) {
            return false;
        }

        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM password_reset_tokens prt '
            . 'INNER JOIN users u ON u.id = prt.user_id '
            . 'WHERE u.email = :email AND prt.token_hash = :token_hash '
            . 'AND prt.used_at IS NULL AND prt.expires_at > UTC_TIMESTAMP() '
            . 'AND u.status = :status AND u.deleted_at IS NULL'
        );
        $statement->execute([
            'email' => strtolower($email),
            'token_hash' => ResetToken::hash($token),
            'status' => 'active',
        ]);

        return (int) $statement->fetchColumn() === 1;
    }
}
