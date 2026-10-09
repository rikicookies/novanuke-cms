<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use Modules\Pages\src\AdminPagesController;
use Modules\Pages\src\PageInput;
use Modules\Pages\src\PageRepository;
use Modules\Pages\src\PublicPagesController;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Blocks\MarkdownRenderer;
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
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;
use RuntimeException;

final class PagesFunctionalAcceptanceTest extends MySqlIntegrationTestCase
{
    private PageRepository $pages;
    private PageInput $input;
    private FakePagesMembership $membership;
    private int $authorId;
    private int $memberRoleId;
    private SessionManager $session;
    private CsrfTokenManager $csrf;
    private AuthManager $auth;
    private AuthorizationService $authorization;
    private AdminPagesController $admin;
    private PublicPagesController $public;

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        (new Migrator($database))->run(dirname(__DIR__, 2) . '/database/migrations');
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/Pages/module.json'), true, 32, JSON_THROW_ON_ERROR);
        (new ModuleMigrator($database))->run(ModuleManifest::fromArray($manifest, dirname(__DIR__, 2) . '/modules/Pages'));
        $permissions = $database->prepare(
            'INSERT INTO permissions (name, slug, description, module_slug, created_at, updated_at) '
            . 'VALUES (:name, :slug, :description, :module_slug, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), '
            . 'module_slug = VALUES(module_slug), updated_at = UTC_TIMESTAMP()'
        );
        foreach (['pages.edit', 'pages.publish'] as $permission) {
            $permissions->execute([
                'name' => ucwords(str_replace(['.', '-', '_'], ' ', $permission)),
                'slug' => $permission,
                'description' => 'Permission provided by Pages.',
                'module_slug' => 'pages',
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->membership = new FakePagesMembership();
        $this->input = new PageInput();
        $this->pages = new PageRepository($this->db(), $this->membership);
        $this->authorId = $this->createUser('pages-author');
        $this->memberRoleId = $this->roleId('member');
        $this->session = new SessionManager('novanuke_pages_acceptance', false);
        $this->csrf = new CsrfTokenManager($this->session);
        $events = new EventDispatcher();
        $this->auth = new AuthManager($this->db(), $this->session, $events);
        $this->authorization = new AuthorizationService($this->db());

        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        $translator->addNamespace('pages', dirname(__DIR__, 2) . '/modules/Pages/language');
        $views = new ViewRenderer(dirname(__DIR__, 2) . '/resources/views', sys_get_temp_dir() . '/novanuke-pages-acceptance-' . bin2hex(random_bytes(4)), true, $translator);
        $views->addNamespace('pages', dirname(__DIR__, 2) . '/modules/Pages/views');
        $views->addNamespace('admin-pages', dirname(__DIR__, 2) . '/modules/Pages/views/admin');
        foreach ([
            'cms_name' => 'NovaNuke Test', 'cms_url' => 'https://novanuke.test', 'cms_date_format' => 'Y-m-d',
            'cms_timezone' => 'UTC', 'current_user' => null, 'blocks' => [], 'menus' => ['primary' => []],
            'admin_navigation' => [], 'cms_version' => '0.4.0-beta.1',
        ] as $key => $value) $views->addGlobal($key, $value);

        $renderer = new ContentRenderer(new HtmlSanitizer(), new MarkdownRenderer(new HtmlSanitizer()));
        $this->admin = new AdminPagesController(
            $this->pages, $this->input, $this->auth, $this->authorization, new ActivityLogger($this->db()),
            $events, $this->csrf, $this->session, $views, null, $translator,
        );
        $this->public = new PublicPagesController(
            $this->pages, $this->auth, $this->session, $views, $events, $renderer, null, $this->csrf,
            $this->authorization, $translator,
        );
    }

    public function testCreateEditPreservesIdentityAndExistingContent(): void
    {
        $pageId = $this->pages->save(null, $this->data('welcome', 'Welcome', 'First body'), $this->authorId);
        $before = $this->pages->page($pageId);
        $this->pages->save($pageId, $this->data('welcome', 'Welcome updated', 'Updated body'), $this->authorId);
        $after = $this->pages->page($pageId);

        self::assertSame($pageId, (int) $after['id']);
        self::assertSame('Welcome updated', $after['title']);
        self::assertSame('Updated body', $after['content']);
        self::assertSame($before['author_id'], $after['author_id']);
    }

    public function testDuplicateSlugRollsBackWithoutChangingExistingPage(): void
    {
        $first = $this->pages->save(null, $this->data('same-slug', 'First', 'Keep me'), $this->authorId);
        $second = $this->pages->save(null, $this->data('other-slug', 'Second', 'Original'), $this->authorId);

        $this->expectException(RuntimeException::class);
        try {
            $this->pages->save($second, $this->data('same-slug', 'Rejected', 'Must not persist'), $this->authorId);
        } finally {
            $row = $this->pages->page($second);
            self::assertSame('other-slug', $row['slug']);
            self::assertSame('Original', $row['content']);
            self::assertNotSame($first, $second);
        }
    }

    public function testParentHierarchyAndInvalidParentAreEnforced(): void
    {
        $parent = $this->pages->save(null, $this->data('parent', 'Parent', 'Parent body'), $this->authorId);
        $childData = $this->data('child', 'Child', 'Child body');
        $childData['parent_id'] = $parent;
        $child = $this->pages->save(null, $childData, $this->authorId);
        self::assertSame($parent, (int) $this->pages->page($child)['parent_id']);

        $invalid = $this->data('invalid-parent', 'Invalid', 'Invalid body');
        $invalid['parent_id'] = 999999999;
        $this->expectException(RuntimeException::class);
        $this->pages->save(null, $invalid, $this->authorId);
    }

    public function testDraftScheduledAndPublishedVisibility(): void
    {
        $draft = $this->pages->save(null, $this->data('draft', 'Draft', 'Draft body'), $this->authorId);
        $future = $this->data('future', 'Future', 'Future body');
        $future['status'] = 'scheduled';
        $future['published_at'] = gmdate('Y-m-d H:i:s', time() + 3600);
        $scheduled = $this->pages->save(null, $future, $this->authorId);
        $published = $this->data('published', 'Published', 'Published body');
        $published['status'] = 'published';
        $published['published_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $live = $this->pages->save(null, $published, $this->authorId);

        self::assertNull($this->pages->publicPage('draft'));
        self::assertNull($this->pages->publicPage('future'));
        self::assertSame($live, (int) $this->pages->publicPage('published')['id']);
        self::assertSame($draft, (int) $this->pages->page($draft)['id']);
        self::assertSame($scheduled, (int) $this->pages->page($scheduled)['id']);
    }

    public function testAudienceRoleAndVipRestrictions(): void
    {
        $member = $this->createUser('pages-member');
        $rolePage = $this->data('role-page', 'Role page', 'Role body');
        $rolePage['access_type'] = 'roles';
        $rolePage['role_ids'] = [$this->memberRoleId];
        $rolePage['status'] = 'published';
        $rolePage['published_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $roleId = $this->pages->save(null, $rolePage, $this->authorId);
        $vip = $this->data('vip-page', 'VIP page', 'VIP body');
        $vip['access_type'] = 'vip'; $vip['status'] = 'published'; $vip['published_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $vipId = $this->pages->save(null, $vip, $this->authorId);

        self::assertFalse($this->pages->canView($this->pages->page($roleId), null));
        self::assertFalse($this->pages->canView($this->pages->page($roleId), $member));
        $this->assignRole($member, 'member');
        self::assertTrue($this->pages->canView($this->pages->page($roleId), $member));
        self::assertFalse($this->pages->canView($this->pages->page($vipId), $member));
        $this->membership->vip = true;
        self::assertTrue($this->pages->canView($this->pages->page($vipId), $member));
    }

    public function testSoftDeleteRemovesPageFromPublicQueries(): void
    {
        $data = $this->data('delete-me', 'Delete me', 'Body');
        $data['status'] = 'published'; $data['published_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $id = $this->pages->save(null, $data, $this->authorId);
        self::assertNotNull($this->pages->publicPage('delete-me'));
        $this->pages->delete($id);
        self::assertNull($this->pages->publicPage('delete-me'));
        self::assertNull($this->pages->page($id));
    }

    public function testAdminAuthorizationCsrfAndValidSave(): void
    {
        $anonymous = $this->admin->index();
        self::assertSame(302, $anonymous->status());
        $editor = $this->createUser('pages-editor');
        $this->assignPermission($editor, 'pages.edit');
        $this->loginAs($editor);
        self::assertSame(200, $this->admin->index()->status());
        $missingCsrf = $this->admin->save($this->request('POST', '/admin/pages/save', $this->data('controller-page', 'Controller', 'Body')));
        self::assertSame(419, $missingCsrf->status());
        $data = $this->data('controller-page', 'Controller', 'Body');
        $data['_token'] = $this->csrf->token();
        $response = $this->admin->save($this->request('POST', '/admin/pages/save', $data));
        self::assertSame(303, $response->status());
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM pages WHERE slug='controller-page'")->fetchColumn());
    }

    public function testAnonymousAndEditorWithoutPermissionAreRejected(): void
    {
        self::assertSame(302, $this->admin->create()->status());

        $user = $this->createUser('pages-no-permission');
        $this->loginAs($user);
        self::assertSame(403, $this->admin->index()->status());
        self::assertSame(403, $this->admin->save($this->request('POST', '/admin/pages/save', []))->status());
    }

    public function testInvalidCsrfAndAuthorizedAdministratorEditAndDelete(): void
    {
        $administrator = $this->createUser('pages-administrator');
        $this->assignPermissionToRole($administrator, 'super-administrator', 'pages.edit');
        $this->assignPermissionToRole($administrator, 'super-administrator', 'pages.publish');
        $this->loginAs($administrator);

        $data = $this->data('controller-edit', 'Before', 'Before body');
        $data['_token'] = 'invalid-token';
        self::assertSame(419, $this->admin->save($this->request('POST', '/admin/pages/save', $data))->status());

        $data['_token'] = $this->csrf->token();
        $id = $this->admin->save($this->request('POST', '/admin/pages/save', $data))->header('Location');
        self::assertSame('/admin/pages', $id);
        $pageId = (int) $this->db()->query("SELECT id FROM pages WHERE slug='controller-edit'")->fetchColumn();

        $edit = $this->data('controller-edit', 'After', 'After body');
        $edit['id'] = $pageId;
        $edit['_token'] = $this->csrf->token();
        self::assertSame(303, $this->admin->save($this->request('POST', '/admin/pages/save', $edit))->status());
        self::assertSame('After', $this->pages->page($pageId)['title']);

        $delete = $this->request('POST', '/admin/pages/' . $pageId . '/delete', [
            '_token' => $this->csrf->token(), 'confirm_delete' => '1', 'return_to' => '/pages',
        ])->withAttributes(['id' => (string) $pageId]);
        self::assertSame(303, $this->admin->delete($delete)->status());
        self::assertNull($this->pages->page($pageId));
    }

    public function testPublishPermissionIsRequiredForNonDraftControllerSave(): void
    {
        $editor = $this->createUser('pages-draft-editor');
        $this->assignPermission($editor, 'pages.edit');
        $this->loginAs($editor);
        $data = $this->data('published-by-editor', 'Published', 'Body');
        $data['status'] = 'published'; $data['_token'] = $this->csrf->token();
        $response = $this->admin->save($this->request('POST', '/admin/pages/save', $data));
        self::assertSame(422, $response->status());
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM pages WHERE slug='published-by-editor'")->fetchColumn());
    }

    public function testPublicControllerRendersSanitizedPublishedContent(): void
    {
        $data = $this->data('safe-page', 'Safe page', '<script>alert(1)</script><p onclick="bad()">Hello <strong>world</strong></p>');
        $data['status'] = 'published'; $data['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $data['content_format'] = 'html';
        $this->pages->save(null, $data, $this->authorId);
        $response = $this->public->show((new Request('GET', '/pages/safe-page'))->withAttributes(['slug' => 'safe-page']));
        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('<script', $response->content());
        self::assertStringNotContainsString('onclick=', $response->content());
        self::assertStringContainsString('<strong>world</strong>', $response->content());
    }

    public function testPublicListingAndDetailEnforceDraftAndAudienceBoundaries(): void
    {
        $public = $this->data('listed-page', 'Listed page', 'Listed body');
        $public['status'] = 'published'; $public['published_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $this->pages->save(null, $public, $this->authorId);

        $members = $this->data('members-page', 'Members page', 'Secret body');
        $members['status'] = 'published'; $members['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $members['access_type'] = 'members';
        $this->pages->save(null, $members, $this->authorId);

        $listing = $this->public->index();
        self::assertSame(200, $listing->status());
        self::assertStringContainsString('Listed page', $listing->content());
        self::assertStringNotContainsString('Secret body', $listing->content());
        self::assertSame(302, $this->public->show((new Request('GET', '/pages/members-page'))->withAttributes(['slug' => 'members-page']))->status());

        $member = $this->createUser('pages-public-member');
        $this->assignRole($member, 'member');
        $this->loginAs($member);
        $detail = $this->public->show((new Request('GET', '/pages/members-page'))->withAttributes(['slug' => 'members-page']));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('Members page', $detail->content());
    }

    public function testSitemapAndImageValidationRespectPublicBoundaries(): void
    {
        $safe = $this->data('sitemap-public', 'Sitemap public', 'Body');
        $safe['status'] = 'published'; $safe['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $safe['image_path'] = '/uploads/pages/cover.webp';
        $this->pages->save(null, $safe, $this->authorId);
        $private = $this->data('sitemap-private', 'Sitemap private', 'Body');
        $private['status'] = 'published'; $private['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $private['access_type'] = 'members';
        $this->pages->save(null, $private, $this->authorId);

        $sitemap = $this->pages->sitemapEntries();
        self::assertCount(1, $sitemap);
        self::assertSame('sitemap-public', $sitemap[0]['slug']);
        $this->expectException(RuntimeException::class);
        $this->input->page(['title' => 'Unsafe image', 'slug' => 'unsafe-image', 'content' => 'body', 'image_path' => '/uploads/../secret.jpg'], true);
    }

    public function testCommentsAvailabilityRequiresPublishedVisiblePage(): void
    {
        $data = $this->data('comments-page', 'Comments page', 'Body');
        $data['comments_enabled'] = 1; $data['status'] = 'published'; $data['published_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        $id = $this->pages->save(null, $data, $this->authorId);
        self::assertTrue($this->pages->acceptsComments($id, null));
        $this->pages->delete($id);
        self::assertFalse($this->pages->acceptsComments($id, null));
    }

    public function testMarkdownRendererStripsUnsafeLinksAndScriptMarkup(): void
    {
        $renderer = new ContentRenderer(new HtmlSanitizer(), new MarkdownRenderer(new HtmlSanitizer()));
        $html = $renderer->render('[bad](javascript:alert(1)) <script>x</script>\n\n**good**', ContentFormat::Markdown, ContentProfile::FullContent);
        self::assertStringNotContainsString('javascript:', strtolower($html));
        self::assertStringNotContainsString('<script', strtolower($html));
        self::assertStringContainsString('<strong>good</strong>', $html);
    }

    /** @return array<string,mixed> */
    private function data(string $slug, string $title, string $content): array
    {
        return $this->input->page([
            'title' => $title, 'slug' => $slug, 'content' => $content, 'content_format' => 'html',
            'status' => 'draft', 'template' => 'default', 'access_type' => 'public',
            'comments_enabled' => '0', 'show_in_directory' => '1', 'role_ids' => [],
        ], true);
    }

    private function createUser(string $name): int
    {
        $this->db()->prepare('INSERT INTO users (username,email,password_hash,status,auth_version,created_at,updated_at) VALUES (:username,:email,:password,:status,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([
            'username' => $name, 'email' => $name . '@example.test', 'password' => password_hash('not-used', PASSWORD_DEFAULT), 'status' => 'active',
        ]);
        return (int) $this->db()->lastInsertId();
    }

    private function roleId(string $slug): int
    {
        $statement = $this->db()->prepare('SELECT id FROM roles WHERE slug=:slug'); $statement->execute(['slug' => $slug]);
        return (int) $statement->fetchColumn();
    }

    private function assignRole(int $userId, string $role): void
    {
        $this->db()->prepare('INSERT IGNORE INTO user_roles (user_id,role_id,created_at) VALUES (:user,:role,UTC_TIMESTAMP())')->execute(['user' => $userId, 'role' => $this->roleId($role)]);
    }

    private function assignPermission(int $userId, string $permission): void
    {
        $this->assignPermissionToRole($userId, 'editor', $permission);
    }

    private function assignPermissionToRole(int $userId, string $roleSlug, string $permission): void
    {
        $role = $this->roleId($roleSlug);
        $statement = $this->db()->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id,created_at) SELECT :role,id,UTC_TIMESTAMP() FROM permissions WHERE slug=:permission');
        $statement->execute(['role' => $role, 'permission' => $permission]);
        $this->assignRole($userId, $roleSlug);
    }

    private function loginAs(int $userId): void
    {
        $this->session->put('_auth_user_id', $userId);
        $this->session->put('_auth_version', 1);
    }

    private function request(string $method, string $path, array $input): Request
    {
        return new Request($method, $path, [], $input, [], [], ['REMOTE_ADDR' => '127.0.0.1']);
    }
}

final class FakePagesMembership implements MembershipManagerInterface
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
