<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\MarkdownParser;
use RuntimeException;

final class WikiLinkIndexer
{
    private readonly MarkdownParser $parser;

    public function __construct(private readonly WikiInput $input)
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $this->parser = new MarkdownParser($environment);
    }

    /** @return list<string> */
    public function targets(string $markdown): array
    {
        if (trim($markdown) === '') return [];
        $targets = [];
        $walker = $this->parser->parse($markdown)->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if (! $event->isEntering() || ! $node instanceof Link) continue;
            $url = trim($node->getUrl());
            if (! str_starts_with($url, '/wiki/')) continue;
            $urlPath = parse_url($url, PHP_URL_PATH);
            if (! is_string($urlPath) || ! str_starts_with($urlPath, '/wiki/')) continue;
            try {
                $targets[] = $this->input->path(rawurldecode(substr($urlPath, 6)));
            } catch (RuntimeException) {
            }
            if (count($targets) >= 500) break;
        }
        return array_values(array_unique($targets));
    }
}
