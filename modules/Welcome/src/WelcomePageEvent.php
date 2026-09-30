<?php

declare(strict_types=1);

namespace Modules\Welcome\src;

final class WelcomePageEvent
{
    public function __construct(public string $message)
    {
    }
}
