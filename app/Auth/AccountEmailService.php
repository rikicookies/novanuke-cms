<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Mail\Mailer;
use PDO;
use PDOException;

final class AccountEmailService
{
    private const EXPIRES_MINUTES = 60;

    public function __construct(
        private readonly PDO $database,
        private readonly Mailer $mailer,
        private readonly EventDispatcher $events,
        private readonly string $applicationUrl,
    ) {
    }

    public function request(int $userId, string $email, string $password): ?string
    {
        $user = $this->database->prepare(
            "SELECT email,password_hash FROM users WHERE id=:id AND status='active' AND deleted_at IS NULL LIMIT 1"
        );
        $user->execute(['id' => $userId]);
        $record = $user->fetch();
        if (! is_array($record) || ! password_verify($password, (string) $record['password_hash'])) {
            return 'The current password is incorrect.';
        }
        if (hash_equals(strtolower((string) $record['email']), $email)) return 'Enter an email different from your current address.';
        $existing = $this->database->prepare('SELECT COUNT(*) FROM users WHERE email=:email AND id<>:id AND deleted_at IS NULL');
        $existing->execute(['email' => $email, 'id' => $userId]);
        if ((int) $existing->fetchColumn() > 0) return 'That email address is already registered.';

        $token = ResetToken::generate();
        $this->database->beginTransaction();
        try {
            $this->database->prepare('DELETE FROM email_change_tokens WHERE user_id=:id')->execute(['id' => $userId]);
            $insert = $this->database->prepare(
                'INSERT INTO email_change_tokens (user_id,pending_email,token_hash,expires_at,created_at) '
                . 'VALUES (:user_id,:email,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE),UTC_TIMESTAMP())'
            );
            $insert->execute(['user_id' => $userId, 'email' => $email, 'hash' => ResetToken::hash($token)]);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }

        $url = rtrim($this->applicationUrl, '/') . '/account/email/verify/' . rawurlencode($token);
        $this->mailer->sendEmailChangeVerification($email, $url, self::EXPIRES_MINUTES);
        return null;
    }

    public function confirm(string $token): ?int
    {
        if (! ResetToken::isWellFormed($token)) return null;
        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'SELECT id,user_id,pending_email FROM email_change_tokens WHERE token_hash=:hash '
                . 'AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['hash' => ResetToken::hash($token)]);
            $change = $statement->fetch();
            if (! is_array($change)) { $this->database->rollBack(); return null; }
            $update = $this->database->prepare(
                "UPDATE users SET email=:email,email_verified_at=UTC_TIMESTAMP(),auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() "
                . "WHERE id=:id AND status='active' AND deleted_at IS NULL"
            );
            try {
                $update->execute(['email' => $change['pending_email'], 'id' => $change['user_id']]);
            } catch (PDOException $error) {
                if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    $this->database->rollBack();
                    return null;
                }
                throw $error;
            }
            if ($update->rowCount() !== 1) { $this->database->rollBack(); return null; }
            $used = $this->database->prepare('UPDATE email_change_tokens SET used_at=UTC_TIMESTAMP() WHERE id=:id');
            $used->execute(['id' => $change['id']]);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
        try { $this->events->dispatch(\NovaNuke\Core\Events\EventName::USER_EMAIL_CHANGED, new UserEmailChanged((int) $change['user_id'])); }
        catch (\Throwable $error) { error_log('A user.email_changed listener failed: ' . $error->getMessage()); }
        return (int) $change['user_id'];
    }
}
