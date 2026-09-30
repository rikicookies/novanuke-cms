<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use PDO;
use RuntimeException;

final class AccountPasswordService
{
    public function __construct(private readonly PDO $database, private readonly PasswordPolicy $policy)
    {
    }

    public function change(int $userId, mixed $currentPassword, mixed $password, mixed $confirmation): ?string
    {
        if (! is_string($currentPassword) || $currentPassword === '' || strlen($currentPassword) > 255) return 'Enter your current password.';
        $error = $this->policy->validate($password, $confirmation);
        if ($error !== null) return $error;

        $statement = $this->database->prepare('SELECT password_hash FROM users WHERE id=:id AND status=:status AND deleted_at IS NULL LIMIT 1');
        $statement->execute(['id' => $userId, 'status' => 'active']);
        $hash = $statement->fetchColumn();
        if (! is_string($hash) || ! password_verify($currentPassword, $hash)) return 'The current password is incorrect.';
        if (password_verify((string) $password, $hash)) return 'Choose a password different from the current password.';

        $this->database->beginTransaction();
        try {
            $update = $this->database->prepare('UPDATE users SET password_hash=:hash,must_change_password=0,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id');
            $update->execute(['hash' => password_hash((string) $password, PASSWORD_DEFAULT), 'id' => $userId]);
            $this->database->prepare('DELETE FROM password_reset_tokens WHERE user_id=:id')->execute(['id' => $userId]);
            $this->database->prepare('DELETE FROM email_change_tokens WHERE user_id=:id')->execute(['id' => $userId]);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
        if ($update->rowCount() !== 1) throw new RuntimeException('Password could not be changed.');
        return null;
    }
}
