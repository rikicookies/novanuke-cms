<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use NovaNuke\Core\Search\LikePattern;
use NovaNuke\Core\Search\SearchProviderInterface;
use NovaNuke\Core\Search\SearchProviderResult;
use NovaNuke\Core\Search\SearchQuery;
use NovaNuke\Core\Search\SearchResultItem;
use NovaNuke\Core\Access\AccessAudience;
use PDO;

final class WikiSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private readonly PDO $database,
        private readonly AccessAudience $audiences,
    ) {
    }

    public function type(): string
    {
        return 'wiki';
    }

    public function label(): string
    {
        return 'Wiki';
    }

    public function search(SearchQuery $query): SearchProviderResult
    {
        $allowedAudiences = ['public'];
        if ($query->userId !== null) {
            $allowedAudiences[] = 'member';
            if ($this->audiences->allows('vip', ['id' => $query->userId])) {
                $allowedAudiences[] = 'vip';
            }
        }

        $audienceParameters = [];
        foreach ($allowedAudiences as $index => $audience) {
            $audienceParameters['audience' . $index] = $audience;
        }
        $audiencePlaceholders = implode(',', array_map(
            static fn (string $name): string => ':' . $name,
            array_keys($audienceParameters),
        ));
        $where = "deleted_at IS NULL AND status='published' AND published_at<=UTC_TIMESTAMP() "
            . "AND audience IN ({$audiencePlaceholders}) "
            . "AND (title LIKE :title ESCAPE '=' OR content LIKE :content ESCAPE '=')";
        $like = LikePattern::contains($query->term);
        $parameters = $audienceParameters + ['title' => $like, 'content' => $like];

        $count = $this->database->prepare("SELECT COUNT(*) FROM wiki_pages WHERE {$where}");
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();

        $statement = $this->database->prepare(
            "SELECT namespace,slug,title,content,published_at FROM wiki_pages WHERE {$where} "
            . 'ORDER BY published_at DESC,id DESC LIMIT :limit',
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $query->limit, PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(static function (array $row): SearchResultItem {
            $path = ($row['namespace'] === '' ? '' : $row['namespace'] . ':') . $row['slug'];
            return new SearchResultItem(
                'wiki',
                (string) $row['title'],
                '/wiki/' . $path,
                (string) $row['content'],
                (string) $row['published_at'],
            );
        }, $statement->fetchAll());

        return new SearchProviderResult($items, $total);
    }
}
