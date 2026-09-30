<?php

declare(strict_types=1);

namespace NovaNuke\Core\Content;

/**
 * Shared payload for generic content lifecycle notifications.
 *
 * The optional reference carries a module-owned stable reference such as a
 * Wiki path without forcing listeners to depend on that module's DTO.
 */
readonly class ContentChanged
{
    public string $contentType;

    public function __construct(
        public string $type,
        public int $id,
        public int $actorId,
        public ?string $reference = null,
    ) {
        $this->contentType = $type;
    }
}
