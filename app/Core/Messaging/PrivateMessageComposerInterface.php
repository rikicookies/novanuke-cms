<?php

declare(strict_types=1);

namespace NovaNuke\Core\Messaging;

interface PrivateMessageComposerInterface
{
    public function composeUrlFor(string $username): string;
}
