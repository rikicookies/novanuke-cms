<?php

declare(strict_types=1);

namespace Modules\Pages\src;

use NovaNuke\Core\Membership\MembershipManagerInterface;
use PDO;
use RuntimeException;

final class PageRepository
{
    public function __construct(
        private readonly PDO $database,
        private readonly MembershipManagerInterface $memberships,
    )
    {
    }

    public function roles(): array { return $this->database->query("SELECT id,name,slug FROM roles WHERE slug<>'guest' ORDER BY name")->fetchAll(); }

    public function adminPages(): array
    {
        return $this->database->query('SELECT p.id,p.title,p.slug,p.status,p.access_type,p.template,p.published_at,p.updated_at,u.username,parent.title AS parent_title FROM pages p INNER JOIN users u ON u.id=p.author_id LEFT JOIN pages parent ON parent.id=p.parent_id WHERE p.deleted_at IS NULL ORDER BY p.updated_at DESC,p.id DESC')->fetchAll();
    }

    public function parentOptions(?int $exclude = null): array
    {
        $statement = $this->database->prepare('SELECT id,title FROM pages WHERE deleted_at IS NULL AND (:exclude IS NULL OR id<>:exclude_id) ORDER BY title');
        $statement->bindValue(':exclude', $exclude, $exclude === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':exclude_id', $exclude ?? 0, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function page(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM pages WHERE id=:id AND deleted_at IS NULL');
        $statement->execute(['id' => $id]);
        $page = $statement->fetch();
        if (! is_array($page)) return null;
        $page['role_ids'] = $this->roleIds($id);
        return $page;
    }

    /** @param array<string,mixed> $data */
    public function save(?int $id, array $data, int $authorId): int
    {
        $roles = $data['role_ids']; unset($data['role_ids']);
        $this->assertParent($id, $data['parent_id']);
        $this->assertRoles($roles);
        $this->database->beginTransaction();
        try {
            if ($id === null) {
                $sql = 'INSERT INTO pages (parent_id,author_id,title,slug,content,content_format,image_path,status,template,access_type,comments_enabled,show_in_directory,menu_title,seo_title,seo_description,published_at,created_at,updated_at) VALUES (:parent_id,:author_id,:title,:slug,:content,:content_format,:image_path,:status,:template,:access_type,:comments_enabled,:show_in_directory,:menu_title,:seo_title,:seo_description,:published_at,UTC_TIMESTAMP(),UTC_TIMESTAMP())';
                $data['author_id'] = $authorId;
            } else {
                if ($this->page($id) === null) throw new RuntimeException('Page not found.');
                $sql = 'UPDATE pages SET parent_id=:parent_id,title=:title,slug=:slug,content=:content,content_format=:content_format,image_path=:image_path,status=:status,template=:template,access_type=:access_type,comments_enabled=:comments_enabled,show_in_directory=:show_in_directory,menu_title=:menu_title,seo_title=:seo_title,seo_description=:seo_description,published_at=:published_at,updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL';
                $data['id'] = $id;
            }
            $statement = $this->database->prepare($sql); $statement->execute($data);
            $pageId = $id ?? (int) $this->database->lastInsertId();
            $this->syncRoles($pageId, $roles);
            $this->database->commit();
            return $pageId;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($error instanceof \PDOException && $error->getCode() === '23000') throw new RuntimeException('The page slug is already in use.', 0, $error);
            throw $error;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('UPDATE pages SET deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('Page not found.');
    }

    public function mediaUsage(string $publicPath): int
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM pages WHERE image_path=:path');
        $statement->execute(['path' => $publicPath]);
        return (int) $statement->fetchColumn();
    }

    public function publicPage(string $slug): ?array
    {
        $statement = $this->database->prepare("SELECT p.*,u.username,parent.title AS parent_title,parent.slug AS parent_slug,parent.access_type AS parent_access_type FROM pages p INNER JOIN users u ON u.id=p.author_id LEFT JOIN pages parent ON parent.id=p.parent_id AND parent.deleted_at IS NULL AND parent.published_at<=UTC_TIMESTAMP() AND parent.status IN ('published','scheduled') WHERE p.slug=:slug AND p.deleted_at IS NULL AND p.published_at<=UTC_TIMESTAMP() AND p.status IN ('published','scheduled') LIMIT 1");
        $statement->execute(['slug' => $slug]);
        $page = $statement->fetch();
        return is_array($page) ? $page : null;
    }

    public function directory(?int $userId): array
    {
        $pages = $this->database->query("SELECT id,parent_id,title,slug,menu_title,access_type FROM pages WHERE deleted_at IS NULL AND show_in_directory=1 AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled') ORDER BY title")->fetchAll();
        return array_values(array_filter($pages, fn (array $page): bool => $this->canView($page, $userId)));
    }

    /** @return array<int,array{slug:string,updated_at:string}> */
    public function sitemapEntries(): array
    {
        return $this->database->query(
            "SELECT slug,updated_at FROM pages WHERE access_type='public' AND deleted_at IS NULL "
            . "AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled') ORDER BY id"
        )->fetchAll();
    }

    public function canView(array $page, ?int $userId): bool
    {
        if ($page['access_type'] === 'public') return true;
        if ($userId === null) return false;
        if ($page['access_type'] === 'members') return true;
        if ($page['access_type'] === 'vip') return $this->memberships->isVip($userId);
        $statement = $this->database->prepare('SELECT COUNT(*) FROM page_role_access pra INNER JOIN user_roles ur ON ur.role_id=pra.role_id WHERE pra.page_id=:page AND ur.user_id=:user');
        $statement->execute(['page' => $page['id'], 'user' => $userId]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function acceptsComments(int $id, ?int $userId): bool
    {
        $statement = $this->database->prepare("SELECT id,access_type FROM pages WHERE id=:id AND deleted_at IS NULL AND comments_enabled=1 AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled')");
        $statement->execute(['id' => $id]);
        $page = $statement->fetch();
        return is_array($page) && $this->canView($page, $userId);
    }

    private function assertParent(?int $id, ?int $parentId): void
    {
        if ($parentId === null) return;
        if ($id !== null && $id === $parentId) throw new RuntimeException('A page cannot be its own parent.');
        $seen = []; $cursor = $parentId;
        while ($cursor !== null) {
            if (isset($seen[$cursor]) || ($id !== null && $cursor === $id)) throw new RuntimeException('Page hierarchy cannot contain a cycle.');
            $seen[$cursor] = true;
            $statement = $this->database->prepare('SELECT parent_id FROM pages WHERE id=:id AND deleted_at IS NULL');
            $statement->execute(['id' => $cursor]);
            $parent = $statement->fetch();
            if (! is_array($parent)) throw new RuntimeException('Selected parent page does not exist.');
            $cursor = $parent['parent_id'] === null ? null : (int) $parent['parent_id'];
        }
    }

    private function assertRoles(array $roleIds): void
    {
        $valid = array_map(static fn (array $role): int => (int) $role['id'], $this->roles());
        if (array_diff($roleIds, $valid) !== []) throw new RuntimeException('One or more selected roles are invalid.');
    }

    private function syncRoles(int $pageId, array $roleIds): void
    {
        $this->database->prepare('DELETE FROM page_role_access WHERE page_id=:id')->execute(['id' => $pageId]);
        $statement = $this->database->prepare('INSERT INTO page_role_access (page_id,role_id) VALUES (:page,:role)');
        foreach ($roleIds as $roleId) $statement->execute(['page' => $pageId, 'role' => $roleId]);
    }

    private function roleIds(int $pageId): array
    {
        $statement = $this->database->prepare('SELECT role_id FROM page_role_access WHERE page_id=:id');
        $statement->execute(['id' => $pageId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
