<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use Modules\News\src\AdminNewsController;
use Modules\News\src\NewsInput;
use Modules\News\src\NewsRepository;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Access\AccessAudience;
use NovaNuke\Core\Blocks\MarkdownRenderer;
use NovaNuke\Core\Database\Migrator;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Membership\MembershipManagerInterface;
use NovaNuke\Core\Modules\ModuleManifest;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\HtmlSanitizer;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;
use RuntimeException;

final class NewsTaxonomyEditingAcceptanceTest extends MySqlIntegrationTestCase
{
    private NewsRepository $news;
    private NewsInput $input;
    private SessionManager $session;
    private CsrfTokenManager $csrf;
    private AuthManager $auth;
    private AuthorizationService $authorization;
    private AdminNewsController $admin;
    private int $authorId;

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        (new Migrator($database))->run(dirname(__DIR__, 2) . '/database/migrations');
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/News/module.json'), true, 32, JSON_THROW_ON_ERROR);
        (new ModuleMigrator($database))->run(ModuleManifest::fromArray($manifest, dirname(__DIR__, 2) . '/modules/News'));
        $statement = $database->prepare('INSERT INTO permissions (name,slug,description,module_slug,created_at,updated_at) VALUES (:name,:slug,:description,"news",UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=UTC_TIMESTAMP()');
        $statement->execute(['name' => 'News Edit', 'slug' => 'news.edit', 'description' => 'News editing.']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->input = new NewsInput();
        $this->news = new NewsRepository($this->db(), new AccessAudience(new TaxonomyMembership()));
        $this->authorId = $this->createUser('taxonomy-author');
        $this->session = new SessionManager('novanuke_news_taxonomy', false);
        $this->csrf = new CsrfTokenManager($this->session);
        $events = new EventDispatcher();
        $this->auth = new AuthManager($this->db(), $this->session, $events);
        $this->authorization = new AuthorizationService($this->db());
        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        $translator->addNamespace('news', dirname(__DIR__, 2) . '/modules/News/language');
        $views = new ViewRenderer(dirname(__DIR__, 2) . '/resources/views', sys_get_temp_dir() . '/novanuke-news-taxonomy-' . bin2hex(random_bytes(4)), true, $translator);
        $views->addNamespace('admin-core', dirname(__DIR__, 2) . '/resources/views');
        $views->addNamespace('admin-news', dirname(__DIR__, 2) . '/modules/News/views/admin');
        foreach (['cms_name' => 'NovaNuke Test', 'cms_locale' => 'en', 'current_user' => null, 'blocks' => [], 'menus' => ['primary' => []], 'admin_navigation' => []] as $key => $value) $views->addGlobal($key, $value);
        $this->admin = new AdminNewsController($this->news, $this->input, $this->auth, $this->authorization, new ActivityLogger($this->db()), $events, $this->csrf, $this->session, $views, null, $translator);
    }

    public function testCategoryAndTopicEditPreservesIdentitySlugsAndArticleRelations(): void
    {
        $category = $this->news->saveTaxonomy('category', ['name' => 'Original category', 'slug' => 'original-category', 'description' => 'Old']);
        $topic = $this->news->saveTaxonomy('topic', ['name' => 'Original topic', 'slug' => 'original-topic', 'description' => 'Old']);
        $article = $this->news->save(null, $this->articleData($category, $topic), $this->authorId);
        $this->news->updateTaxonomy('category', $category, ['name' => 'Updated category', 'description' => 'New', 'parent_id' => null]);
        $this->news->updateTaxonomy('topic', $topic, ['name' => 'Updated topic', 'description' => 'New']);
        $updatedCategory = $this->news->taxonomy('category', $category);
        $updatedTopic = $this->news->taxonomy('topic', $topic);
        self::assertSame($category, (int) $updatedCategory['id']);
        self::assertSame('original-category', $updatedCategory['slug']);
        self::assertSame('Updated category', $updatedCategory['name']);
        self::assertSame($topic, (int) $updatedTopic['id']);
        self::assertSame('original-topic', $updatedTopic['slug']);
        $row = $this->news->article($article);
        self::assertSame($category, (int) $row['category_id']);
        self::assertSame($topic, (int) $row['topic_id']);
    }

