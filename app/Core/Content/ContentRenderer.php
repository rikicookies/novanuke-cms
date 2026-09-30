<?php

declare(strict_types=1);

namespace NovaNuke\Core\Content;

use NovaNuke\Core\Blocks\MarkdownRenderer;
use NovaNuke\Core\Security\HtmlSanitizer;

final class ContentRenderer implements ContentRendererInterface
{
    public function __construct(
        private readonly HtmlSanitizer $html,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    public function render(string $source, ContentFormat $format, ContentProfile $profile = ContentProfile::FullContent): string
    {
        $tags = match ($profile) {
            ContentProfile::FullContent => null,
            ContentProfile::Description => ['p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'h2', 'h3', 'h4', 'a', 'hr'],
            ContentProfile::Comment, ContentProfile::Profile, ContentProfile::Message => ['p', 'br', 'strong', 'em', 's', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'a'],
        };
        return match ($format) {
            ContentFormat::Html => $this->html->sanitize($source, $tags),
            ContentFormat::Markdown => $this->html->sanitize($this->markdown->render($source), $tags),
        };
    }
}
