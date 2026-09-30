<?php

declare(strict_types=1);

namespace NovaNuke\Core\Content;

interface ContentRendererInterface
{
    public function render(string $source, ContentFormat $format, ContentProfile $profile = ContentProfile::FullContent): string;
}