    public function testCategoryParentChangeRejectsSelfAndDescendantAndAllowsValidParent(): void
    {
        $root = $this->news->saveTaxonomy('category', ['name' => 'Root', 'slug' => 'root', 'description' => null]);
        $child = $this->createCategoryWithParent('Child', 'child', $root);
        $target = $this->news->saveTaxonomy('category', ['name' => 'Target', 'slug' => 'target', 'description' => null]);
        try {
            $this->news->updateTaxonomy('category', $root, ['name' => 'Root', 'description' => null, 'parent_id' => $child]);
            self::fail('A descendant cannot become the parent.');
        } catch (RuntimeException) {
            self::assertNull($this->news->taxonomy('category', $root)['parent_id']);
        } finally {
            self::assertNull($this->news->taxonomy('category', $root)['parent_id']);
        }
        try {
            $this->news->updateTaxonomy('category', $child, ['name' => 'Child', 'description' => null, 'parent_id' => $child]);
            self::fail('Self-parent must be rejected.');
        } catch (RuntimeException) {
            $this->news->updateTaxonomy('category', $child, ['name' => 'Child', 'description' => null, 'parent_id' => $target]);
        }
        self::assertSame($target, (int) $this->news->taxonomy('category', $child)['parent_id']);
    }

    public function testControllerAuthorizationCsrfAndSuccessfulCategoryUpdate(): void
    {
        self::assertSame(302, $this->admin->taxonomyEdit($this->request('GET', '/admin/news/taxonomy/category/1/edit')->withAttributes(['type' => 'category', 'id' => '1']))->status());
        $withoutPermission = $this->createUser('taxonomy-no-permission');
        $this->loginAs($withoutPermission);
        self::assertSame(403, $this->admin->taxonomyEdit($this->request('GET', '/admin/news/taxonomy/category/1/edit')->withAttributes(['type' => 'category', 'id' => '1']))->status());
        $editor = $this->createUser('taxonomy-editor');
        $this->grant($editor, 'news.edit');
        $this->loginAs($editor);
        $category = $this->news->saveTaxonomy('category', ['name' => 'Before', 'slug' => 'before', 'description' => 'Before']);
        $route = ['type' => 'category', 'id' => (string) $category];
        self::assertSame(200, $this->admin->taxonomyEdit($this->request('GET', '/admin/news/taxonomy/category/' . $category . '/edit')->withAttributes($route))->status());
        $data = ['name' => 'After', 'slug' => 'before', 'description' => 'After'];
        self::assertSame(419, $this->admin->taxonomyUpdate($this->request('POST', '/admin/news/taxonomy/category/' . $category . '/update', $data)->withAttributes($route))->status());
        $data['_token'] = 'invalid';
        self::assertSame(419, $this->admin->taxonomyUpdate($this->request('POST', '/admin/news/taxonomy/category/' . $category . '/update', $data)->withAttributes($route))->status());
        $data['_token'] = $this->csrf->token();
        self::assertSame(303, $this->admin->taxonomyUpdate($this->request('POST', '/admin/news/taxonomy/category/' . $category . '/update', $data)->withAttributes($route))->status());
        self::assertSame('After', $this->news->taxonomy('category', $category)['name']);
        self::assertSame(404, $this->admin->taxonomyEdit($this->request('GET', '/admin/news/taxonomy/category/999999/edit')->withAttributes(['type' => 'category', 'id' => '999999']))->status());
        $topic = $this->news->saveTaxonomy('topic', ['name' => 'Topic before', 'slug' => 'topic-before', 'description' => null]);
        $topicRoute = ['type' => 'topic', 'id' => (string) $topic];
        $topicData = ['name' => 'Topic after', 'slug' => 'topic-before', 'description' => 'Updated', '_token' => $this->csrf->token()];
        self::assertSame(303, $this->admin->taxonomyUpdate($this->request('POST', '/admin/news/taxonomy/topic/' . $topic . '/update', $topicData)->withAttributes($topicRoute))->status());
        self::assertSame('Topic after', $this->news->taxonomy('topic', $topic)['name']);
    }

    public function testInvalidInputMissingIdsAndSlugChangesDoNotPersist(): void
    {
        $category = $this->news->saveTaxonomy('category', ['name' => 'Stable', 'slug' => 'stable', 'description' => 'Stable']);
        try {
            $this->news->updateTaxonomy('category', 999999, ['name' => 'Missing', 'description' => null, 'parent_id' => null]);
            self::fail('Missing taxonomy IDs must be rejected.');
        } catch (RuntimeException) {
            self::assertSame('Stable', $this->news->taxonomy('category', $category)['name']);
        } finally {
            self::assertSame('Stable', $this->news->taxonomy('category', $category)['name']);
        }
        try {
            $this->input->taxonomyUpdate(['name' => '', 'description' => null], 'stable', true);
            self::fail('Empty taxonomy names must be rejected.');
        } catch (RuntimeException) {
            self::assertSame('Stable', $this->news->taxonomy('category', $category)['name']);
        }
        try {
            $this->input->taxonomyUpdate(['name' => 'Changed', 'slug' => 'changed', 'description' => null], 'stable', true);
            self::fail('Changed taxonomy slugs must be rejected.');
        } catch (RuntimeException) {
            self::assertSame('Stable', $this->news->taxonomy('category', $category)['name']);
        }
    }

