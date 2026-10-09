<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use Modules\Wiki\src\PublicWikiController;
use Modules\Wiki\src\WikiAttachmentManager;
use Modules\Wiki\src\WikiAttachmentUpload;
use Modules\Wiki\src\AdminWikiController;
use Modules\Wiki\src\WikiArchive;
use Modules\Wiki\src\WikiFolderImport;
use Modules\Wiki\src\WikiInput;
use Modules\Wiki\src\WikiLinkIndexer;
use Modules\Wiki\src\WikiLinkPresenter;
use Modules\Wiki\src\WikiMarkdownRenderer;
use Modules\Wiki\src\WikiMarkdownFile;
use Modules\Wiki\src\WikiNavigation;
use Modules\Wiki\src\WikiRepository;
use Modules\Wiki\src\WikiRevisionComparator;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Database\Migrator;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Membership\MembershipManagerInterface;
use NovaNuke\Core\Modules\ModuleManifest;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Http\Routing\Router;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;

final class Wiki240HttpAcceptanceTest extends MySqlIntegrationTestCase
{
    private WikiRepository $wiki;
    private PublicWikiController $controller;
    private ViewRenderer $views;
    private SessionManager $session;
    private AuthManager $auth;
    private CsrfTokenManager $csrf;
    private AdminWikiController $admin;
    private object $membership;
    private int $authorId;

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        (new Migrator($database))->run(dirname(__DIR__, 2) . '/database/migrations');
        $manifestData = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/Wiki/module.json'), true, 32, JSON_THROW_ON_ERROR);
        (new ModuleMigrator($database))->run(ModuleManifest::fromArray($manifestData, dirname(__DIR__, 2) . '/modules/Wiki'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->db()->prepare(
            'INSERT INTO users (username,email,password_hash,status,created_at,updated_at) VALUES (:username,:email,:password,:status,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute([
            'username' => 'wiki-http', 'email' => 'wiki-http@example.test',
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'status' => 'active',
        ]);
        $this->authorId = (int) $this->db()->lastInsertId();
        $membership = new class implements MembershipManagerInterface {
            public bool $vip = false;
            public function status(int $userId): array { return ['is_vip' => false]; }
            public function isVip(int $userId): bool { return $this->vip; }
            public function nextScheduled(int $userId): ?array { return null; }
            public function plans(): array { return []; }
            public function assign(int $userId, string $planKey, int $actorId, ?string $note = null): array { return []; }
            public function grantDays(int $userId, int $days, int $actorId, ?string $note = null): array { return []; }
            public function extendDays(int $userId, int $days, int $actorId, ?string $note = null): array { return []; }
            public function schedule(int $userId, string $planKey, string $startsAt, int $actorId, ?string $note = null): array { return []; }
            public function cancelScheduled(int $userId, ?int $actorId = null): bool { return false; }
            public function revoke(int $userId, ?int $actorId = null, string $source = 'manual'): void {}
        };
        $this->membership = $membership;
        $input = new WikiInput();
        $this->wiki = new WikiRepository($this->db(), $membership, new WikiLinkIndexer($input));
        $session = new SessionManager('novanuke_wiki_http_test', false);
        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        $translator->addNamespace('wiki', dirname(__DIR__, 2) . '/modules/Wiki/language');
        $views = new ViewRenderer(dirname(__DIR__, 2) . '/resources/views', sys_get_temp_dir() . '/novanuke-wiki-http-cache', true, $translator);
        $views->addNamespace('wiki', dirname(__DIR__, 2) . '/modules/Wiki/views');
        $views->addGlobal('cms_name', 'NovaNuke Test');
        $views->addGlobal('cms_url', 'https://novanuke.test');
        $views->addGlobal('cms_date_format', 'Y-m-d');
        $views->addGlobal('cms_timezone', 'UTC');
        $views->addGlobal('current_user', null);
        $views->addGlobal('blocks', []);
        $views->addGlobal('menus', ['primary' => []]);
        $views->addGlobal('admin_navigation', []);
        $views->addGlobal('cms_version', '0.4.0-beta.1');
        $csrf = new CsrfTokenManager($session);
        $events = new EventDispatcher();
        $auth = new AuthManager($this->db(), $session, $events);
        $authorization = new AuthorizationService($this->db());
        $attachments = new WikiAttachmentManager($this->db(), new WikiAttachmentUpload(), sys_get_temp_dir());
        $this->session = $session;
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->views = $views;
        $this->controller = new PublicWikiController(
            $this->wiki,
            $input,
            new WikiNavigation(),
            new WikiLinkPresenter($input),
            new WikiAttachmentManager($this->db(), new WikiAttachmentUpload(), sys_get_temp_dir()),
            $auth,
            $authorization,
            new WikiMarkdownRenderer(),
            $views,
            $session,
            $csrf,
        );
        $this->admin = new AdminWikiController(
            $this->wiki,
            $input,
            $auth,
            $authorization,
            new ActivityLogger($this->db()),
            $events,
            $csrf,
            $session,
            $views,
            new WikiMarkdownRenderer(),
            new WikiRevisionComparator(),
            new WikiMarkdownFile(),
            $attachments,
            new WikiArchive(),
            new WikiFolderImport(new WikiMarkdownFile(), $input),
            $translator,
        );
    }

