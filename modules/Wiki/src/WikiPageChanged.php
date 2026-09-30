<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

/** @deprecated Use NovaNuke\Core\Content\ContentChanged for cross-module listeners. */
final readonly class WikiPageChanged extends \NovaNuke\Core\Content\ContentChanged
{
    public function __construct(int $id, public string $path, int $actorId)
    {
        parent::__construct('wiki', $id, $actorId, $path);
    }
}