    public function testExistingCreationPublicFilteringAndAdminEscapingRemainFunctional(): void
    {
        $category = $this->news->saveTaxonomy('category', ['name' => 'Safe <Category>', 'slug' => 'safe-category', 'description' => '<script>alert(1)</script>']);
        $topic = $this->news->saveTaxonomy('topic', ['name' => 'Topic', 'slug' => 'topic', 'description' => null]);
        $id = $this->news->save(null, $this->articleData($category, $topic, 'safe-story'), $this->authorId);
        self::assertSame($id, (int) $this->news->publicArticles(1, 'safe-category')['items'][0]['id']);
        $editor = $this->createUser('taxonomy-viewer'); $this->grant($editor, 'news.edit'); $this->loginAs($editor);
        $response = $this->admin->index($this->request('GET', '/admin/news'));
        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->content());
        self::assertStringContainsString('safe-category', $response->content());
    }

    /** @return array<string,mixed> */
    private function articleData(int $category, int $topic, string $slug = 'taxonomy-story'): array
    {
        return $this->input->article(['title' => 'Taxonomy story', 'slug' => $slug, 'content' => 'Body', 'summary' => 'Summary', 'summary_format' => 'html', 'content_format' => 'html', 'status' => 'published', 'audience' => 'public', 'published_at' => gmdate('Y-m-d\\TH:i', time() - 60), 'category_id' => $category, 'topic_id' => $topic, 'tags' => ''], true);
    }

    private function createCategoryWithParent(string $name, string $slug, int $parent): int
    {
        $id = $this->news->saveTaxonomy('category', ['name' => $name, 'slug' => $slug, 'description' => null]);
        $this->db()->prepare('UPDATE news_categories SET parent_id=:parent WHERE id=:id')->execute(['parent' => $parent, 'id' => $id]);
        return $id;
    }

    private function createUser(string $name): int
    {
        $this->db()->prepare('INSERT INTO users (username,email,password_hash,status,auth_version,created_at,updated_at) VALUES (:u,:e,:p,"active",1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['u' => $name, 'e' => $name . '@example.test', 'p' => password_hash('not-used', PASSWORD_DEFAULT)]);
        return (int) $this->db()->lastInsertId();
    }

    private function grant(int $userId, string $permission): void
    {
        $this->db()->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id,created_at) SELECT r.id,p.id,UTC_TIMESTAMP() FROM roles r INNER JOIN permissions p ON p.slug=:permission WHERE r.slug="editor"')->execute(['permission' => $permission]);
        $this->db()->prepare('INSERT IGNORE INTO user_roles (user_id,role_id,created_at) SELECT :user,id,UTC_TIMESTAMP() FROM roles WHERE slug="editor"')->execute(['user' => $userId]);
    }

    private function loginAs(int $userId): void { $this->session->put('_auth_user_id', $userId); $this->session->put('_auth_version', 1); }

    private function request(string $method, string $path, array $input = []): Request { return new Request($method, $path, [], $input, [], [], ['REMOTE_ADDR' => '127.0.0.1']); }
}

final class TaxonomyMembership implements MembershipManagerInterface
{
    public function status(int $userId): array { return ['is_vip' => false]; }
    public function isVip(int $userId): bool { return false; }
    public function nextScheduled(int $userId): ?array { return null; }
    public function plans(): array { return []; }
    public function assign(int $userId, string $planKey, int $actorId, ?string $note = null): array { return []; }
    public function grantDays(int $userId, int $days, int $actorId, ?string $note = null): array { return []; }
    public function extendDays(int $userId, int $days, int $actorId, ?string $note = null): array { return []; }
    public function schedule(int $userId, string $planKey, string $startsAt, int $actorId, ?string $note = null): array { return []; }
    public function cancelScheduled(int $userId, ?int $actorId = null): bool { return false; }
    public function revoke(int $userId, ?int $actorId = null, string $source = 'manual'): void {}
}
