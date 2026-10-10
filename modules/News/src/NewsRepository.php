<?php

declare(strict_types=1);

namespace Modules\News\src;

use NovaNuke\Core\Access\AccessAudience;
use PDO;
use RuntimeException;

final class NewsRepository
{
    private readonly int $perPage;

    public function __construct(
        private readonly PDO $database,
        private readonly AccessAudience $accessAudience,
        int $perPage = 10,
    )
    {
        $this->perPage = max(5, min(100, $perPage));
    }

    public function categories(): array { return $this->database->query('SELECT * FROM news_categories ORDER BY name')->fetchAll(); }
    public function topics(): array { return $this->database->query('SELECT * FROM news_topics ORDER BY name')->fetchAll(); }
    public function publishedCountByAuthor(int $userId):int{$s=$this->database->prepare("SELECT COUNT(*) FROM news_articles WHERE author_id=:user AND audience='public' AND status='published' AND published_at<=UTC_TIMESTAMP() AND deleted_at IS NULL");$s->execute(['user'=>$userId]);return(int)$s->fetchColumn();}

    public function adminArticles(string $search = '', ?int $categoryId = null, string $sort = 'newest'): array
    {
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        if ($search !== '') { $where[] = '(a.title LIKE :search OR a.slug LIKE :search)'; $params['search'] = '%' . $search . '%'; }
        if ($categoryId !== null) { $where[] = 'a.category_id=:category'; $params['category'] = $categoryId; }
        $order = $sort === 'oldest' ? 'a.updated_at ASC,a.id ASC' : 'a.updated_at DESC,a.id DESC';
        $statement = $this->database->prepare(
            'SELECT a.id,a.title,a.slug,a.status,a.audience,a.is_featured,a.published_at,a.updated_at,u.username,c.name AS category_name '
            . 'FROM news_articles a INNER JOIN users u ON u.id=a.author_id LEFT JOIN news_categories c ON c.id=a.category_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order
        );
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /** @param list<int> $ids */
    public function bulkUpdate(array $ids, string $action): int
    {
        if ($ids === []) return 0;
        $allowed = ['publish', 'unpublish', 'public', 'member', 'vip', 'delete'];
        if (! in_array($action, $allowed, true)) throw new RuntimeException('Invalid bulk action.');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        if ($action === 'delete') $sql = "UPDATE news_articles SET deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE deleted_at IS NULL AND id IN ({$marks})";
        elseif ($action === 'publish') $sql = "UPDATE news_articles SET status='published',published_at=COALESCE(published_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE deleted_at IS NULL AND id IN ({$marks})";
        elseif ($action === 'unpublish') $sql = "UPDATE news_articles SET status='draft',updated_at=UTC_TIMESTAMP() WHERE deleted_at IS NULL AND id IN ({$marks})";
        else { $sql = "UPDATE news_articles SET audience=?,updated_at=UTC_TIMESTAMP() WHERE deleted_at IS NULL AND id IN ({$marks})"; array_unshift($params, $action); }
        $statement = $this->database->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public function article(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM news_articles WHERE id=:id AND deleted_at IS NULL');
        $statement->execute(['id' => $id]);
        $article = $statement->fetch();
        if (! is_array($article)) return null;
        $article['tags'] = implode(', ', $this->tagNames($id));
        return $article;
    }

    /** @param array<string,mixed> $data */
    public function save(?int $id, array $data, int $authorId): int
    {
        $tags = $data['tags'];
        unset($data['tags']);
        $this->assertTaxonomy($data['category_id'], 'news_categories');
        $this->assertTaxonomy($data['topic_id'], 'news_topics');
        $this->database->beginTransaction();
        try {
            if ($id === null) {
                $sql = 'INSERT INTO news_articles (author_id,category_id,topic_id,title,slug,summary,summary_format,content,content_format,featured_image,status,audience,is_featured,comments_enabled,seo_title,seo_description,published_at,created_at,updated_at) '
                    . 'VALUES (:author_id,:category_id,:topic_id,:title,:slug,:summary,:summary_format,:content,:content_format,:featured_image,:status,:audience,:is_featured,:comments_enabled,:seo_title,:seo_description,:published_at,UTC_TIMESTAMP(),UTC_TIMESTAMP())';
                $data['author_id'] = $authorId;
            } else {
                if ($this->article($id) === null) throw new RuntimeException('News article not found.');
                $sql = 'UPDATE news_articles SET category_id=:category_id,topic_id=:topic_id,title=:title,slug=:slug,summary=:summary,summary_format=:summary_format,content=:content,content_format=:content_format,featured_image=:featured_image,status=:status,audience=:audience,is_featured=:is_featured,comments_enabled=:comments_enabled,seo_title=:seo_title,seo_description=:seo_description,published_at=:published_at,updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL';
                $data['id'] = $id;
            }
            $statement = $this->database->prepare($sql);
            $statement->execute($data);
            $articleId = $id ?? (int) $this->database->lastInsertId();
            $this->syncTags($articleId, $tags);
            $this->database->commit();
            return $articleId;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($error instanceof \PDOException && $error->getCode() === '23000') {
                throw new RuntimeException('The news slug is already in use.', 0, $error);
            }
            throw $error;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('UPDATE news_articles SET deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('News article not found.');
    }

    public function mediaUsage(string $publicPath): int
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM news_articles WHERE featured_image=:path');
        $statement->execute(['path' => $publicPath]);
        return (int) $statement->fetchColumn();
    }

    public function saveTaxonomy(string $type, array $data): int
    {
        $table = $type === 'category' ? 'news_categories' : ($type === 'topic' ? 'news_topics' : null);
        if ($table === null) throw new RuntimeException('Invalid taxonomy type.');
        try {
            $statement = $this->database->prepare("INSERT INTO {$table} (name,slug,description,created_at,updated_at) VALUES (:name,:slug,:description,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $statement->execute($data);
            return (int) $this->database->lastInsertId();
        } catch (\PDOException $error) {
            if ($error->getCode() === '23000') throw new RuntimeException('That taxonomy slug is already in use.', 0, $error);
            throw $error;
        }
    }

    public function taxonomy(string $type, int $id): ?array
    {
        $table = $this->taxonomyTable($type);
        $statement = $this->database->prepare("SELECT * FROM {$table} WHERE id=:id");
        $statement->execute(['id' => $id]);
        $taxonomy = $statement->fetch();

        return is_array($taxonomy) ? $taxonomy : null;
    }

    /** @return list<array<string,mixed>> */
    public function categoryParentOptions(int $categoryId): array
    {
        $categories = $this->database->query('SELECT id,parent_id,name,slug FROM news_categories ORDER BY name,id')->fetchAll();
        $children = [];
        foreach ($categories as $category) {
            $parent = $category['parent_id'] === null ? 0 : (int) $category['parent_id'];
            $children[$parent][] = (int) $category['id'];
        }
        $excluded = [$categoryId => true];
        $pending = $children[$categoryId] ?? [];
        while ($pending !== []) {
            $child = array_pop($pending);
            if (isset($excluded[$child])) continue;
            $excluded[$child] = true;
            foreach ($children[$child] ?? [] as $descendant) $pending[] = $descendant;
        }

        return array_values(array_filter($categories, static function (array $category) use ($excluded): bool {
            return ! isset($excluded[(int) $category['id']]);
        }));
    }

    /** @param array<string,mixed> $data */
    public function updateTaxonomy(string $type, int $id, array $data): void
    {
        $table = $this->taxonomyTable($type);
        $this->database->beginTransaction();
        try {
            $currentStatement = $this->database->prepare("SELECT * FROM {$table} WHERE id=:id FOR UPDATE");
            $currentStatement->execute(['id' => $id]);
            $current = $currentStatement->fetch();
            if (! is_array($current)) throw new RuntimeException('News taxonomy not found.');

            if ($type === 'category') $this->assertCategoryParent($id, $data['parent_id'] ?? null);
            $fields = 'name=:name,description=:description,updated_at=UTC_TIMESTAMP()';
            $parameters = ['name' => $data['name'], 'description' => $data['description'] ?? null, 'id' => $id];
            if ($type === 'category') {
                $fields = 'parent_id=:parent_id,' . $fields;
                $parameters['parent_id'] = $data['parent_id'] ?? null;
            }
            $statement = $this->database->prepare("UPDATE {$table} SET {$fields} WHERE id=:id");
            $statement->execute($parameters);
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    private function taxonomyTable(string $type): string
    {
        $table = $type === 'category' ? 'news_categories' : ($type === 'topic' ? 'news_topics' : null);
        if ($table === null) throw new RuntimeException('Invalid taxonomy type.');

        return $table;
    }

    private function assertCategoryParent(int $categoryId, mixed $parentId): void
    {
        if ($parentId === null) return;
        $parentId = (int) $parentId;
        $statement = $this->database->prepare('SELECT id,parent_id FROM news_categories WHERE id=:id FOR UPDATE');
        $seen = [];
        while ($parentId !== 0) {
            if (isset($seen[$parentId])) throw new RuntimeException('Category hierarchy contains a cycle.');
            $seen[$parentId] = true;
            if ($parentId === $categoryId) throw new RuntimeException('A category cannot be its own parent or descendant.');
            $statement->execute(['id' => $parentId]);
            $parent = $statement->fetch();
            if (! is_array($parent)) throw new RuntimeException('Selected parent category does not exist.');
            $parentId = $parent['parent_id'] === null ? 0 : (int) $parent['parent_id'];
        }
    }

    public function publicArticles(int $page, ?string $categorySlug = null, ?int $userId = null): array
    {
        $audiences = $this->visibleAudiences($userId);
        $placeholders = implode(',', array_map(static fn (int $index): string => ':audience' . $index, array_keys($audiences)));
        $where = "a.deleted_at IS NULL AND a.published_at<=UTC_TIMESTAMP() AND a.status IN ('published','scheduled') AND a.audience IN ({$placeholders})";
        $parameters = [];
        foreach ($audiences as $index => $audience) $parameters['audience' . $index] = $audience;
        if ($categorySlug !== null) {
            $where .= ' AND c.slug=:category';
            $parameters['category'] = $categorySlug;
        }
        $count = $this->database->prepare("SELECT COUNT(*) FROM news_articles a LEFT JOIN news_categories c ON c.id=a.category_id WHERE {$where}");
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $perPage = $this->perPage;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $sql = "SELECT a.id,a.title,a.slug,a.summary,a.summary_format,a.featured_image,a.is_featured,a.published_at,a.view_count,u.username,c.name AS category_name,c.slug AS category_slug,t.name AS topic_name "
            . "FROM news_articles a INNER JOIN users u ON u.id=a.author_id LEFT JOIN news_categories c ON c.id=a.category_id LEFT JOIN news_topics t ON t.id=a.topic_id WHERE {$where} "
            . 'ORDER BY a.is_featured DESC,a.published_at DESC,a.id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->database->prepare($sql);
        foreach ($parameters as $key => $value) $statement->bindValue(':' . $key, $value);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => $statement->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function publicArticle(string $slug): ?array
    {
        $statement = $this->database->prepare(
            "SELECT a.*,u.username,c.name AS category_name,c.slug AS category_slug,t.name AS topic_name,t.slug AS topic_slug "
            . "FROM news_articles a INNER JOIN users u ON u.id=a.author_id LEFT JOIN news_categories c ON c.id=a.category_id LEFT JOIN news_topics t ON t.id=a.topic_id "
            . "WHERE a.slug=:slug AND a.deleted_at IS NULL AND a.published_at<=UTC_TIMESTAMP() AND a.status IN ('published','scheduled') LIMIT 1"
        );
        $statement->execute(['slug' => $slug]);
        $article = $statement->fetch();
        if (! is_array($article)) return null;
        $article['tag_list'] = $this->tagNames((int) $article['id']);
        return $article;
    }

    public function canView(array $article, ?int $userId): bool
    {
        return $this->accessAudience->allows(
            (string) ($article['audience'] ?? 'public'),
            $userId === null ? null : ['id' => $userId],
        );
    }

    public function rssArticles(): array
    {
        return $this->database->query(
            "SELECT a.title,a.slug,a.summary,a.summary_format,a.content,a.content_format,a.published_at,u.username,c.name AS category_name "
            . "FROM news_articles a INNER JOIN users u ON u.id=a.author_id LEFT JOIN news_categories c ON c.id=a.category_id "
            . "WHERE a.audience='public' AND a.deleted_at IS NULL AND a.published_at<=UTC_TIMESTAMP() AND a.status IN ('published','scheduled') "
            . 'ORDER BY a.published_at DESC,a.id DESC LIMIT 20'
        )->fetchAll();
    }

    /** @return array<int,array{slug:string,updated_at:string}> */
    public function sitemapEntries(): array
    {
        return $this->database->query(
            "SELECT slug,updated_at FROM news_articles WHERE audience='public' AND deleted_at IS NULL AND published_at<=UTC_TIMESTAMP() "
            . "AND status IN ('published','scheduled') ORDER BY id"
        )->fetchAll();
    }

    public function incrementViews(int $id): void
    {
        $statement = $this->database->prepare('UPDATE news_articles SET view_count=view_count+1 WHERE id=:id');
        $statement->execute(['id' => $id]);
    }

    public function acceptsComments(int $id, ?int $userId): bool
    {
        $statement = $this->database->prepare(
            "SELECT id,audience FROM news_articles WHERE id=:id AND deleted_at IS NULL AND comments_enabled=1 "
            . "AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled')"
        );
        $statement->execute(['id' => $id]);
        $article = $statement->fetch();
        return is_array($article) && $this->canView($article, $userId);
    }

    /** @return list<string> */
    private function visibleAudiences(?int $userId): array
    {
        $audiences = ['public'];
        if ($userId === null) return $audiences;
        $audiences[] = 'member';
        if ($this->accessAudience->allows('vip', ['id' => $userId])) $audiences[] = 'vip';
        return $audiences;
    }

    private function assertTaxonomy(?int $id, string $table): void
    {
        if ($id === null) return;
        $statement = $this->database->prepare("SELECT COUNT(*) FROM {$table} WHERE id=:id");
        $statement->execute(['id' => $id]);
        if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('Selected category or topic does not exist.');
    }

    private function syncTags(int $articleId, array $tags): void
    {
        $this->database->prepare('DELETE FROM news_article_tags WHERE article_id=:id')->execute(['id' => $articleId]);
        $insertTag = $this->database->prepare('INSERT INTO news_tags (name,slug,created_at) VALUES (:name,:slug,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name)');
        $tagId = $this->database->prepare('SELECT id FROM news_tags WHERE slug=:slug');
        $pivot = $this->database->prepare('INSERT INTO news_article_tags (article_id,tag_id) VALUES (:article,:tag)');
        foreach ($tags as $slug => $name) {
            $insertTag->execute(compact('name', 'slug'));
            $tagId->execute(['slug' => $slug]);
            $pivot->execute(['article' => $articleId, 'tag' => (int) $tagId->fetchColumn()]);
        }
    }

    private function tagNames(int $articleId): array
    {
        $statement = $this->database->prepare('SELECT t.name FROM news_article_tags at INNER JOIN news_tags t ON t.id=at.tag_id WHERE at.article_id=:id ORDER BY t.name');
        $statement->execute(['id' => $articleId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
