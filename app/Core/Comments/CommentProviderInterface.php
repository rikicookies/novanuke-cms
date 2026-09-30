<?php

declare(strict_types=1);

namespace NovaNuke\Core\Comments;

interface CommentProviderInterface
{
    public function for(string $type, int $id): array;
    public function guestsAllowed(): bool;
}
