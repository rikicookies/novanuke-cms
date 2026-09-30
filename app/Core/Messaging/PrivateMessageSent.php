<?php

declare(strict_types=1);

namespace NovaNuke\Core\Messaging;

final readonly class PrivateMessageSent
{
    public function __construct(public int $recipientId, public int $conversationId, public string $messageKey) {}
}
