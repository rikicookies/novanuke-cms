<?php

declare(strict_types=1);

namespace NovaNuke\Core\Logging;

use PDO;

final class ActivityLogger
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @param array<string, scalar|null> $context */
    public function log(
        ?int $actorUserId,
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        array $context = [],
        ?string $ipAddress = null,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO activity_logs '
            . '(actor_user_id, action, subject_type, subject_id, context, ip_address, created_at) '
            . 'VALUES (:actor_user_id, :action, :subject_type, :subject_id, :context, :ip_address, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
            'ip_address' => $ipAddress,
        ]);
    }
}
