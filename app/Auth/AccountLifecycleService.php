<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Events\EventDispatcher;
use PDO;
use RuntimeException;

final class AccountLifecycleService
{
    public function __construct(private readonly PDO $database, private readonly EventDispatcher $events)
    {
    }

    public function anonymize(int $userId, string $password): ?string
    {
        $statement = $this->database->prepare(
            'SELECT password_hash FROM users WHERE id=:id AND status=:status AND deleted_at IS NULL LIMIT 1 FOR UPDATE'
        );

        $this->database->beginTransaction();
        try {
            $statement->execute(['id' => $userId, 'status' => 'active']);
            $hash = $statement->fetchColumn();
            if (! is_string($hash) || ! password_verify($password, $hash)) {
                $this->database->rollBack();
                return 'The current password is incorrect.';
            }
            if ($this->isLastActiveSuperAdministrator($userId)) {
                $this->database->rollBack();
                return 'The last active Super Administrator cannot delete their account.';
            }

            $token = bin2hex(random_bytes(8));
            $username = 'former-user-' . $userId;
            $email = 'deleted+' . $userId . '+' . $token . '@invalid.local';
            $update = $this->database->prepare(
                "UPDATE users SET username=:username,email=:email,password_hash=:password,status='deleted',"
                . 'email_verified_at=NULL,last_login_at=NULL,suspended_at=NULL,auth_version=auth_version+1,'
                . 'deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL'
            );
            $update->execute([
                'username' => $username, 'email' => $email,
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'id' => $userId,
            ]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Account could not be anonymized.');

            $profile = $this->database->prepare(
                "UPDATE user_profiles SET display_name='Former user',avatar_path=NULL,bio=NULL,bio_format='markdown',website=NULL,location=NULL,locale='en',timezone='UTC',"
                . "preferences=JSON_OBJECT('profile_visibility','members'),updated_at=UTC_TIMESTAMP() WHERE user_id=:id"
            );
            $profile->execute(['id' => $userId]);
            foreach (['password_reset_tokens', 'email_verification_tokens', 'email_change_tokens', 'user_login_history', 'user_roles'] as $table) {
                $delete = $this->database->prepare("DELETE FROM `{$table}` WHERE user_id=:id");
                $delete->execute(['id' => $userId]);
            }
            $logs = $this->database->prepare('UPDATE activity_logs SET actor_user_id=NULL,ip_address=NULL WHERE actor_user_id=:id');
            $logs->execute(['id' => $userId]);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }

        try {
            $this->events->dispatch(\NovaNuke\Core\Events\EventName::USER_ANONYMIZED, new UserAnonymized($userId));
        } catch (\Throwable $error) {
            error_log('A user.anonymized listener failed: ' . $error->getMessage());
        }
        return null;
    }

    private function isLastActiveSuperAdministrator(int $userId): bool
    {
        $assigned = $this->database->prepare(
            "SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:id AND r.slug='super-administrator'"
        );
        $assigned->execute(['id' => $userId]);
        if ((int) $assigned->fetchColumn() === 0) return false;
        $count = $this->database->query(
            "SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id "
            . "WHERE r.slug='super-administrator' AND u.status='active' AND u.deleted_at IS NULL"
        );
        return (int) $count->fetchColumn() <= 1;
    }
}
