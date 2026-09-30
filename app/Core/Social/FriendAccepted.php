<?php

declare(strict_types=1);

namespace NovaNuke\Core\Social;

final readonly class FriendAccepted
{
    public function __construct(public int $recipientId, public int $acceptedById) {}
}
