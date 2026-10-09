<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use Modules\News\src\AdminNewsController;
use Modules\News\src\NewsInput;
use Modules\News\src\NewsRepository;
use Modules\News\src\NewsSearchProvider;
use Modules\News\src\PublicNewsController;
use Modules\News\src\RssController;
use Modules\News\src\RssFeedBuilder;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Access\AccessAudience;
use NovaNuke\Core\Blocks\MarkdownRenderer;
use NovaNuke\Core\Config\ConfigRepository;
use NovaNuke\Core\Content\ContentFormat;
use NovaNuke\Core\Content\ContentProfile;
use NovaNuke\Core\Content\ContentRenderer;
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
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;
use RuntimeException;

final class NewsFunctionalAcceptanceTest extends MySqlIntegrationTestCase
{
    private NewsRepository $news;
    private NewsInput $input;
    private FakeNewsMembership $membership;
    private int $authorId;
    private SessionManager $session;
    private CsrfTokenManager $csrf;
    private AuthManager $auth;
    private AuthorizationService $authorization;
    private AdminNewsController $admin;
    private PublicNewsController $public;
    private RssController $rss;

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        (new Migrator($database))->run(dirname(__DIR__, 2) . '/database/migrations');
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/News/module.json'), true, 32, JSON_THROW_ON_ERROR);
        (new ModuleMigrator($database))->run(ModuleManifest::fromArray($manifest, dirname(__DIR__, 2) . '/modules/News'));
        $statement = $database->prepare(
            'INSERT INTO permissions (name,slug,description,module_slug,created_at,updated_at) '
            . 'VALUES (:name,:slug,:description,:module,UTC_TIMESTAMP(),UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),module_slug=VALUES(module_slug),updated_at=UTC_TIMESTAMP()'
        );
        foreach (['news.edit', 'news.publish'] as $permission) {
            $statement->execute([
                'name' => ucwords(str_replace(['.', '-', '_'], ' ', $permission)), 'slug' => $permission,
                'description' => 'Permission provided by News.', 'module' => 'news',
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->membership = new FakeNewsMembership();
        $audience = new AccessAudience($this->membership);
        $this->input = new NewsInput();
        $this->news = new NewsRepository($this->db(), $audience);
        $this->authorId = $this->createUser('news-author');
        $this->session = new SessionManager('novanuke_news_acceptance', false);
        $this->csrf = new CsrfTokenManager($this->session);
        $events = new EventDispatcher();
        $this->auth = new AuthManager($this->db(), $this->session, $events);
        $this->authorization = new AuthorizationService($this->db());
        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        $translator->addNamespace('news', dirname(__DIR__, 2) . '/modules/News/language');
        $views = new ViewRenderer(dirname(__DIR__, 2) . '/resources/views', sys_get_temp_dir() . '/novanuke-news-acceptance-' . bin2hex(random_bytes(4)), true, $translator);
        $views->addNamespace('admin-core', dirname(__DIR__, 2) . '/resources/views');
        $views->addNamespace('news', dirname(__DIR__, 2) . '/modules/News/views');
        $views->addNamespace('admin-news', dirname(__DIR__, 2) . '/modules/News/views/admin');
        foreach ([
            'cms_name' => 'NovaNuke Test', 'cms_url' => 'https://novanuke.test', 'cms_locale' => 'en', 'cms_date_format' => 'Y-m-d', 'cms_timezone' => 'UTC',
            'current_user' => null, 'blocks' => [], 'menus' => ['primary' => []], 'admin_navigation' => [],
            'cms_version' => '0.4.0-beta.1',
        ] as $key => $value) $views->addGlobal($key, $value);
        $renderer = new ContentRenderer(new HtmlSanitizer(), new MarkdownRenderer(new HtmlSanitizer()));
        $this->admin = new AdminNewsController($this->news, $this->input, $this->auth, $this->authorization, new ActivityLogger($this->db()), $events, $this->csrf, $this->session, $views, null, $translator);
        $this->public = new PublicNewsController($this->news, $this->session, $views, $renderer, null, $this->csrf, $this->auth, $this->authorization, $translator);
        $settings = new SettingsRepository($this->db());
        $settings->setMany([
            'site.name' => ['value' => 'NovaNuke Test', 'type' => 'string', 'group' => 'general'],
            'site.url' => ['value' => 'https://novanuke.test', 'type' => 'string', 'group' => 'general'],
            'site.locale' => ['value' => 'en', 'type' => 'string', 'group' => 'general'],
        ]);
        $this->rss = new RssController($this->news, new RssFeedBuilder(), $settings, new ConfigRepository(['app' => ['name' => 'NovaNuke', 'url' => 'https://novanuke.test', 'locale' => 'en']]), $renderer);
    }

    public function testCreateEditPreservesIdentityTaxonomyAndTags(): void
    {
        $category = $this->news->saveTaxonomy('category', ['name' => 'Science', 'slug' => 'science', 'description' => 'Science']);
        $topic = $this->news->saveTaxonomy('topic', ['name' => 'Research', 'slug' => 'research', 'description' => 'Research']);
        $data = $this->data('science-story', 'Science story', 'First body');
        $data['category_id'] = $category; $data['topic_id'] = $topic; $data['tags'] = ['ai' => 'AI', 'research' => 'Research'];
        $id = $this->news->save(null, $data, $this->authorId);
        $before = $this->news->article($id);
        $edit = $this->data('science-story', 'Updated story', 'Updated body');
        $edit['category_id'] = $category; $edit['topic_id'] = $topic; $edit['tags'] = ['ai' => 'AI', 'updated' => 'Updated'];
        $this->news->save($id, $edit, $this->authorId);
        $after = $this->news->article($id);
        self::assertSame($id, (int) $after['id']);
        self::assertSame($before['author_id'], $after['author_id']);
        self::assertSame('Updated body', $after['content']);
        self::assertSame('AI, Updated', $after['tags']);
        self::assertSame(2, (int) $this->db()->query("SELECT COUNT(*) FROM news_article_tags WHERE article_id={$id}")->fetchColumn());
    }

    public function testDuplicateSlugRollsBackArticleAndTags(): void
    {
        $first = $this->news->save(null, $this->data('same-slug', 'First', 'Keep first'), $this->authorId);
        $secondData = $this->data('other-slug', 'Second', 'Keep second'); $secondData['tags'] = ['old' => 'Old'];
        $second = $this->news->save(null, $secondData, $this->authorId);
        $replacement = $this->data('same-slug', 'Rejected', 'Must not persist'); $replacement['tags'] = ['new' => 'New'];
        $this->expectException(RuntimeException::class);
        try { $this->news->save($second, $replacement, $this->authorId); }
        finally {
            $row = $this->news->article($second);
            self::assertSame('other-slug', $row['slug']); self::assertSame('Keep second', $row['content']); self::assertSame('Old', $row['tags']);
            self::assertNotSame($first, $second);
            self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM news_article_tags nt INNER JOIN news_tags t ON t.id=nt.tag_id WHERE nt.article_id={$second} AND t.slug='new'")->fetchColumn());
        }
    }

    public function testDraftScheduledPublishedAndSoftDeletedVisibility(): void
    {
        $draft = $this->news->save(null, $this->data('draft-story', 'Draft', 'Draft body'), $this->authorId);
        $future = $this->data('future-story', 'Future', 'Future body'); $future['status'] = 'scheduled'; $future['published_at'] = gmdate('Y-m-d H:i:s', time() + 3600);
        $scheduled = $this->news->save(null, $future, $this->authorId);
        $live = $this->data('live-story', 'Live', 'Live body'); $live['status'] = 'published'; $live['published_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $published = $this->news->save(null, $live, $this->authorId);
        self::assertNull($this->news->publicArticle('draft-story')); self::assertNull($this->news->publicArticle('future-story'));
        self::assertSame($published, (int) $this->news->publicArticle('live-story')['id']);
        self::assertSame(1, $this->news->publicArticles(1)['total']);
        self::assertSame($draft, (int) $this->news->article($draft)['id']); self::assertSame($scheduled, (int) $this->news->article($scheduled)['id']);
        $this->news->delete($published);
        self::assertNull($this->news->publicArticle('live-story')); self::assertNull($this->news->article($published));
    }

    public function testMemberVipAndUnsupportedRoleAudienceBoundaries(): void
    {
        $memberData = $this->data('members-only', 'Members only', 'Member body'); $memberData['status'] = 'published'; $memberData['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $memberData['audience'] = 'member';
        $memberId = $this->news->save(null, $memberData, $this->authorId);
        $vipData = $this->data('vip-only', 'VIP only', 'VIP body'); $vipData['status'] = 'published'; $vipData['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $vipData['audience'] = 'vip';
        $vipId = $this->news->save(null, $vipData, $this->authorId);
        self::assertFalse($this->news->canView($this->news->article($memberId), null));
        self::assertFalse($this->news->canView($this->news->article($vipId), 4));
        $member = $this->createUser('news-member');
        self::assertTrue($this->news->canView($this->news->article($memberId), $member));
        self::assertFalse($this->news->canView($this->news->article($vipId), $member));
        $this->membership->vip = true; self::assertTrue($this->news->canView($this->news->article($vipId), $member));
        $invalid = $this->data('role-audience', 'Role audience', 'Body'); $invalid['audience'] = 'role';
        $this->expectException(RuntimeException::class); $this->input->article($invalid, true);
    }

    public function testTaxonomyValidationAndDuplicateTaxonomyRollback(): void
    {
        $category = $this->news->saveTaxonomy('category', ['name' => 'Local', 'slug' => 'local', 'description' => null]);
        $topic = $this->news->saveTaxonomy('topic', ['name' => 'Events', 'slug' => 'events', 'description' => null]);
        self::assertSame($category, (int) $this->news->categories()[1]['id']);
        self::assertSame($topic, (int) $this->news->topics()[1]['id']);
        $data = $this->data('bad-taxonomy', 'Bad taxonomy', 'Body'); $data['category_id'] = 999999999;
        $this->expectException(RuntimeException::class);
        try { $this->news->save(null, $data, $this->authorId); }
        finally { self::assertNull($this->news->publicArticle('bad-taxonomy')); }
        $this->expectException(RuntimeException::class);
        $this->news->saveTaxonomy('category', ['name' => 'Duplicate', 'slug' => 'local', 'description' => null]);
    }

    public function testFailedTaxonomyAndTagUpdateLeavesOriginalState(): void
    {
        $category = $this->news->saveTaxonomy('category', ['name' => 'Updates', 'slug' => 'updates', 'description' => null]);
        $data = $this->data('rollback-story', 'Original', 'Original body'); $data['category_id'] = $category; $data['tags'] = ['stable' => 'Stable'];
        $id = $this->news->save(null, $data, $this->authorId);
        $other = $this->news->save(null, $this->data('occupied-slug', 'Occupied', 'Occupied body'), $this->authorId);
        $replacement = $this->data('occupied-slug', 'Changed', 'Changed body'); $replacement['category_id'] = $category; $replacement['tags'] = ['new' => 'New'];
        $this->expectException(RuntimeException::class);
        try { $this->news->save($id, $replacement, $this->authorId); }
        finally {
            $row = $this->news->article($id);
            self::assertSame('Original', $row['title']); self::assertSame('Original body', $row['content']); self::assertSame('Stable', $row['tags']); self::assertNotSame($id, $other);
            self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM news_tags WHERE slug='new'")->fetchColumn());
        }
    }

    public function testPublicControllerRendersSafeContentAndCountsViewsOnce(): void
    {
        $data = $this->data('safe-news', 'Safe news', '<script>alert(1)</script><p onclick="bad()">Hello <strong>world</strong></p>');
        $data['summary'] = '<img src=x onerror=bad()>Summary'; $data['status'] = 'published'; $data['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $data['comments_enabled'] = '0';
        $id = $this->news->save(null, $data, $this->authorId);
        $request = (new Request('GET', '/news/safe-news'))->withAttributes(['slug' => 'safe-news']);
        $response = $this->public->show($request);
        self::assertSame(200, $response->status()); self::assertStringNotContainsString('<script', $response->content()); self::assertStringNotContainsString('onclick=', $response->content()); self::assertStringNotContainsString('onerror=', $response->content()); self::assertStringContainsString('<strong>world</strong>', $response->content());
        self::assertSame(1, (int) $this->db()->query("SELECT view_count FROM news_articles WHERE id={$id}")->fetchColumn());
        $this->public->show($request); self::assertSame(1, (int) $this->db()->query("SELECT view_count FROM news_articles WHERE id={$id}")->fetchColumn());
    }

    public function testPublicListingRssSearchSitemapAndCommentsBoundaries(): void
    {
        $live = $this->data('rss-public', 'RSS public', 'Searchable body'); $live['summary'] = 'Search summary'; $live['status'] = 'published'; $live['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $live['comments_enabled'] = '1';
        $id = $this->news->save(null, $live, $this->authorId);
        $private = $this->data('rss-private', 'RSS private', 'Private body'); $private['status'] = 'published'; $private['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $private['audience'] = 'member';
        $this->news->save(null, $private, $this->authorId);
        $listing = $this->public->index(new Request('GET', '/news'));
        self::assertSame(200, $listing->status()); self::assertStringContainsString('RSS public', $listing->content()); self::assertStringNotContainsString('RSS private', $listing->content());
        $rss = $this->rss->feed(); self::assertSame(200, $rss->status()); self::assertSame('application/rss+xml; charset=UTF-8', $rss->header('Content-Type')); self::assertStringContainsString('<title>RSS public</title>', $rss->content()); self::assertStringNotContainsString('RSS private', $rss->content());
        $search = (new NewsSearchProvider($this->db(), new AccessAudience($this->membership)))->search(new \NovaNuke\Core\Search\SearchQuery('Searchable', 10, null));
        self::assertSame(1, $search->total); self::assertSame('/news/rss-public', $search->items[0]->url);
        self::assertSame('rss-public', $this->news->sitemapEntries()[0]['slug']); self::assertTrue($this->news->acceptsComments($id, null));
        $this->news->delete($id); self::assertFalse($this->news->acceptsComments($id, null));
    }

    public function testAdminAuthorizationCsrfPublishEditDeleteAndValidation(): void
    {
        self::assertSame(302, $this->admin->create()->status());
        $user = $this->createUser('news-no-permission'); $this->loginAs($user); self::assertSame(403, $this->admin->index(new Request('GET', '/admin/news'))->status());
        $editor = $this->createUser('news-editor'); $this->grant($editor, 'editor', 'news.edit'); $this->loginAs($editor);
        self::assertSame(419, $this->admin->save($this->request('POST', '/admin/news/save', $this->httpData($this->data('csrf-news', 'CSRF', 'Body'))))->status());
        $draft = $this->data('draft-by-editor', 'Draft', 'Draft body'); $draft['_token'] = 'invalid'; self::assertSame(419, $this->admin->save($this->request('POST', '/admin/news/save', $this->httpData($draft)))->status());
        $draft['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->save($this->request('POST', '/admin/news/save', $this->httpData($draft)))->status());
        $this->loginAs($editor); $published = $this->data('published-by-editor', 'Published', 'Body'); $published['status'] = 'published'; $published['_token'] = $this->csrf->token();
        self::assertSame(422, $this->admin->save($this->request('POST', '/admin/news/save', $this->httpData($published)))->status());
        $admin = $this->createUser('news-admin'); $this->grant($admin, 'super-administrator', 'news.edit'); $this->grant($admin, 'super-administrator', 'news.publish'); $this->loginAs($admin);
        $valid = $this->data('controller-news', 'Before', 'Before body'); $valid['status'] = 'published'; $valid['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->save($this->request('POST', '/admin/news/save', $this->httpData($valid)))->status());
        $id = (int) $this->db()->query("SELECT id FROM news_articles WHERE slug='controller-news'")->fetchColumn();
        $edit = $this->data('controller-news', 'After', 'After body'); $edit['id'] = $id; $edit['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->save($this->request('POST', '/admin/news/save', $this->httpData($edit)))->status()); self::assertSame('After', $this->news->article($id)['title']);
        $delete = $this->request('POST', '/admin/news/' . $id . '/delete', ['_token' => $this->csrf->token(), 'confirm_delete' => '1', 'return_to' => '/news'])->withAttributes(['id' => (string) $id]); self::assertSame(303, $this->admin->delete($delete)->status()); self::assertNull($this->news->article($id));
        $invalid = ['title' => '', 'slug' => 'invalid-news', 'content' => 'Body', 'status' => 'draft', 'audience' => 'public', '_token' => $this->csrf->token()];
        self::assertSame(422, $this->admin->save($this->request('POST', '/admin/news/save', $invalid))->status());
    }

    public function testAdminTaxonomyAndBulkActionsEnforcePermissionsAndState(): void
    {
        $editor = $this->createUser('news-bulk-editor'); $this->grant($editor, 'editor', 'news.edit'); $this->loginAs($editor);
        $taxonomy = ['name' => 'Politics', 'slug' => 'politics', 'description' => 'Politics', '_token' => $this->csrf->token()];
        $categoryResponse = $this->admin->taxonomy($this->request('POST', '/admin/news/taxonomy/category', $taxonomy)->withAttributes(['type' => 'category'])); self::assertSame(303, $categoryResponse->status());
        $topicResponse = $this->admin->taxonomy($this->request('POST', '/admin/news/taxonomy/topic', ['name' => 'Policy', 'slug' => 'policy', 'description' => '', '_token' => $this->csrf->token()])->withAttributes(['type' => 'topic'])); self::assertSame(303, $topicResponse->status());
        $one = $this->news->save(null, $this->data('bulk-one', 'Bulk one', 'One'), $this->authorId); $two = $this->news->save(null, $this->data('bulk-two', 'Bulk two', 'Two'), $this->authorId);
        $bulk = ['ids' => [$one, $two], 'bulk_action' => 'publish', '_token' => $this->csrf->token()]; self::assertSame(403, $this->admin->bulk($this->request('POST', '/admin/news/bulk', $bulk))->status());
        $admin = $this->createUser('news-bulk-admin'); $this->grant($admin, 'super-administrator', 'news.edit'); $this->grant($admin, 'super-administrator', 'news.publish'); $this->loginAs($admin);
        $bulk['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->bulk($this->request('POST', '/admin/news/bulk', $bulk))->status());
        self::assertSame(2, (int) $this->db()->query("SELECT COUNT(*) FROM news_articles WHERE id IN ({$one},{$two}) AND status='published'")->fetchColumn());
        $audience = ['ids' => [$one, $two], 'bulk_action' => 'member', '_token' => $this->csrf->token()]; self::assertSame(303, $this->admin->bulk($this->request('POST', '/admin/news/bulk', $audience))->status());
        self::assertSame(2, (int) $this->db()->query("SELECT COUNT(*) FROM news_articles WHERE id IN ({$one},{$two}) AND audience='member'")->fetchColumn());
        $delete = ['ids' => [$one, $two], 'bulk_action' => 'delete', '_token' => $this->csrf->token()]; self::assertSame(303, $this->admin->bulk($this->request('POST', '/admin/news/bulk', $delete))->status());
        self::assertSame(2, (int) $this->db()->query("SELECT COUNT(*) FROM news_articles WHERE id IN ({$one},{$two}) AND deleted_at IS NOT NULL")->fetchColumn());
    }

    public function testMarkdownRendererPreservesFormattingAndRemovesUnsafeMarkup(): void
    {
        $renderer = new ContentRenderer(new HtmlSanitizer(), new MarkdownRenderer(new HtmlSanitizer()));
        $html = $renderer->render('**good** [bad](javascript:alert(1)) <script>x</script>', ContentFormat::Markdown, ContentProfile::FullContent);
        self::assertStringContainsString('<strong>good</strong>', $html); self::assertStringNotContainsString('javascript:', strtolower($html)); self::assertStringNotContainsString('<script', strtolower($html));
    }

    /** @return array<string,mixed> */
    private function data(string $slug, string $title, string $content): array
    {
        return $this->input->article(['title' => $title, 'slug' => $slug, 'content' => $content, 'summary' => 'Summary', 'summary_format' => 'html', 'content_format' => 'html', 'status' => 'draft', 'audience' => 'public', 'tags' => '', 'comments_enabled' => '0'], true);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function httpData(array $data): array
    {
        if (is_array($data['tags'] ?? null)) $data['tags'] = implode(', ', $data['tags']);
        return $data;
    }

    private function createUser(string $name): int
    {
        $this->db()->prepare('INSERT INTO users (username,email,password_hash,status,auth_version,created_at,updated_at) VALUES (:u,:e,:p,"active",1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['u' => $name, 'e' => $name . '@example.test', 'p' => password_hash('not-used', PASSWORD_DEFAULT)]);
        return (int) $this->db()->lastInsertId();
    }

    private function roleId(string $slug): int
    {
        $statement = $this->db()->prepare('SELECT id FROM roles WHERE slug=:slug'); $statement->execute(['slug' => $slug]); return (int) $statement->fetchColumn();
    }

    private function grant(int $userId, string $roleSlug, string $permission): void
    {
        $role = $this->roleId($roleSlug);
        $this->db()->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id,created_at) SELECT :role,id,UTC_TIMESTAMP() FROM permissions WHERE slug=:permission')->execute(['role' => $role, 'permission' => $permission]);
        $this->db()->prepare('INSERT IGNORE INTO user_roles (user_id,role_id,created_at) VALUES (:user,:role,UTC_TIMESTAMP())')->execute(['user' => $userId, 'role' => $role]);
    }

    private function loginAs(int $userId): void { $this->session->put('_auth_user_id', $userId); $this->session->put('_auth_version', 1); }

    private function request(string $method, string $path, array $input): Request { return new Request($method, $path, [], $input, [], [], ['REMOTE_ADDR' => '127.0.0.1']); }
}

final class FakeNewsMembership implements MembershipManagerInterface
{
    public bool $vip = false;
    public function status(int $userId): array { return ['is_vip' => $this->vip]; }
    public function isVip(int $userId): bool { return $this->vip; }
    public function nextScheduled(int $userId): ?array { return null; }
    public function plans(): array { return []; }
    public function assign(int $userId, string $planKey, int $actorId, ?string $note = null): array { return []; }
    public function grantDays(int $userId, int $days, int $actorId, ?string $note = null): array { return []; }
    public function extendDays(int $userId, int $days, int $actorId, ?string $note = null): array { return []; }
    public function schedule(int $userId, string $planKey, string $startsAt, int $actorId, ?string $note = null): array { return []; }
    public function cancelScheduled(int $userId, ?int $actorId = null): bool { return false; }
    public function revoke(int $userId, ?int $actorId = null, string $source = 'manual'): void {}
}
