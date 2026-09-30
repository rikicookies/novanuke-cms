<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

final class DownloadCompleted
{
    public function __construct(public readonly int $downloadId, public readonly bool $counted)
    {
    }
}
