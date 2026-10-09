<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use NovaNuke\Core\Membership\MembershipManagerInterface;
use PDO;
use PDOException;
use RuntimeException;

final class WikiRepository
{
    /** @var array<string,string> */
    private const ADMIN_SORTS = [
        'title_asc' => 'w.title ASC,w.id ASC',
        'title_desc' => 'w.title DESC,w.id DESC',
        'path' => 'w.namespace ASC,w.slug ASC,w.id ASC',
        'newest' => 'w.created_at DESC,w.id DESC',
        'oldest' => 'w.created_at ASC,w.id ASC',
        'updated' => 'w.updated_at DESC,w.id DESC',
    ];

    public function __construct(
        private readonly PDO $database,
        private readonly MembershipManagerInterface $memberships,
        private readonly WikiLinkIndexer $links,
    )
    {
    }

    /** @return list<array<string,mixed>> */
    public function adminPages(): array
    {
        return $this->database->query(
            "SELECT w.id,w.namespace,w.slug,w.title,w.status,w.audience,w.published_at,w.updated_at,u.username "
            . 'FROM wiki_pages w INNER JOIN users u ON u.id=w.author_id WHERE w.deleted_at IS NULL '
            . 'ORDER BY w.namespace,w.slug,w.id'
        )->fetchAll();
    }

