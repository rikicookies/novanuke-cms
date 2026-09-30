<?php

declare(strict_types=1);

namespace NovaNuke\Core\Blocks;

use League\CommonMark\CommonMarkConverter;
use NovaNuke\Core\Security\HtmlSanitizer;

final class MarkdownRenderer
{
    private readonly CommonMarkConverter $converter;

    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    public function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        return $this->sanitizer->sanitize($this->converter->convert($markdown)->getContent());
    }
}
