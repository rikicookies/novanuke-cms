<?php

declare(strict_types=1);

namespace Modules\Comments\src;

use NovaNuke\Core\Comments\CommentTargetChecking;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Events\EventName;

final class CommentTargetAccessGuard
{
    public function __construct(private readonly EventDispatcher $events)
    {
    }

    public function allows(string $type, int $contentId): bool
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $type) || $contentId < 1) return false;
        $target = new CommentTargetChecking($type, $contentId);
        $this->events->dispatch(EventName::COMMENTS_CONTENT_CHECKING, $target);
        return $target->accepted;
    }

    public function require(string $type, int $contentId): void
    {
        if (! $this->allows($type, $contentId)) throw new CommentTargetNotFound();
    }
}