    public function testAdminNamespacesTemplateRendersLinksAndMovePreview(): void
    {
        $base = [
            'namespaces' => [], 'plan' => null, 'error' => null,
            'form' => ['old_namespace' => '', 'new_namespace' => '', 'include_descendants' => false],
            'message' => null, 'csrf_token' => str_repeat('a', 64),
        ];
        $empty = $this->views->render('@wiki/admin/namespaces.twig', $base);
        self::assertStringContainsString('href="/admin/wiki"', $empty);
        self::assertStringContainsString('action="/admin/wiki/namespaces/move"', $empty);
        self::assertStringNotContainsString('url(', (string) file_get_contents(dirname(__DIR__, 2) . '/modules/Wiki/views/admin/namespaces.twig'));

        $preview = $this->views->render('@wiki/admin/namespaces.twig', array_replace($base, [
            'namespaces' => [['namespace' => 'guides:quick', 'direct_pages' => 2, 'pages' => 3]],
            'plan' => ['collisions' => [], 'affected_count' => 1],
            'form' => ['old_namespace' => 'guides', 'new_namespace' => 'handbook', 'include_descendants' => true],
        ]));
        self::assertStringContainsString('namespace=guides%3Aquick', $preview);
        self::assertStringContainsString('name="confirm_move" value="1"', $preview);
        self::assertStringContainsString('name="new_namespace" value="handbook"', $preview);
    }

    public function testAccessibleAliasReturnsInternalPermanentRedirect(): void
    {
        $page = $this->createPage('old', 'page', 'Moved page');
        $this->wiki->renameNamespace('old', 'new', false);
        $response = $this->controller->show((new Request('GET', '/wiki/old:page'))->withAttributes(['path' => 'old:page']));
        self::assertSame(301, $response->status());
        self::assertSame('/wiki/new:page', $response->header('Location'));
        self::assertSame($page, (int) $this->db()->query("SELECT id FROM wiki_pages WHERE namespace='new' AND slug='page'")->fetchColumn());
    }

    public function testCanonicalRoutePrecedesHistoricalAliasAndUnavailableAliasIs404(): void
    {
        $original = $this->createPage('old', 'page', 'Original');
        $this->wiki->renameNamespace('old', 'new', false);
        $canonical = $this->createPage('old', 'page', 'Reused canonical path');
        $response = $this->controller->show((new Request('GET', '/wiki/old:page'))->withAttributes(['path' => 'old:page']));
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Reused canonical path', $response->content());
        self::assertNotSame($original, $canonical);

        $private = $this->createPage('private', 'page', 'Private', 'secret', 'member');
        $this->wiki->renameNamespace('private', 'hidden', false);
        $missing = $this->controller->show((new Request('GET', '/wiki/private:page'))->withAttributes(['path' => 'private:page']));
        self::assertSame(404, $missing->status());
        self::assertStringNotContainsString('Private', $missing->content());
    }

    public function testNamespaceStartLandingRendersAccessibleStartAndFallsBackToDirectory(): void
    {
        $this->createPage('area', 'start', 'Area landing');
        $this->createPage('area', 'child', 'Child page');
        $landing = $this->controller->index(new Request('GET', '/wiki?namespace=area', ['namespace' => 'area']));
        self::assertSame(200, $landing->status());
        self::assertStringContainsString('Area landing', $landing->content());

        $this->createPage('other', 'child', 'Other child');
        $directory = $this->controller->index(new Request('GET', '/wiki?namespace=other', ['namespace' => 'other']));
        self::assertSame(200, $directory->status());
        self::assertStringContainsString('Other child', $directory->content());
    }

