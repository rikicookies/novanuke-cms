<?php

declare(strict_types=1);

namespace NovaNuke\Core\Security;

final class UserRoleSafety
{
    public function violation(
        int $actorId,
        int $targetId,
        bool $actorIsSuper,
        bool $targetIsSuper,
        bool $targetWillBeActive,
        bool $targetWillBeSuper,
        int $activeSuperAdministrators,
    ): ?string {
        if ($actorId === $targetId) {
            return 'You cannot change your own status or roles from this screen.';
        }
        if ($targetIsSuper && ! $actorIsSuper) {
            return 'Only a Super Administrator can modify another Super Administrator.';
        }
        if ($targetWillBeSuper && ! $actorIsSuper) {
            return 'Only a Super Administrator can assign that role.';
        }
        if ($targetIsSuper && (! $targetWillBeActive || ! $targetWillBeSuper) && $activeSuperAdministrators <= 1) {
            return 'NovaNuke must retain at least one active Super Administrator.';
        }

        return null;
    }
}
