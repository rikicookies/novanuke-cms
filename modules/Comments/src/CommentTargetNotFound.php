<?php

declare(strict_types=1);

namespace Modules\Comments\src;

use RuntimeException;

final class CommentTargetNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Not found.');
    }
}