    public function testAdminNamespacesRouterAuthorizationMatrix(): void
    {
        $this->installPermission('wiki.edit');
        $member = $this->createUser('wiki-member', 'member');
        $editor = $this->createUser('wiki-editor', 'wiki-test-editor', ['wiki.edit']);
        $administrator = $this->createUser('wiki-admin', 'super-administrator');

        $anonymous = $this->dispatchAdmin('GET', '/admin/wiki/namespaces');
        self::assertSame(302, $anonymous->status());
        self::assertSame('/login', $anonymous->header('Location'));

        $this->authenticateAs($member);
        $withoutPermission = $this->dispatchAdmin('GET', '/admin/wiki/namespaces');
        self::assertSame(403, $withoutPermission->status());
        self::assertStringNotContainsString('private', strtolower($withoutPermission->content()));

        $this->authenticateAs($editor);
        $authorized = $this->dispatchAdmin('GET', '/admin/wiki/namespaces');
        self::assertSame(200, $authorized->status());
        self::assertStringContainsString('admin/wiki/namespaces/move', $authorized->content());
        self::assertStringNotContainsString('id="include_descendants" name="include_descendants" value="1" checked', $authorized->content());

        $selected = $this->dispatchAdmin('GET', '/admin/wiki/namespaces', [], ['namespace' => 'guides']);
        self::assertSame(200, $selected->status());
        self::assertStringContainsString('name="old_namespace" value="guides"', $selected->content());

        $this->authenticateAs($administrator);
        self::assertSame(200, $this->dispatchAdmin('GET', '/admin/wiki/namespaces')->status());
    }

    public function testAdminNamespacePostEnforcesPermissionAndCsrfBeforeMutation(): void
    {
        $this->installPermission('wiki.edit');
        $member = $this->createUser('wiki-member-post', 'member');
        $editor = $this->createUser('wiki-editor-post', 'wiki-test-editor-post', ['wiki.edit']);
        $page = $this->createPage('old', 'page', 'Move me');
        $revisionCount = (int) $this->db()->query("SELECT COUNT(*) FROM wiki_page_revisions WHERE wiki_page_id={$page}")->fetchColumn();
        $this->db()->prepare(
            'INSERT INTO wiki_attachments (wiki_page_id,uploaded_by,original_name,stored_name,mime_type,file_size,created_at) VALUES (:page,:user,:original,:stored,:mime,:size,UTC_TIMESTAMP())'
        )->execute([
            'page' => $page, 'user' => $this->authorId, 'original' => 'note.txt',
            'stored' => str_repeat('a', 40) . '.txt', 'mime' => 'text/plain', 'size' => 4,
        ]);
        $before = $this->namespaceState();

        $anonymous = $this->dispatchAdmin('POST', '/admin/wiki/namespaces/move', [
            'old_namespace' => 'old', 'new_namespace' => 'new', 'confirm_move' => '1',
        ]);
        self::assertSame(302, $anonymous->status());
        self::assertSame($before, $this->namespaceState());

        $this->authenticateAs($member);
        $forbidden = $this->dispatchAdmin('POST', '/admin/wiki/namespaces/move', [
            'old_namespace' => 'old', 'new_namespace' => 'new', 'confirm_move' => '1',
        ]);
        self::assertSame(403, $forbidden->status());
        self::assertSame($before, $this->namespaceState());

        $this->authenticateAs($editor);
        $missingCsrf = $this->dispatchAdmin('POST', '/admin/wiki/namespaces/move', [
            'old_namespace' => 'old', 'new_namespace' => 'new', 'confirm_move' => '1',
        ]);
        self::assertSame(419, $missingCsrf->status());
        self::assertSame($before, $this->namespaceState());

        $invalidCsrf = $this->dispatchAdmin('POST', '/admin/wiki/namespaces/move', [
            '_token' => str_repeat('0', 64), 'old_namespace' => 'old', 'new_namespace' => 'new', 'confirm_move' => '1',
        ]);
        self::assertSame(419, $invalidCsrf->status());
        self::assertSame($before, $this->namespaceState());

        $validCsrf = $this->csrf->token();
        $invalidInput = $this->dispatchAdmin('POST', '/admin/wiki/namespaces/move', [
            '_token' => $validCsrf, 'old_namespace' => 'bad namespace', 'new_namespace' => 'new', 'confirm_move' => '1',
        ]);
        self::assertSame(422, $invalidInput->status());
        self::assertStringContainsString('include_descendants', $invalidInput->content());
        self::assertSame($before, $this->namespaceState());

        $preview = $this->dispatchAdmin('POST', '/admin/wiki/namespaces/move', [
            '_token' => $validCsrf, 'old_namespace' => 'old', 'new_namespace' => 'new',
        ]);
        self::assertSame(200, $preview->status());
        self::assertStringContainsString('name="confirm_move" value="1"', $preview->content());
        self::assertSame($before, $this->namespaceState());

        $moved = $this->dispatchAdmin('POST', '/admin/wiki/namespaces/move', [
            '_token' => $validCsrf, 'old_namespace' => 'old', 'new_namespace' => 'new', 'confirm_move' => '1',
        ]);
        self::assertSame(303, $moved->status());
        self::assertSame('/admin/wiki/namespaces', $moved->header('Location'));
        self::assertSame($page, (int) $this->db()->query("SELECT id FROM wiki_pages WHERE namespace='new' AND slug='page'")->fetchColumn());
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM wiki_pages WHERE namespace='old'")->fetchColumn());
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM wiki_path_aliases WHERE historical_path='old:page' AND wiki_page_id={$page}")->fetchColumn());
        self::assertSame($revisionCount, (int) $this->db()->query("SELECT COUNT(*) FROM wiki_page_revisions WHERE wiki_page_id={$page}")->fetchColumn());
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM wiki_attachments WHERE wiki_page_id={$page}")->fetchColumn());
    }

