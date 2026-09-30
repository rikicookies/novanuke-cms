<?php

declare(strict_types=1);

namespace Modules\News\src;

use NovaNuke\Core\Config\ConfigRepository;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\Content\ContentFormat;
use NovaNuke\Core\Content\ContentProfile;
use NovaNuke\Core\Content\ContentRendererInterface;

final class RssController
{
    public function __construct(
        private readonly NewsRepository $news, private readonly RssFeedBuilder $feeds,
        private readonly SettingsRepository $settings, private readonly ConfigRepository $config,
        private readonly ContentRendererInterface $contentRenderer,
    ) {
    }

    public function feed(): Response
    {
        $name = $this->settings->string('site.name', (string) $this->config->get('app.name', 'NovaNuke'));
        $url = $this->settings->string('site.url', (string) $this->config->get('app.url', 'http://localhost'));
        $language = $this->settings->string('site.locale', (string) $this->config->get('app.locale', 'en'));
        $description = $this->settings->string('site.description', "Latest news from {$name}.");
        $articles = $this->news->rssArticles();
        foreach ($articles as &$article) {
            $article['summary'] = trim(strip_tags($this->contentRenderer->render((string) ($article['summary'] ?? ''), ContentFormat::fromInput($article['summary_format'] ?? null), ContentProfile::Description)));
            $article['content'] = trim(strip_tags($this->contentRenderer->render((string) $article['content'], ContentFormat::fromInput($article['content_format'] ?? null), ContentProfile::FullContent)));
        }
        unset($article);
        return Response::xml($this->feeds->build($name, $description, $url, $language, $articles), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
