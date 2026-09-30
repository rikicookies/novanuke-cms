<?php

declare(strict_types=1);

namespace Modules\Pages\src;

/** @deprecated Use NovaNuke\Core\Content\ContentChanged for cross-module listeners. */
final readonly class PageChanged extends \NovaNuke\Core\Content\ContentChanged
{
    public function __construct(string $contentType, int $id, int $actorId)
    {
        parent::__construct($contentType, $id, $actorId);
    }
}
