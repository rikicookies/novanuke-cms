<?php

declare(strict_types=1);

namespace NovaNuke\Core\Comments;

final readonly class CommentCreated
{
    public function __construct(public int $id, public string $contentType, public int $contentId, public string $status) {}
}
