<?php

declare(strict_types=1);

namespace NovaNuke\Core\Comments;

final class CommentTargetChecking
{
    public bool $accepted = false;
    public function __construct(public readonly string $type, public readonly int $contentId) {}
    public function accept(): void { $this->accepted = true; }
}