    public function testMemberVipAndAliasVisibilityMatrixDoesNotLeakProtectedContent(): void
    {
        $member = $this->createUser('visibility-member', 'member');
        $vip = $this->createUser('visibility-vip', 'member');
        $editor = $this->createUser('visibility-admin', 'super-administrator');
        $public = $this->createPage('', 'public', 'Public page');
        $this->createPage('', 'members', 'Members page', 'member content', 'member');
        $this->createPage('', 'vip', 'VIP page', 'vip content', 'vip');
        $this->createPage('', 'draft', 'Draft page', 'draft content', 'public', 'draft');
        $this->createPage('area', 'start', 'Members landing', 'landing secret', 'member');
        $this->createPage('area', 'child', 'Public child');
        $this->createPage('viparea', 'start', 'VIP landing', 'vip landing secret', 'vip');
        $this->createPage('viparea', 'child', 'VIP public child');
        $this->createPage('old', 'secret', 'Historical members page', 'historical secret', 'member');
        $this->wiki->renameNamespace('old', 'new', false);

        $_SESSION = [];
        $anonymousPublic = $this->dispatchPublic('GET', '/wiki/public', ['path' => 'public']);
        self::assertSame(200, $anonymousPublic->status());
        self::assertStringContainsString('Public page', $anonymousPublic->content());
        $anonymousMembers = $this->dispatchPublic('GET', '/wiki/members', ['path' => 'members']);
        self::assertSame(302, $anonymousMembers->status());
        self::assertStringNotContainsString('Members page', $anonymousMembers->content());
        $anonymousVip = $this->dispatchPublic('GET', '/wiki/vip', ['path' => 'vip']);
        self::assertSame(302, $anonymousVip->status());
        $anonymousDraft = $this->dispatchPublic('GET', '/wiki/draft', ['path' => 'draft']);
        self::assertSame(404, $anonymousDraft->status());
        $anonymousAlias = $this->dispatchPublic('GET', '/wiki/old:secret', ['path' => 'old:secret']);
        self::assertSame(404, $anonymousAlias->status());
        $anonymousLanding = $this->dispatchPublicIndex(['namespace' => 'area']);
        self::assertSame(200, $anonymousLanding->status());
        self::assertStringContainsString('Public child', $anonymousLanding->content());
        self::assertStringNotContainsString('Members landing', $anonymousLanding->content());

        $this->authenticateAs($member);
        self::assertSame(200, $this->dispatchPublic('GET', '/wiki/members', ['path' => 'members'])->status());
        $memberVip = $this->dispatchPublic('GET', '/wiki/vip', ['path' => 'vip']);
        self::assertSame(403, $memberVip->status());
        $memberAlias = $this->dispatchPublic('GET', '/wiki/old:secret', ['path' => 'old:secret']);
        self::assertSame(301, $memberAlias->status());
        self::assertSame('/wiki/new:secret', $memberAlias->header('Location'));
        self::assertSame(200, $this->dispatchPublicIndex(['namespace' => 'area'])->status());

        $this->membership->vip = true;
        $this->authenticateAs($vip);
        self::assertSame(200, $this->dispatchPublic('GET', '/wiki/vip', ['path' => 'vip'])->status());
        $vipLanding = $this->dispatchPublicIndex(['namespace' => 'viparea']);
        self::assertSame(200, $vipLanding->status());
        self::assertStringContainsString('VIP landing', $vipLanding->content());

        $this->authenticateAs($editor);
        $adminDraft = $this->dispatchPublic('GET', '/wiki/draft', ['path' => 'draft']);
        self::assertSame(404, $adminDraft->status());
        self::assertStringNotContainsString('draft content', $adminDraft->content());
        self::assertSame($public, (int) $this->db()->query("SELECT id FROM wiki_pages WHERE namespace='' AND slug='public'")->fetchColumn());
    }