    /** @return array{items:list<array<string,mixed>>,page:int,pages:int,total:int,counts:array{all:int,published:int,draft:int}} */
    public function adminListing(int $page = 1, int $perPage = 20, string $status = 'all', ?string $namespace = null, string $search = '', string $sort = 'path'): array
    {
        $perPage = in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 20;
        $status = in_array($status, ['all', 'published', 'draft'], true) ? $status : 'all';
        $sort = array_key_exists($sort, self::ADMIN_SORTS) ? $sort : 'path';
        $where = ['w.deleted_at IS NULL'];
        $params = [];
        if ($status !== 'all') { $where[] = 'w.status=:status'; $params['status'] = $status; }
        if ($namespace !== null) { $where[] = 'w.namespace=:namespace'; $params['namespace'] = $namespace; }
        if ($search !== '') {
            $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
            $where[] = '(w.title LIKE :search_title ESCAPE \'=\' OR w.namespace LIKE :search_namespace ESCAPE \'=\' OR w.slug LIKE :search_slug ESCAPE \'=\' OR CONCAT(CASE WHEN w.namespace=\'\' THEN \'\' ELSE CONCAT(w.namespace, \':\') END,w.slug) LIKE :search_path ESCAPE \'=\')';
            $params += ['search_title' => $like, 'search_namespace' => $like, 'search_slug' => $like, 'search_path' => $like];
        }
        $clause = implode(' AND ', $where);
        $count = $this->database->prepare("SELECT COUNT(*) AS all_count,COALESCE(SUM(w.status='published'),0) AS published_count,COALESCE(SUM(w.status='draft'),0) AS draft_count FROM wiki_pages w WHERE {$clause}");
        $count->execute($params);
        $summary = $count->fetch() ?: ['all_count' => 0, 'published_count' => 0, 'draft_count' => 0];
        $total = (int) $summary['all_count'];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $statement = $this->database->prepare(
            'SELECT w.id,w.namespace,w.slug,w.title,w.status,w.audience,w.published_at,w.created_at,w.updated_at,u.username '
            . 'FROM wiki_pages w INNER JOIN users u ON u.id=w.author_id '
            . "WHERE {$clause} ORDER BY " . self::ADMIN_SORTS[$sort] . ' LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => $statement->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $total, 'counts' => [
            'all' => $total, 'published' => (int) $summary['published_count'], 'draft' => (int) $summary['draft_count'],
        ]];
    }

    /** @return list<string> */
    public function adminNamespaces(): array
    {
        return array_map('strval', $this->database->query('SELECT DISTINCT namespace FROM wiki_pages WHERE deleted_at IS NULL ORDER BY namespace')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{namespace:string,pages:int,direct_pages:int}> */
    public function namespaceSummary(): array
    {
        $rows = $this->database->query("SELECT namespace FROM wiki_pages WHERE deleted_at IS NULL AND namespace<>'' ORDER BY namespace")->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];
        $direct = [];
        foreach ($rows as $value) {
            $namespace = (string) $value;
            $direct[$namespace] = ($direct[$namespace] ?? 0) + 1;
            $parts = explode(':', $namespace);
            for ($i = 1; $i <= count($parts); $i++) {
                $parent = implode(':', array_slice($parts, 0, $i));
                $counts[$parent] = ($counts[$parent] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_NATURAL);
        $result = [];
        foreach ($counts as $namespace => $pages) $result[] = ['namespace' => $namespace, 'pages' => $pages, 'direct_pages' => $direct[$namespace] ?? 0];
        return $result;
    }

    /** @return array{old:string,new:string,include_descendants:bool,affected:list<array{id:int,from:string,to:string}>,collisions:list<array{from:string,to:string}>,affected_count:int} */
    public function namespaceMovePlan(string $old, string $new, bool $includeDescendants): array
    {
        $rows = $this->database->query('SELECT id,namespace,slug FROM wiki_pages WHERE deleted_at IS NULL ORDER BY namespace,slug,id')->fetchAll();
        $plan = $this->buildNamespaceMovePlan($rows, $old, $new, $includeDescendants);
        self::validateNamespaceMoveTargets($plan['affected']);
        return $plan;
    }

    /** @return array{old:string,new:string,include_descendants:bool,affected:list<array{id:int,from:string,to:string}>,collisions:list<array{from:string,to:string}>,affected_count:int} */
    public function renameNamespace(string $old, string $new, bool $includeDescendants): array
    {
        $this->database->beginTransaction();
        try {
            $rows = $this->database->query('SELECT id,namespace,slug FROM wiki_pages WHERE deleted_at IS NULL ORDER BY namespace,slug,id FOR UPDATE')->fetchAll();
            $plan = $this->buildNamespaceMovePlan($rows, $old, $new, $includeDescendants);
            if ($plan['affected_count'] === 0) throw new RuntimeException('Wiki namespace has no pages to move.');
            if ($plan['collisions'] !== []) throw new RuntimeException('Wiki namespace move has path collisions.');
            self::validateNamespaceMoveTargets($plan['affected']);
            $this->assertAliasCollisions($plan['affected']);
            $this->createAliases($plan['affected']);
            $statement = $this->database->prepare('UPDATE wiki_pages SET namespace=:namespace,updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL');
            foreach ($plan['affected'] as $item) {
                $target = str_contains($item['to'], ':') ? substr($item['to'], 0, (int) strrpos($item['to'], ':')) : '';
                $statement->execute(['namespace' => $target, 'id' => $item['id']]);
            }
            $this->database->commit();
            return $plan;
        } catch (PDOException $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($error->getCode() === '23000') throw new RuntimeException('A historical Wiki path is already assigned to another page.', 0, $error);
            throw $error;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** @param list<array{id:int,from:string,to:string}> $affected */
    public static function validateNamespaceMoveTargets(array $affected): void
    {
        foreach ($affected as $item) {
            $to = (string) $item['to'];
            if (preg_match('//u', $to) !== 1) throw new RuntimeException('The destination namespace or page path is too long.');
            if (preg_match('/^[a-z0-9:-]+$/D', $to) !== 1) throw new RuntimeException('The destination namespace is invalid.');
            [$namespace, $slug] = self::splitPath($to);
            if (mb_strlen($namespace, 'UTF-8') > 190 || mb_strlen($slug, 'UTF-8') > 120 || mb_strlen($to, 'UTF-8') > 311) throw new RuntimeException('The destination namespace or page path is too long.');
        }
    }

    /** @param list<array{id:int,from:string,to:string}> $affected */
    private function assertAliasCollisions(array $affected): void
    {
        $paths = [];
        foreach ($affected as $item) {
            $paths[$item['from']] = (int) $item['id'];
            $paths[$item['to']] = (int) $item['id'];
        }
        if ($paths === []) return;
        $placeholders = implode(',', array_fill(0, count($paths), '?'));
        $statement = $this->database->prepare("SELECT historical_path,wiki_page_id FROM wiki_path_aliases WHERE historical_path IN ({$placeholders})");
        $statement->execute(array_keys($paths));
        foreach ($statement->fetchAll() as $row) if ((int) $row['wiki_page_id'] !== $paths[(string) $row['historical_path']]) throw new RuntimeException('A historical Wiki path is already assigned to another page.');
    }

    /** @param list<array{id:int,from:string,to:string}> $affected */
    private function createAliases(array $affected): void
    {
        $lookup = $this->database->prepare('SELECT wiki_page_id FROM wiki_path_aliases WHERE historical_path=:path FOR UPDATE');
        $insert = $this->database->prepare('INSERT INTO wiki_path_aliases (historical_path,wiki_page_id,created_at) VALUES (:path,:page_id,UTC_TIMESTAMP())');
        foreach ($affected as $item) {
            $lookup->execute(['path' => $item['from']]);
            $owner = $lookup->fetchColumn();
            if ($owner !== false) {
                if ((int) $owner !== (int) $item['id']) {
                    throw new RuntimeException('A historical Wiki path is already assigned to another page.');
                }
                continue;
            }
            try {
                $insert->execute(['path' => $item['from'], 'page_id' => $item['id']]);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    throw new RuntimeException('A historical Wiki path is already assigned to another page.', 0, $error);
                }
                throw $error;
            }
        }
    }

    /** @return array{0:string,1:string} */
    private static function splitPath(string $path): array
    {
        $position = strrpos($path, ':');
        return $position === false ? ['', $path] : [substr($path, 0, $position), substr($path, $position + 1)];
    }

    /** @param list<array{id:mixed,namespace:mixed,slug:mixed}> $rows */
    private function buildNamespaceMovePlan(array $rows, string $old, string $new, bool $includeDescendants): array
    {
        $movingIds = [];
        $targets = [];
        $affected = [];
        foreach ($rows as $row) {
            $namespace = (string) $row['namespace'];
            $matches = $namespace === $old || ($includeDescendants && str_starts_with($namespace, $old . ':'));
            if (! $matches) continue;
            $suffix = $namespace === $old ? '' : substr($namespace, strlen($old));
            $targetNamespace = $new . $suffix;
            $from = ($namespace === '' ? '' : $namespace . ':') . (string) $row['slug'];
            $to = ($targetNamespace === '' ? '' : $targetNamespace . ':') . (string) $row['slug'];
            $id = (int) $row['id'];
            $movingIds[$id] = true;
            $targets[$to][] = $id;
            $affected[] = ['id' => $id, 'from' => $from, 'to' => $to];
        }
        $collisions = [];
        foreach ($targets as $path => $ids) if (count($ids) > 1) $collisions[$path] = ['from' => '(moving pages)', 'to' => $path];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($movingIds[$id])) continue;
            $path = ((string) $row['namespace'] === '' ? '' : (string) $row['namespace'] . ':') . (string) $row['slug'];
            if (isset($targets[$path])) $collisions[$path] = ['from' => '(moving page)', 'to' => $path];
        }
        return ['old' => $old, 'new' => $new, 'include_descendants' => $includeDescendants, 'affected' => $affected, 'collisions' => array_values($collisions), 'affected_count' => count($affected)];
    }

    /** @return list<array{namespace:string,slug:string,content:string}> */
    public function exportPages(): array
    {
        return $this->database->query(
            'SELECT namespace,slug,content FROM wiki_pages WHERE deleted_at IS NULL ORDER BY namespace,slug'
        )->fetchAll();
    }

    /** @return list<string> */
    public function existingPaths(): array
    {
        $pages = $this->database->query(
            'SELECT namespace,slug FROM wiki_pages ORDER BY namespace,slug'
        )->fetchAll();
        return array_map(
            static fn (array $page): string => ($page['namespace'] === '' ? '' : $page['namespace'] . ':') . $page['slug'],
            $pages,
        );
    }

    /** @return list<array<string,mixed>> */
    public function directory(?int $userId): array
    {
        $pages = $this->database->query(
            "SELECT id,namespace,slug,title,audience,updated_at FROM wiki_pages WHERE status='published' "
            . 'AND published_at<=UTC_TIMESTAMP() AND deleted_at IS NULL ORDER BY namespace,slug,id'
        )->fetchAll();
        return array_values(array_filter($pages, fn (array $page): bool => $this->canView($page, $userId)));
    }

    /** @return list<array<string,mixed>> */
    public function recentChanges(?int $userId, int $limit = 50): array
    {
        $pages = $this->database->query(
            "SELECT id,namespace,slug,title,audience,updated_at FROM wiki_pages WHERE status='published' "
            . 'AND published_at<=UTC_TIMESTAMP() AND deleted_at IS NULL ORDER BY updated_at DESC,id DESC'
        )->fetchAll();
        $visible = array_values(array_filter($pages, fn (array $page): bool => $this->canView($page, $userId)));
        return array_slice($visible, 0, max(1, min($limit, 100)));
    }

    /** @return list<array<string,mixed>> */
    public function search(string $term, ?int $userId, int $limit = 30): array
    {
        $statement = $this->database->prepare(
            "SELECT id,namespace,slug,title,audience,updated_at FROM wiki_pages WHERE status='published' "
            . "AND published_at<=UTC_TIMESTAMP() AND deleted_at IS NULL AND (title LIKE :title ESCAPE '=' OR content LIKE :content ESCAPE '=') "
            . 'ORDER BY updated_at DESC,id DESC'
        );
        $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $term) . '%';
        $statement->execute(['title' => $like, 'content' => $like]);
        $visible = array_values(array_filter($statement->fetchAll(), fn (array $page): bool => $this->canView($page, $userId)));
        return array_slice($visible, 0, max(1, min($limit, 100)));
    }

    /** @return list<array{namespace:string,slug:string,updated_at:string}> */
    public function sitemapEntries(): array
    {
        return $this->database->query(
            "SELECT namespace,slug,updated_at FROM wiki_pages WHERE audience='public' AND status='published' "
            . 'AND published_at<=UTC_TIMESTAMP() AND deleted_at IS NULL ORDER BY namespace,slug,id'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM wiki_pages WHERE id=:id AND deleted_at IS NULL');
        $statement->execute(['id' => $id]);
        $page = $statement->fetch();
        return is_array($page) ? $this->withPath($page) : null;
    }

    /** @return array<string,mixed>|null */
    public function publishedByPath(string $path): ?array
    {
        [$namespace, $slug] = $this->split($path);
        $statement = $this->database->prepare(
            "SELECT w.*,u.username FROM wiki_pages w INNER JOIN users u ON u.id=w.author_id "
            . "WHERE w.namespace=:namespace AND w.slug=:slug AND w.status='published' "
            . 'AND w.published_at<=UTC_TIMESTAMP() AND w.deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['namespace' => $namespace, 'slug' => $slug]);
        $page = $statement->fetch();
        return is_array($page) ? $this->withPath($page) : null;
    }

    /** @return array<string,mixed>|null */
    public function publishedByAlias(string $path): ?array
    {
        try {
            $statement = $this->database->prepare(
                "SELECT w.*,a.historical_path,u.username FROM wiki_path_aliases a INNER JOIN wiki_pages w ON w.id=a.wiki_page_id INNER JOIN users u ON u.id=w.author_id "
                . "WHERE a.historical_path=:path AND w.status='published' AND w.published_at<=UTC_TIMESTAMP() AND w.deleted_at IS NULL LIMIT 1"
            );
            $statement->execute(['path' => $path]);
            $page = $statement->fetch();
            return is_array($page) ? $this->withPath($page) : null;
        } catch (PDOException) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public function byPath(string $path): ?array
    {
        [$namespace, $slug] = $this->split($path);
        $statement = $this->database->prepare('SELECT * FROM wiki_pages WHERE namespace=:namespace AND slug=:slug AND deleted_at IS NULL LIMIT 1');
        $statement->execute(['namespace' => $namespace, 'slug' => $slug]);
        $page = $statement->fetch();
        return is_array($page) ? $this->withPath($page) : null;
    }

    /** @param array<string,mixed> $page */
    public function canView(array $page, ?int $userId): bool
    {
        $audience = (string) ($page['audience'] ?? 'public');
        if ($audience === 'public') return true;
        if ($userId === null) return false;
        if ($audience === 'member') return true;
        return $audience === 'vip' && $this->memberships->isVip($userId);
    }

    public function acceptsComments(int $id, ?int $userId): bool
    {
        $statement = $this->database->prepare(
            "SELECT id,audience FROM wiki_pages WHERE id=:id AND deleted_at IS NULL AND comments_enabled=1 "
            . "AND status='published' AND published_at<=UTC_TIMESTAMP()",
        );
        $statement->execute(['id' => $id]);
        $page = $statement->fetch();
        return is_array($page) && $this->canView($page, $userId);
    }

    /** @return list<array<string,mixed>> */
    public function backlinks(string $targetPath, ?int $userId): array
    {
        $pages = $this->database->query(
            "SELECT id,namespace,slug,title,audience,content FROM wiki_pages WHERE status='published' "
            . 'AND published_at<=UTC_TIMESTAMP() AND deleted_at IS NULL ORDER BY title,namespace,slug,id'
        )->fetchAll();
        $backlinks = [];
        foreach ($pages as $page) {
            $page = $this->withPath($page);
            if ($page['path'] === $targetPath || ! $this->canView($page, $userId)) continue;
            if (! in_array($targetPath, $this->links->targets((string) $page['content']), true)) continue;
            unset($page['content']);
            $backlinks[] = $page;
        }
        return $backlinks;
    }

    /** @return list<array{target_path:string,references:int}> */
    public function missingLinks(): array
    {
        $pages = $this->database->query(
            'SELECT namespace,slug,content FROM wiki_pages WHERE deleted_at IS NULL ORDER BY id'
        )->fetchAll();
        $existing = [];
        foreach ($pages as $page) {
            $path = ($page['namespace'] === '' ? '' : $page['namespace'] . ':') . $page['slug'];
            $existing[$path] = true;
        }
        $missing = [];
        foreach ($pages as $page) {
            foreach ($this->links->targets((string) $page['content']) as $target) {
                if (! isset($existing[$target])) $missing[$target] = ($missing[$target] ?? 0) + 1;
            }
        }
        ksort($missing);
        $result = [];
        foreach ($missing as $target => $references) $result[] = ['target_path' => $target, 'references' => $references];
        return $result;
    }

    /** @param array<string,mixed> $data */
    public function save(?int $id, array $data, int $authorId): int
    {
        $parameters = [
            'namespace' => $data['namespace'], 'slug' => $data['slug'], 'title' => $data['title'],
            'content' => $data['content'], 'status' => $data['status'], 'audience' => $data['audience'],
            'comments_enabled' => $data['comments_enabled'],
            'published_at' => $data['status'] === 'published' ? gmdate('Y-m-d H:i:s') : null,
        ];
        $this->database->beginTransaction();
        try {
            if ($id === null) {
                $parameters['author_id'] = $authorId;
                $statement = $this->database->prepare(
                    'INSERT INTO wiki_pages (namespace,slug,title,content,status,audience,comments_enabled,author_id,published_at,created_at,updated_at) '
                    . 'VALUES (:namespace,:slug,:title,:content,:status,:audience,:comments_enabled,:author_id,:published_at,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
                );
            } else {
                $existing = $this->locked($id);
                if ($existing === null) throw new RuntimeException('Wiki page not found.');
                $parameters['published_at'] = $data['status'] === 'published'
                    ? ($existing['published_at'] ?? gmdate('Y-m-d H:i:s'))
                    : null;
                $parameters['id'] = $id;
                $statement = $this->database->prepare(
                    'UPDATE wiki_pages SET namespace=:namespace,slug=:slug,title=:title,content=:content,status=:status,'
                    . 'audience=:audience,comments_enabled=:comments_enabled,published_at=:published_at,updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL'
                );
            }
            $statement->execute($parameters);
            $pageId = $id ?? (int) $this->database->lastInsertId();
            $this->insertRevision($pageId, $data, $parameters['published_at'], $authorId);
            $this->database->commit();
            return $pageId;
        } catch (PDOException $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($error->getCode() === '23000') throw new RuntimeException('That wiki path is already in use.', 0, $error);
            throw $error;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function revisions(int $pageId): array
    {
        $statement = $this->database->prepare(
            "SELECT r.id,r.revision_number,r.title,r.status,r.audience,r.created_at,COALESCE(u.username,'Deleted user') AS actor "
            . 'FROM wiki_page_revisions r LEFT JOIN users u ON u.id=r.actor_id WHERE r.wiki_page_id=:page_id ORDER BY r.revision_number DESC'
        );
        $statement->execute(['page_id' => $pageId]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function revision(int $pageId, int $revisionId): ?array
    {
        $statement = $this->database->prepare(
            "SELECT r.*,COALESCE(u.username,'Deleted user') AS actor FROM wiki_page_revisions r "
            . 'LEFT JOIN users u ON u.id=r.actor_id WHERE r.id=:id AND r.wiki_page_id=:page_id LIMIT 1'
        );
        $statement->execute(['id' => $revisionId, 'page_id' => $pageId]);
        $revision = $statement->fetch();
        return is_array($revision) ? $revision : null;
    }

    /** @return array{path:string,revision_number:int} */
    public function restore(int $pageId, int $revisionId, int $actorId, bool $canPublish): array
    {
        $this->database->beginTransaction();
        try {
            $page = $this->locked($pageId);
            if ($page === null) throw new RuntimeException('Wiki page not found.');
            $revision = $this->revision($pageId, $revisionId);
            if ($revision === null) throw new RuntimeException('Wiki revision not found.');
            if ($revision['status'] === 'published' && ! $canPublish) throw new RuntimeException('You do not have permission to restore a published revision.');
            $statement = $this->database->prepare(
                'UPDATE wiki_pages SET namespace=:namespace,slug=:slug,title=:title,content=:content,status=:status,'
                . 'audience=:audience,comments_enabled=:comments_enabled,published_at=:published_at,updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL'
            );
            $statement->execute([
                'namespace' => $page['namespace'], 'slug' => $page['slug'], 'title' => $revision['title'],
                'content' => $revision['content'], 'status' => $revision['status'], 'audience' => $revision['audience'],
                'comments_enabled' => $revision['comments_enabled'],
                'published_at' => $revision['published_at'], 'id' => $pageId,
            ]);
            $data = [
                'namespace' => $page['namespace'], 'slug' => $page['slug'], 'title' => $revision['title'],
                'content' => $revision['content'], 'status' => $revision['status'], 'audience' => $revision['audience'],
                'comments_enabled' => $revision['comments_enabled'],
            ];
            $number = $this->insertRevision($pageId, $data, $revision['published_at'], $actorId);
            $this->database->commit();
            return ['path' => ($page['namespace'] === '' ? '' : $page['namespace'] . ':') . $page['slug'], 'revision_number' => $number];
        } catch (PDOException $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($error->getCode() === '23000') throw new RuntimeException('The restored wiki path is already in use.', 0, $error);
            throw $error;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare("UPDATE wiki_pages SET namespace=CONCAT('__deleted__:',id),deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL");
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('Wiki page not found.');
    }

    /**
     * @param list<int> $ids
     * @return list<array{id:int,path:string}>
     */
    public function bulkChange(array $ids, string $action, int $actorId): array
    {
        if ($ids === [] || count($ids) > 500 || ! in_array($action, [
            'publish', 'draft', 'audience_public', 'audience_member', 'audience_vip', 'delete',
        ], true)) throw new RuntimeException('Invalid bulk Wiki action.');

        $changed = [];
        $this->database->beginTransaction();
        try {
            foreach ($ids as $id) {
                $page = $this->locked($id);
                if ($page === null) throw new RuntimeException('A selected Wiki page no longer exists.');
                $path = (string) $page['path'];
                if ($action === 'delete') {
                    $this->delete($id);
                    $changed[] = ['id' => $id, 'path' => $path];
                    continue;
                }

                $status = match ($action) {
                    'publish' => 'published',
                    'draft' => 'draft',
                    default => (string) $page['status'],
                };
                $audience = match ($action) {
                    'audience_public' => 'public',
                    'audience_member' => 'member',
                    'audience_vip' => 'vip',
                    default => (string) $page['audience'],
                };
                if ($status === $page['status'] && $audience === $page['audience']) continue;
                $publishedAt = $status === 'published'
                    ? ($page['published_at'] ?? gmdate('Y-m-d H:i:s'))
                    : null;
                $statement = $this->database->prepare(
                    'UPDATE wiki_pages SET status=:status,audience=:audience,published_at=:published_at,'
                    . 'updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL'
                );
                $statement->execute([
                    'status' => $status,
                    'audience' => $audience,
                    'published_at' => $publishedAt,
                    'id' => $id,
                ]);
                $this->insertRevision($id, [
                    'namespace' => $page['namespace'],
                    'slug' => $page['slug'],
                    'title' => $page['title'],
                    'content' => $page['content'],
                    'status' => $status,
                    'audience' => $audience,
                    'comments_enabled' => $page['comments_enabled'],
                ], $publishedAt, $actorId);
                $changed[] = ['id' => $id, 'path' => $path];
            }
            $this->database->commit();
            return $changed;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** @return array{0:string,1:string} */
    private function split(string $path): array
    {
        $parts = explode(':', $path);
        $slug = (string) array_pop($parts);
        return [implode(':', $parts), $slug];
    }

    /** @return array<string,mixed>|null */
    private function locked(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM wiki_pages WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
        $statement->execute(['id' => $id]);
        $page = $statement->fetch();
        return is_array($page) ? $this->withPath($page) : null;
    }

    /** @param array<string,mixed> $data */
    private function insertRevision(int $pageId, array $data, mixed $publishedAt, int $actorId): int
    {
        $number = $this->database->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM wiki_page_revisions WHERE wiki_page_id=:page_id');
        $number->execute(['page_id' => $pageId]);
        $revisionNumber = (int) $number->fetchColumn();
        $statement = $this->database->prepare(
            'INSERT INTO wiki_page_revisions (wiki_page_id,revision_number,namespace,slug,title,content,status,audience,comments_enabled,published_at,actor_id,created_at) '
            . 'VALUES (:page_id,:revision_number,:namespace,:slug,:title,:content,:status,:audience,:comments_enabled,:published_at,:actor_id,UTC_TIMESTAMP())'
        );
        $statement->execute([
            'page_id' => $pageId, 'revision_number' => $revisionNumber, 'namespace' => $data['namespace'],
            'slug' => $data['slug'], 'title' => $data['title'], 'content' => $data['content'],
            'status' => $data['status'], 'audience' => $data['audience'], 'comments_enabled' => $data['comments_enabled'],
            'published_at' => $publishedAt, 'actor_id' => $actorId,
        ]);
        return $revisionNumber;
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    private function withPath(array $page): array
    {
        $page['path'] = ($page['namespace'] === '' ? '' : $page['namespace'] . ':') . $page['slug'];
        return $page;
    }
}
