<?php

declare(strict_types=1);

namespace Modules\Seo\src;

use NovaNuke\Core\Sitemap\SitemapCollecting;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Response;

final class PublicSeoController
{
    private readonly string $baseUrl;

    public function __construct(private readonly EventDispatcher $events, private readonly SitemapBuilder $builder, string $baseUrl)
    {
        $this->baseUrl = $builder->normalizeBaseUrl($baseUrl);
    }

    public function sitemap(): Response
    {
        $collection = new SitemapCollecting();
        $collection->add('/', null, 'daily', 1.0);
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::SITEMAP_COLLECTING, $collection);
        return Response::xml($this->builder->build($this->baseUrl, $collection), 200, ['Cache-Control'=>'public, max-age=900']);
    }

    public function robots(): Response
    {
        $base = rtrim($this->baseUrl, '/');
        $body = "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /account\nDisallow: /messages\nDisallow: /notifications\nDisallow: /install\nSitemap: {$base}/sitemap.xml\n";
        return new Response($body, 200, ['Content-Type'=>'text/plain; charset=UTF-8','Cache-Control'=>'public, max-age=3600','X-Content-Type-Options'=>'nosniff']);
    }
}