    private function dispatchAdmin(string $method, string $path, array $input = [], array $query = []): Response
    {
        $router = new Router();
        $router->get('/admin/wiki/namespaces', fn (Request $request, object $container): Response => $this->admin->namespaces($request));
        $router->post('/admin/wiki/namespaces/move', fn (Request $request, object $container): Response => $this->admin->moveNamespace($request));
        $request = new Request($method, $path, $query, $input, [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $match = $router->match($request);
        $request = $request->withAttributes($match->parameters);
        return ($match->route->handler)($request, new \stdClass());
    }

    private function dispatchPublic(string $method, string $path, array $attributes = [], array $query = []): Response
    {
        $request = new Request($method, $path, $query, [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        return $this->controller->show($request->withAttributes($attributes));
    }

    private function dispatchPublicIndex(array $query = []): Response
    {
        return $this->controller->index(new Request('GET', '/wiki', $query, [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function installPermission(string $slug): void
    {
        $this->db()->prepare(
            'INSERT INTO permissions (name, slug, description, module_slug, created_at, updated_at) VALUES (:name, :slug, :description, :module, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE slug=VALUES(slug)'
        )->execute(['name' => $slug, 'slug' => $slug, 'description' => $slug, 'module' => 'wiki']);
    }

    private function createUser(string $username, string $roleSlug, array $permissions = []): int
    {
        $this->db()->prepare(
            'INSERT INTO users (username,email,password_hash,status,created_at,updated_at) VALUES (:username,:email,:password,:status,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute([
            'username' => $username, 'email' => $username . '@example.test',
            'password' => password_hash('test-password', PASSWORD_DEFAULT), 'status' => 'active',
        ]);
        $userId = (int) $this->db()->lastInsertId();
        $role = $this->db()->prepare('SELECT id FROM roles WHERE slug=:slug');
        $role->execute(['slug' => $roleSlug]);
        $roleId = $role->fetchColumn();
        if ($roleId === false) {
            $this->db()->prepare('INSERT INTO roles (name,slug,is_system,created_at,updated_at) VALUES (:name,:slug,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
                ->execute(['name' => $roleSlug, 'slug' => $roleSlug]);
            $roleId = (int) $this->db()->lastInsertId();
        }
        $this->db()->prepare('INSERT INTO user_roles (user_id,role_id,created_at) VALUES (:user,:role,UTC_TIMESTAMP())')
            ->execute(['user' => $userId, 'role' => $roleId]);
        foreach ($permissions as $permission) {
            $this->installPermission($permission);
            $this->db()->prepare('INSERT INTO role_permissions (role_id,permission_id,created_at) SELECT :role,id,UTC_TIMESTAMP() FROM permissions WHERE slug=:slug')
                ->execute(['role' => $roleId, 'slug' => $permission]);
        }
        return $userId;
    }

    private function authenticateAs(int $userId): void
    {
        $_SESSION = [];
        $this->session->put('_auth_user_id', $userId);
        $this->session->put('_auth_version', 1);
    }

    private function logout(): void
    {
        $_SESSION = [];
    }

    /** @return array{pages:int,aliases:int} */
    private function namespaceState(): array
    {
        return [
            'pages' => (int) $this->db()->query("SELECT COUNT(*) FROM wiki_pages WHERE namespace IN ('old','new')")->fetchColumn(),
            'aliases' => (int) $this->db()->query("SELECT COUNT(*) FROM wiki_path_aliases WHERE historical_path='old:page'")->fetchColumn(),
        ];
    }

    private function createPage(string $namespace, string $slug, string $title, string $content = 'content', string $audience = 'public', string $status = 'published'): int
    {
        return $this->wiki->save(null, [
            'namespace' => $namespace, 'slug' => $slug, 'title' => $title, 'content' => $content,
            'status' => $status, 'audience' => $audience, 'comments_enabled' => 0,
        ], $this->authorId);
    }
}
