<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use Modules\Downloads\src\AdminDownloadsController;
use Modules\Downloads\src\DownloadInput;
use Modules\Downloads\src\DownloadManager;
use Modules\Downloads\src\DownloadOrphanCleaner;
use Modules\Downloads\src\DownloadRepository;
use Modules\Downloads\src\DownloadStorage;
use Modules\Downloads\src\DownloadUploadValidator;
use Modules\Downloads\src\DownloadsSearchProvider;
use Modules\Downloads\src\PublicDownloadsController;
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
use NovaNuke\Core\Security\DatabaseRateLimiter;
use NovaNuke\Core\Security\HtmlSanitizer;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Search\SearchQuery;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;
use RuntimeException;

final class DownloadsFunctionalAcceptanceTest extends MySqlIntegrationTestCase
{
    private DownloadRepository $downloads;
    private DownloadInput $input;
    private DownloadManager $manager;
    private DownloadStorage $storage;
    private FakeDownloadMembership $membership;
    private int $authorId;
    private SessionManager $session;
    private CsrfTokenManager $csrf;
    private AuthManager $auth;
    private AuthorizationService $authorization;
    private AdminDownloadsController $admin;
    private PublicDownloadsController $public;
    private string $storageDirectory;

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        (new Migrator($database))->run(dirname(__DIR__, 2) . '/database/migrations');
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/Downloads/module.json'), true, 32, JSON_THROW_ON_ERROR);
        (new ModuleMigrator($database))->run(ModuleManifest::fromArray($manifest, dirname(__DIR__, 2) . '/modules/Downloads'));
        $statement = $database->prepare(
            'INSERT INTO permissions (name,slug,description,module_slug,created_at,updated_at) VALUES (:name,:slug,:description,:module,UTC_TIMESTAMP(),UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),module_slug=VALUES(module_slug),updated_at=UTC_TIMESTAMP()'
        );
        foreach (['downloads.manage', 'downloads.publish'] as $permission) {
            $statement->execute(['name' => ucwords(str_replace(['.', '-', '_'], ' ', $permission)), 'slug' => $permission, 'description' => 'Permission provided by Downloads.', 'module' => 'downloads']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->membership = new FakeDownloadMembership();
        $this->downloads = new DownloadRepository($this->db(), $this->membership);
        $this->input = new DownloadInput();
        $this->authorId = $this->createUser('downloads-author');
        $this->session = new SessionManager('novanuke_downloads_acceptance', false);
        $this->csrf = new CsrfTokenManager($this->session);
        $events = new EventDispatcher();
        $this->auth = new AuthManager($this->db(), $this->session, $events);
        $this->authorization = new AuthorizationService($this->db());
        $this->storageDirectory = sys_get_temp_dir() . '/novanuke-downloads-acceptance-' . bin2hex(random_bytes(6));
        mkdir($this->storageDirectory, 0700, true);
        $this->storage = new DownloadStorage($this->storageDirectory);
        $this->manager = new DownloadManager($this->downloads, new DownloadUploadValidator(), $this->storage, $this->auth, $events, new DatabaseRateLimiter($this->db(), 5, 600, 'downloads-acceptance'), 'test-download-app-key');
        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        $translator->addNamespace('downloads', dirname(__DIR__, 2) . '/modules/Downloads/language');
        $views = new ViewRenderer(dirname(__DIR__, 2) . '/resources/views', sys_get_temp_dir() . '/novanuke-downloads-views-' . bin2hex(random_bytes(4)), true, $translator);
        $views->addNamespace('admin-core', dirname(__DIR__, 2) . '/resources/views');
        $views->addNamespace('downloads', dirname(__DIR__, 2) . '/modules/Downloads/views');
        $views->addNamespace('admin-downloads', dirname(__DIR__, 2) . '/modules/Downloads/views/admin');
        foreach (['cms_name' => 'NovaNuke Test', 'cms_url' => 'https://novanuke.test', 'cms_locale' => 'en', 'cms_date_format' => 'Y-m-d', 'cms_timezone' => 'UTC', 'current_user' => null, 'blocks' => [], 'menus' => ['primary' => []], 'admin_navigation' => [], 'cms_version' => '0.4.0-beta.1'] as $key => $value) $views->addGlobal($key, $value);
        $renderer = new ContentRenderer(new HtmlSanitizer(), new MarkdownRenderer(new HtmlSanitizer()));
        $this->admin = new AdminDownloadsController($this->downloads, $this->manager, $this->input, $this->auth, $this->authorization, new ActivityLogger($this->db()), $this->csrf, $this->session, $views, $translator);
        $this->public = new PublicDownloadsController($this->downloads, $this->manager, $this->auth, $events, $this->csrf, $this->session, $views, $this->authorization, $renderer, $translator);
    }

    protected function tearDown(): void
    {
        if (isset($this->storageDirectory) && is_dir($this->storageDirectory)) $this->removeTree($this->storageDirectory);
        parent::tearDown();
    }

    public function testCreateEditPreservesIdentityAndExistingLocalContent(): void
    {
        $stored = str_repeat('a', 40) . '.txt'; file_put_contents($this->storageDirectory . '/' . $stored, 'private content');
        $id = $this->downloads->save(null, $this->data('toolkit', 'Toolkit', 'Original', 'local') + ['stored_name' => $stored, 'original_name' => 'toolkit.txt', 'file_size' => 15, 'mime_type' => 'text/plain'], $this->authorId);
        $before = $this->downloads->download($id);
        $edit = $this->data('toolkit', 'Updated toolkit', 'Updated description', 'local') + ['stored_name' => $stored, 'original_name' => 'toolkit.txt', 'file_size' => 15, 'mime_type' => 'text/plain'];
        $this->downloads->save($id, $edit, $this->authorId);
        $after = $this->downloads->download($id);
        self::assertSame($id, (int) $after['id']);
        self::assertSame($before['created_by'], $after['created_by']);
        self::assertSame('Updated description', $after['description']);
        self::assertSame($stored, $after['stored_name']);
        self::assertSame('private content', file_get_contents($this->storageDirectory . '/' . $stored));
    }

    public function testDuplicateSlugAndInvalidCategoryRollBackWithoutPartialRows(): void
    {
        $first = $this->downloads->save(null, $this->externalData('same-download', 'First'), $this->authorId);
        $second = $this->downloads->save(null, $this->externalData('other-download', 'Second'), $this->authorId);
        $this->expectException(RuntimeException::class);
        try { $this->downloads->save($second, $this->externalData('same-download', 'Rejected'), $this->authorId); }
        finally {
            self::assertSame('other-download', $this->downloads->download($second)['slug']);
            self::assertSame('Second', $this->downloads->download($second)['name']);
            self::assertSame(2, (int) $this->db()->query('SELECT COUNT(*) FROM downloads')->fetchColumn());
            self::assertNotSame($first, $second);
        }
        $this->expectException(RuntimeException::class);
        try { $this->downloads->save(null, $this->externalData('bad-category', 'Bad') + ['category_id' => 999999999], $this->authorId); }
        finally { self::assertNull($this->downloads->publicDownload('bad-category')); }
    }

    public function testPublicationScheduleSoftDeleteAndPublicCatalogBoundaries(): void
    {
        $draft = $this->downloads->save(null, $this->externalData('draft-download', 'Draft'), $this->authorId);
        $future = $this->externalData('future-download', 'Future'); $future['status'] = 'scheduled'; $future['published_at'] = gmdate('Y-m-d H:i:s', time() + 3600); $scheduled = $this->downloads->save(null, $future, $this->authorId);
        $live = $this->externalData('live-download', 'Live'); $live['status'] = 'published'; $live['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $published = $this->downloads->save(null, $live, $this->authorId);
        self::assertNull($this->downloads->publicDownload('draft-download'));
        self::assertNull($this->downloads->publicDownload('future-download'));
        self::assertSame($published, (int) $this->downloads->publicDownload('live-download')['id']);
        self::assertSame(1, $this->downloads->catalog(1, null, '', 'new', null)['total']);
        $this->downloads->delete($published);
        self::assertNull($this->downloads->publicDownload('live-download'));
        self::assertSame($draft, (int) $this->downloads->download($draft)['id']);
        self::assertSame($scheduled, (int) $this->downloads->download($scheduled)['id']);
    }

    public function testCategoriesAndRoleAssociationsPersistWithoutDuplicates(): void
    {
        $parent = $this->downloads->saveCategory(['name' => 'Tools', 'slug' => 'tools', 'description' => 'Tools', 'parent_id' => null]);
        $child = $this->downloads->saveCategory(['name' => 'Editors', 'slug' => 'editors', 'description' => null, 'parent_id' => $parent]);
        $role = $this->roleId('editor');
        $data = $this->externalData('role-download', 'Role download'); $data['category_id'] = $child; $data['access_type'] = 'roles'; $data['role_ids'] = [$role];
        $id = $this->downloads->save(null, $data, $this->authorId);
        self::assertSame($child, (int) $this->downloads->download($id)['category_id']);
        self::assertSame([$role], $this->downloads->download($id)['role_ids']);
        $categories = array_values(array_filter($this->downloads->categories(), static fn (array $category): bool => $category['slug'] === 'editors'));
        self::assertSame('Tools', $categories[0]['parent_name']);
        $this->expectException(RuntimeException::class);
        $this->downloads->saveCategory(['name' => 'Invalid child', 'slug' => 'invalid-child', 'description' => null, 'parent_id' => 999999999]);
    }

    public function testMemberVipAndRoleAudienceAreEnforcedForViewAndCatalog(): void
    {
        $member = $this->downloads->save(null, $this->publishedData('member-only', 'Member', 'members'), $this->authorId);
        $vip = $this->downloads->save(null, $this->publishedData('vip-only', 'VIP', 'vip'), $this->authorId);
        $role = $this->roleId('editor'); $roleData = $this->publishedData('role-only', 'Role', 'roles'); $roleData['role_ids'] = [$role]; $roleId = $this->downloads->save(null, $roleData, $this->authorId);
        self::assertFalse($this->downloads->canView($this->downloads->download($member), null));
        $user = $this->createUser('downloads-member');
        self::assertTrue($this->downloads->canView($this->downloads->download($member), $user));
        self::assertFalse($this->downloads->canView($this->downloads->download($vip), $user));
        $this->membership->vip = true; self::assertTrue($this->downloads->canView($this->downloads->download($vip), $user));
        $this->db()->prepare('INSERT INTO user_roles (user_id,role_id,created_at) VALUES (:user,:role,UTC_TIMESTAMP())')->execute(['user' => $user, 'role' => $role]);
        self::assertTrue($this->downloads->canView($this->downloads->download($roleId), $user));
        $this->db()->prepare('INSERT INTO user_entitlements (user_id,entitlement,starts_at,expires_at,created_at,updated_at) VALUES (:user,"vip",UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['user' => $user]);
        self::assertSame(3, $this->downloads->catalog(1, null, '', 'new', $user)['total']);
    }

    public function testInputRejectsUnsafeExternalUrlsPublicationAndImages(): void
    {
        $messages = [];
        foreach (['javascript:alert(1)', 'file:///tmp/a', 'https://user:pass@example.test/file', 'https://example.test/a' . "\n" . 'X'] as $url) {
            try { $this->input->download(['name' => 'Unsafe', 'slug' => 'unsafe-' . random_int(1, 999999), 'description' => 'Body', 'source_type' => 'external', 'external_url' => $url], true); } catch (RuntimeException $error) { $messages[] = $error->getMessage(); }
        }
        self::assertSame(4, count($messages)); self::assertSame(['External URL must use safe HTTP or HTTPS.'], array_values(array_unique($messages)));
        try { $this->input->download(['name' => 'Bad', 'slug' => 'bad', 'description' => 'Body', 'source_type' => 'external', 'external_url' => 'https://example.test', 'status' => 'scheduled', 'published_at' => gmdate('Y-m-d\TH:i')], true); self::fail('A scheduled download at the current time must be rejected.'); } catch (RuntimeException $error) { self::assertSame('Scheduled downloads require a future publication date.', $error->getMessage()); }
        try { $this->input->download(['name' => 'Bad image', 'slug' => 'bad-image', 'description' => 'Body', 'source_type' => 'external', 'external_url' => 'https://example.test', 'image_path' => '/uploads/../secret.png'], true); self::fail('Unsafe image paths must be rejected.'); } catch (RuntimeException $error) { self::assertSame('Image must be a safe path below /uploads/.', $error->getMessage()); }
    }

    public function testUploadValidationAndPrivateStorageBoundary(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'novanuke-download-upload-'); file_put_contents($path, 'not a pdf');
        try {
            $this->expectException(RuntimeException::class); $this->inputUpload($path, 'bad.pdf');
        } finally { @unlink($path); }
        $this->expectException(RuntimeException::class); $this->storage->path('../' . str_repeat('a', 40) . '.txt');
    }

    public function testOrphanCleanupRemovesOnlyOldUnreferencedPrivateFiles(): void
    {
        $referenced = str_repeat('b', 40) . '.txt'; $orphan = str_repeat('c', 40) . '.txt'; $recent = str_repeat('d', 40) . '.txt';
        foreach ([$referenced, $orphan, $recent] as $name) file_put_contents($this->storageDirectory . '/' . $name, $name);
        touch($this->storageDirectory . '/' . $orphan, time() - 200000);
        $this->downloads->save(null, array_replace($this->externalData('stored-reference', 'Stored'), ['source_type' => 'local', 'stored_name' => $referenced, 'original_name' => 'ref.txt', 'file_size' => 20, 'mime_type' => 'text/plain']), $this->authorId);
        $cleaner = new DownloadOrphanCleaner($this->storageDirectory, fn (): array => $this->downloads->storedNames(), 86400);
        self::assertSame(['eligible' => 1, 'bytes' => 44, 'removed' => 0, 'recent' => 1], $cleaner->run());
        self::assertSame(1, $cleaner->run(true)['removed']);
        self::assertFileExists($this->storageDirectory . '/' . $referenced); self::assertFileExists($this->storageDirectory . '/' . $recent); self::assertFileDoesNotExist($this->storageDirectory . '/' . $orphan);
    }

    public function testPublicControllerListsShowsAndDeliversExternalAndLocalDownloads(): void
    {
        $external = $this->externalData('public-external', 'Public external'); $external['status'] = 'published'; $external['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $externalId = $this->downloads->save(null, $external, $this->authorId);
        $stored = str_repeat('e', 40) . '.txt'; file_put_contents($this->storageDirectory . '/' . $stored, 'download body');
        $local = $this->publishedData('public-local', 'Public local', 'public'); $local = array_replace($local, ['source_type' => 'local', 'stored_name' => $stored, 'original_name' => 'safe name.txt', 'file_size' => 13, 'mime_type' => 'text/plain']); $localId = $this->downloads->save(null, $local, $this->authorId);
        $listing = $this->public->index(new Request('GET', '/downloads'));
        self::assertSame(200, $listing->status()); self::assertStringContainsString('Public external', $listing->content()); self::assertStringContainsString('Public local', $listing->content());
        $show = $this->public->show((new Request('GET', '/downloads/public-external'))->withAttributes(['slug' => 'public-external'])); self::assertSame(200, $show->status()); self::assertStringContainsString('Public external', $show->content());
        $redirect = $this->public->deliver((new Request('GET', '/downloads/public-external/get'))->withAttributes(['slug' => 'public-external'])); self::assertSame(302, $redirect->status()); self::assertSame('https://example.test/files/public-external.zip', $redirect->header('Location')); self::assertSame('no-referrer', $redirect->header('Referrer-Policy'));
        $response = $this->public->deliver((new Request('GET', '/downloads/public-local/get'))->withAttributes(['slug' => 'public-local'])); self::assertSame(200, $response->status()); self::assertSame('text/plain', $response->header('Content-Type')); self::assertStringContainsString('attachment', $response->header('Content-Disposition')); self::assertSame('13', $response->header('Content-Length')); self::assertSame($localId, (int) $this->downloads->publicDownload('public-local')['id']); self::assertSame(1, (int) $this->db()->query('SELECT download_count FROM downloads WHERE id=' . $localId)->fetchColumn());
        self::assertSame($externalId, (int) $this->downloads->publicDownload('public-external')['id']);
    }

    public function testMissingLocalFileAndRestrictedPublicRoutesDoNotDiscloseStorage(): void
    {
        $stored = str_repeat('f', 40) . '.txt'; $data = $this->publishedData('missing-local', 'Missing local', 'public'); $data = array_replace($data, ['source_type' => 'local', 'stored_name' => $stored, 'original_name' => 'secret.txt', 'file_size' => 10, 'mime_type' => 'text/plain']); $this->downloads->save(null, $data, $this->authorId);
        $response = $this->public->deliver((new Request('GET', '/downloads/missing-local/get'))->withAttributes(['slug' => 'missing-local'])); self::assertSame(403, $response->status()); self::assertStringNotContainsString($this->storageDirectory, $response->content());
        $restricted = $this->publishedData('member-download', 'Member download', 'members'); $this->downloads->save(null, $restricted, $this->authorId);
        self::assertSame(302, $this->public->show((new Request('GET', '/downloads/member-download'))->withAttributes(['slug' => 'member-download']))->status());
    }

    public function testTrackingCountsOncePerVisitorAndReportsCanBeResolved(): void
    {
        $id = $this->downloads->save(null, $this->publishedData('trackable', 'Trackable', 'public'), $this->authorId);
        self::assertTrue($this->downloads->recordDownload($id, hash('sha256', 'visitor'))); self::assertFalse($this->downloads->recordDownload($id, hash('sha256', 'visitor'))); self::assertSame(1, (int) $this->db()->query('SELECT download_count FROM downloads WHERE id=' . $id)->fetchColumn());
        $request = $this->request('POST', '/downloads/' . $id . '/report', ['_token' => $this->csrf->token(), 'slug' => 'trackable', 'reason' => 'Broken file']); self::assertSame(303, $this->public->report($request->withAttributes(['id' => (string) $id]))->status());
        self::assertSame(1, count($this->downloads->openReports())); $reportId = (int) $this->db()->query('SELECT id FROM download_reports WHERE download_id=' . $id)->fetchColumn(); $this->downloads->resolveReport($reportId); self::assertSame(0, count($this->downloads->openReports()));
    }

    public function testSearchExcludesDraftPrivateDeletedAndUnsafeContentIsSanitized(): void
    {
        $live = $this->publishedData('searchable', 'Searchable download', 'public'); $live['description'] = '<script>alert(1)</script><p onclick="bad()"><strong>Good</strong></p>'; $id = $this->downloads->save(null, $live, $this->authorId);
        $private = $this->publishedData('private-search', 'Private download', 'members'); $this->downloads->save(null, $private, $this->authorId); $this->downloads->save(null, $this->externalData('draft-search', 'Draft download'), $this->authorId);
        $response = $this->public->show((new Request('GET', '/downloads/searchable'))->withAttributes(['slug' => 'searchable'])); self::assertStringNotContainsString('<script', strtolower($response->content())); self::assertStringNotContainsString('onclick=', strtolower($response->content())); self::assertStringContainsString('<strong>Good</strong>', $response->content());
        $search = (new DownloadsSearchProvider($this->db()))->search(new SearchQuery('Searchable', 10, null)); self::assertSame(1, $search->total); self::assertSame('/downloads/searchable', $search->items[0]->url);
        $this->downloads->delete($id); self::assertSame(0, (new DownloadsSearchProvider($this->db()))->search(new SearchQuery('Searchable', 10, null))->total);
    }

    public function testAdminAuthorizationCsrfPublicationEditDeleteAndValidation(): void
    {
        self::assertSame(302, $this->admin->create()->status());
        $user = $this->createUser('downloads-no-permission'); $this->loginAs($user); self::assertSame(403, $this->admin->index()->status());
        $editor = $this->createUser('downloads-editor'); $this->grant($editor, 'editor', 'downloads.manage'); $this->loginAs($editor);
        $draft = $this->httpData($this->externalData('admin-draft', 'Admin draft')); self::assertSame(419, $this->admin->save($this->request('POST', '/admin/downloads/save', $draft))->status()); $draft['_token'] = 'invalid'; self::assertSame(419, $this->admin->save($this->request('POST', '/admin/downloads/save', $draft))->status());
        $draft['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->save($this->request('POST', '/admin/downloads/save', $draft))->status());
        $published = $this->httpData($this->externalData('admin-published', 'Admin published')); $published['status'] = 'published'; $published['_token'] = $this->csrf->token(); self::assertSame(422, $this->admin->save($this->request('POST', '/admin/downloads/save', $published))->status());
        $admin = $this->createUser('downloads-admin'); $this->grant($admin, 'super-administrator', 'downloads.manage'); $this->grant($admin, 'super-administrator', 'downloads.publish'); $this->loginAs($admin);
        $valid = $this->httpData($this->externalData('admin-valid', 'Before')); $valid['status'] = 'published'; $valid['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->save($this->request('POST', '/admin/downloads/save', $valid))->status()); $id = (int) $this->db()->query("SELECT id FROM downloads WHERE slug='admin-valid'")->fetchColumn();
        $edit = $this->httpData($this->externalData('admin-valid', 'After')); $edit['id'] = $id; $edit['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->save($this->request('POST', '/admin/downloads/save', $edit))->status()); self::assertSame('After', $this->downloads->download($id)['name']);
        $delete = $this->request('POST', '/admin/downloads/' . $id . '/delete', ['_token' => $this->csrf->token(), 'confirm_delete' => '1'])->withAttributes(['id' => (string) $id]); self::assertSame(303, $this->admin->delete($delete)->status()); self::assertNull($this->downloads->download($id));
        $invalid = ['name' => '', 'slug' => 'invalid', 'description' => 'Body', 'source_type' => 'external', 'external_url' => 'https://example.test', 'status' => 'draft', '_token' => $this->csrf->token()]; self::assertSame(422, $this->admin->save($this->request('POST', '/admin/downloads/save', $invalid))->status());
    }

    public function testMarkdownRendererPreservesFormattingAndRemovesUnsafeMarkup(): void
    {
        $renderer = new ContentRenderer(new HtmlSanitizer(), new MarkdownRenderer(new HtmlSanitizer()));
        $html = $renderer->render('**good** <em>ok</em> [bad](javascript:alert(1)) <img src=x onerror=bad()>', ContentFormat::Markdown, ContentProfile::FullContent);
        self::assertStringContainsString('<strong>good</strong>', $html); self::assertStringContainsString('ok', $html); self::assertStringNotContainsString('javascript:', strtolower($html)); self::assertStringNotContainsString('<script', strtolower($html)); self::assertStringNotContainsString('onerror=', strtolower($html));
    }

    /** @return array<string,mixed> */
    private function externalData(string $slug, string $name): array
    {
        return $this->input->download(['name' => $name, 'slug' => $slug, 'description' => 'Description', 'description_format' => 'html', 'source_type' => 'external', 'external_url' => 'https://example.test/files/' . $slug . '.zip', 'status' => 'draft', 'access_type' => 'public'], true)
            + ['stored_name' => null, 'original_name' => null, 'file_size' => null, 'mime_type' => null];
    }

    /** @return array<string,mixed> */
    private function publishedData(string $slug, string $name, string $access): array
    {
        $data = $this->externalData($slug, $name); $data['status'] = 'published'; $data['published_at'] = gmdate('Y-m-d H:i:s', time() - 60); $data['access_type'] = $access; return $data;
    }

    /** @return array<string,mixed> */
    private function data(string $slug, string $name, string $description, string $source): array
    {
        $data = $this->input->download(['name' => $name, 'slug' => $slug, 'description' => $description, 'source_type' => $source, 'status' => 'draft', 'access_type' => 'public'], true); return $data;
    }

    /** @param array<string,mixed> $data */
    private function httpData(array $data): array
    {
        $data['description_format'] ??= 'html'; $data['requirements_format'] ??= 'html'; return $data;
    }

    private function inputUpload(string $path, string $name): void
    {
        (new DownloadUploadValidator())->validate(['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => $name, 'size' => filesize($path)]);
    }

    private function createUser(string $name): int
    {
        $this->db()->prepare('INSERT INTO users (username,email,password_hash,status,auth_version,created_at,updated_at) VALUES (:u,:e,:p,"active",1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['u' => $name, 'e' => $name . '@example.test', 'p' => password_hash('not-used', PASSWORD_DEFAULT)]); return (int) $this->db()->lastInsertId();
    }

    private function roleId(string $slug): int
    {
        $statement = $this->db()->prepare('SELECT id FROM roles WHERE slug=:slug'); $statement->execute(['slug' => $slug]); return (int) $statement->fetchColumn();
    }

    private function grant(int $userId, string $roleSlug, string $permission): void
    {
        $role = $this->roleId($roleSlug); $this->db()->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id,created_at) SELECT :role,id,UTC_TIMESTAMP() FROM permissions WHERE slug=:permission')->execute(['role' => $role, 'permission' => $permission]); $this->db()->prepare('INSERT IGNORE INTO user_roles (user_id,role_id,created_at) VALUES (:user,:role,UTC_TIMESTAMP())')->execute(['user' => $userId, 'role' => $role]);
    }

    private function loginAs(int $userId): void { $this->session->put('_auth_user_id', $userId); $this->session->put('_auth_version', 1); }

    private function request(string $method, string $path, array $input): Request { return new Request($method, $path, [], $input, [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'DownloadsAcceptance']); }

    private function removeTree(string $directory): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($directory);
    }
}

final class FakeDownloadMembership implements MembershipManagerInterface
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
