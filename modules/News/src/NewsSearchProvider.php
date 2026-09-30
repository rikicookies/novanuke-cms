<?php

declare(strict_types=1);

namespace Modules\News\src;

use NovaNuke\Core\Search\SearchProviderInterface;
use NovaNuke\Core\Search\LikePattern;
use NovaNuke\Core\Search\SearchProviderResult;
use NovaNuke\Core\Search\SearchQuery;
use NovaNuke\Core\Search\SearchResultItem;
use NovaNuke\Core\Access\AccessAudience;
use PDO;

final class NewsSearchProvider implements SearchProviderInterface
{
    public function __construct(private readonly PDO $database, private readonly AccessAudience $accessAudience)
    {
    }

    public function type(): string { return 'news'; }
    public function label(): string { return 'News'; }

    public function search(SearchQuery $query): SearchProviderResult
    {
        $audiences = ['public'];
        if ($query->userId !== null) {
            $audiences[] = 'member';
            if ($this->accessAudience->allows('vip', ['id' => $query->userId])) $audiences[] = 'vip';
        }
        $audienceParameters = [];
        foreach ($audiences as $index => $audience) $audienceParameters['audience' . $index] = $audience;
        $placeholders = implode(',', array_map(static fn (string $name): string => ':' . $name, array_keys($audienceParameters)));
        $where = "deleted_at IS NULL AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled') AND audience IN ({$placeholders}) AND (title LIKE :title ESCAPE '=' OR summary LIKE :summary ESCAPE '=' OR content LIKE :content ESCAPE '=')";
        $like = LikePattern::contains($query->term);
        $parameters = $audienceParameters + ['title' => $like, 'summary' => $like, 'content' => $like];
        $count = $this->database->prepare("SELECT COUNT(*) FROM news_articles WHERE {$where}"); $count->execute($parameters); $total = (int) $count->fetchColumn();
        $statement = $this->database->prepare("SELECT title,slug,summary,content,published_at FROM news_articles WHERE {$where} ORDER BY published_at DESC,id DESC LIMIT :limit");
        foreach ($parameters as $name => $value) $statement->bindValue(':' . $name, $value);
        $statement->bindValue(':limit', $query->limit, PDO::PARAM_INT); $statement->execute();
        $items = array_map(static fn (array $row): SearchResultItem => new SearchResultItem(
            'news', (string) $row['title'], '/news/' . $row['slug'], (string) ($row['summary'] ?: $row['content']), (string) $row['published_at'],
        ), $statement->fetchAll());
        return new SearchProviderResult($items, $total);
    }
}
