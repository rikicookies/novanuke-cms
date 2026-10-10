<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use Modules\WebLinks\src\AdminWebLinksController;
use Modules\WebLinks\src\PublicWebLinksController;
use Modules\WebLinks\src\WebLinkInput;
use Modules\WebLinks\src\WebLinkManager;
use Modules\WebLinks\src\WebLinkRepository;
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
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;
use RuntimeException;

final class WebLinksFunctionalAcceptanceTest extends MySqlIntegrationTestCase
{
    private WebLinkRepository $links;
    private WebLinkInput $input;
    private WebLinkManager $manager;
    private FakeWebLinksMembership $membership;
    private int $authorId;
    private SessionManager $session;
    private CsrfTokenManager $csrf;
    private AuthManager $auth;
    private AuthorizationService $authorization;
    private AdminWebLinksController $admin;
    private PublicWebLinksController $public;

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        (new Migrator($database))->run(dirname(__DIR__, 2) . '/database/migrations');
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/WebLinks/module.json'), true, 32, JSON_THROW_ON_ERROR);
        (new ModuleMigrator($database))->run(ModuleManifest::fromArray($manifest, dirname(__DIR__, 2) . '/modules/WebLinks'));
        $statement = $database->prepare(
            'INSERT INTO permissions (name,slug,description,module_slug,created_at,updated_at) VALUES (:name,:slug,:description,:module,UTC_TIMESTAMP(),UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),module_slug=VALUES(module_slug),updated_at=UTC_TIMESTAMP()'
        );
        $statement->execute(['name' => 'Web Links Manage', 'slug' => 'web-links.manage', 'description' => 'Permission provided by Web Links.', 'module' => 'web-links']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->membership = new FakeWebLinksMembership();
        $this->links = new WebLinkRepository($this->db(), $this->membership);
        $this->input = new WebLinkInput();
        $this->authorId = $this->createUser('weblinks-author');
        $this->session = new SessionManager('novanuke_weblinks_acceptance', false);
        $this->csrf = new CsrfTokenManager($this->session);
        $events = new EventDispatcher();
        $this->auth = new AuthManager($this->db(), $this->session, $events);
        $this->authorization = new AuthorizationService($this->db());
        $this->manager = new WebLinkManager($this->links, $this->auth, new DatabaseRateLimiter($this->db(), 5, 3600, 'weblinks-submissions'), new DatabaseRateLimiter($this->db(), 5, 3600, 'weblinks-reports'), 'test-weblinks-app-key');
        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        $translator->addNamespace('web-links', dirname(__DIR__, 2) . '/modules/WebLinks/language');
        $views = new ViewRenderer(dirname(__DIR__, 2) . '/resources/views', sys_get_temp_dir() . '/novanuke-weblinks-views-' . bin2hex(random_bytes(4)), true, $translator);
        $views->addNamespace('admin-core', dirname(__DIR__, 2) . '/resources/views');
        $views->addNamespace('web-links', dirname(__DIR__, 2) . '/modules/WebLinks/views');
        $views->addNamespace('admin-web-links', dirname(__DIR__, 2) . '/modules/WebLinks/views/admin');
        foreach (['cms_name' => 'NovaNuke Test', 'cms_url' => 'https://novanuke.test', 'cms_locale' => 'en', 'cms_date_format' => 'Y-m-d', 'cms_timezone' => 'UTC', 'current_user' => null, 'blocks' => [], 'menus' => ['primary' => []], 'admin_navigation' => [], 'cms_version' => '0.4.0-beta.1'] as $key => $value) $views->addGlobal($key, $value);
        $renderer = new ContentRenderer(new HtmlSanitizer(), new MarkdownRenderer(new HtmlSanitizer()));
        $this->admin = new AdminWebLinksController($this->links, $this->input, $this->auth, $this->authorization, new ActivityLogger($this->db()), $this->csrf, $this->session, $views, $translator);
        $this->public = new PublicWebLinksController($this->links, $this->input, $this->manager, $this->auth, $this->csrf, $this->session, $views, $this->authorization, $renderer, $translator);
    }

    public function testCreateEditPreservesIdentityAndUrlContent(): void
    {
        $id = $this->links->save(null, $this->publishedData('nova-home', 'Nova Home', 'https://example.test/home'), $this->authorId);
        $before = $this->links->find($id);
        $edit = $this->publishedData('nova-home', 'Updated Nova Home', 'https://example.test/updated');
        $this->links->save($id, $edit, $this->authorId);
        $after = $this->links->find($id);
        self::assertSame($id, (int) $after['id']);
        self::assertSame($before['submitted_by'], $after['submitted_by']);
        self::assertSame('Updated Nova Home', $after['title']);
        self::assertSame('https://example.test/updated', $after['url']);
        self::assertSame('published', $after['status']);
    }

    public function testDuplicateSlugAndInvalidCategoryLeaveDatabaseUnchanged(): void
    {
        $first = $this->links->save(null, $this->publishedData('same-link', 'First'), $this->authorId);
        $second = $this->links->save(null, $this->publishedData('other-link', 'Second'), $this->authorId);
        try { $this->links->save($second, $this->publishedData('same-link', 'Rejected'), $this->authorId); self::fail('Duplicate slugs must be rejected.'); }
        catch (RuntimeException $error) { self::assertSame('The link slug is already in use.', $error->getMessage()); }
        self::assertSame('other-link', $this->links->find($second)['slug']);
        self::assertSame('Second', $this->links->find($second)['title']);
        self::assertNotSame($first, $second);
        self::assertSame(2, (int) $this->db()->query('SELECT COUNT(*) FROM web_links')->fetchColumn());
        try { $this->links->save(null, array_replace($this->publishedData('invalid-category', 'Invalid'), ['category_id' => 999999999]), $this->authorId); self::fail('Invalid categories must be rejected.'); }
        catch (RuntimeException $error) { self::assertSame('Selected category does not exist.', $error->getMessage()); }
        self::assertNull($this->links->publicBySlug('invalid-category'));
    }

    public function testUrlValidationRejectsDangerousSchemesCredentialsAndControls(): void
    {
        $messages = [];
        foreach (['javascript:alert(1)', 'file:///tmp/private', 'https://user:pass@example.test/a', 'https://example.test/a' . "\n" . 'X'] as $url) {
            try { $this->input->link(['title' => 'Unsafe', 'slug' => 'unsafe-' . random_int(1, 999999), 'url' => $url, 'description' => 'Body'], true); }
            catch (RuntimeException $error) { $messages[] = $error->getMessage(); }
        }
        self::assertCount(4, $messages);
        self::assertSame(['URL must use safe HTTP or HTTPS without embedded credentials.'], array_values(array_unique($messages)));
        try { $this->input->link(['title' => 'Unsafe image', 'slug' => 'unsafe-image', 'url' => 'https://example.test', 'description' => 'Body', 'image_path' => '/uploads/../secret.png'], true); self::fail('Unsafe image paths must be rejected.'); }
        catch (RuntimeException $error) { self::assertSame('Image must be a safe path below /uploads/.', $error->getMessage()); }
    }

    public function testPendingPublishedVisibilityAndSoftDeletion(): void
    {
        $pending = $this->links->save(null, $this->pendingData('pending-link', 'Pending'), $this->authorId);
        $published = $this->links->save(null, $this->publishedData('visible-link', 'Visible'), $this->authorId);
        $rejectedData = $this->publishedData('rejected-link', 'Rejected'); $rejectedData['status'] = 'rejected'; $rejected = $this->links->save(null, $rejectedData, $this->authorId);
        self::assertNull($this->links->publicBySlug('pending-link'));
        self::assertNull($this->links->publicBySlug('rejected-link'));
        self::assertSame($published, (int) $this->links->publicBySlug('visible-link')['id']);
        self::assertSame(1, $this->links->catalog(1, null, '', 'new', null)['total']);
        $this->links->delete($published);
        self::assertNull($this->links->publicBySlug('visible-link'));
        self::assertSame($pending, (int) $this->links->find($pending)['id']);
        self::assertSame($rejected, (int) $this->links->find($rejected)['id']);
    }

    public function testCategoriesAssignAndFilterPublicListing(): void
    {
        $category = $this->links->saveCategory(['name' => 'Education', 'slug' => 'education', 'description' => 'Learning resources']);
        $data = $this->publishedData('education-link', 'Education link'); $data['category_id'] = $category;
        $id = $this->links->save(null, $data, $this->authorId);
        self::assertSame($category, (int) $this->links->find($id)['category_id']);
        self::assertSame(1, $this->links->catalog(1, 'education', '', 'new', null)['total']);
        try { $this->links->saveCategory($this->input->category(['name' => 'Broken', 'slug' => 'broken', 'description' => str_repeat('x', 501)])); self::fail('Oversized category descriptions must be rejected by input.'); }
        catch (RuntimeException $error) { self::assertSame('Category description cannot exceed 500 characters.', $error->getMessage()); }
        try { $this->links->saveCategory(['name' => 'Duplicate', 'slug' => 'education', 'description' => 'Duplicate']); self::fail('Duplicate category slugs must be rejected.'); }
        catch (RuntimeException $error) { self::assertSame('The category slug is already in use.', $error->getMessage()); }
    }

    public function testMemberAndVipAudienceRequiresPersistedVisibilityState(): void
    {
        $member = $this->links->save(null, $this->publishedData('members-link', 'Members', 'https://example.test/members', 'member'), $this->authorId);
        $vip = $this->links->save(null, $this->publishedData('vip-link', 'VIP', 'https://example.test/vip', 'vip'), $this->authorId);
        self::assertFalse($this->links->canView($this->links->find($member), null));
        $user = $this->createUser('weblinks-member');
        self::assertTrue($this->links->canView($this->links->find($member), $user));
        self::assertFalse($this->links->canView($this->links->find($vip), $user));
        $this->membership->vip = true;
        self::assertTrue($this->links->canView($this->links->find($vip), $user));
        self::assertSame(1, $this->links->catalog(1, null, '', 'new', $user)['total']);
        $this->db()->prepare('INSERT INTO user_entitlements (user_id,entitlement,starts_at,expires_at,created_at,updated_at) VALUES (:user,"vip",UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['user' => $user]);
        self::assertSame(2, $this->links->catalog(1, null, '', 'new', $user)['total']);
    }

    public function testPublicSubmissionRequiresLoginCsrfAndCreatesPendingLink(): void
    {
        self::assertSame(302, $this->public->submitForm()->status());
        $user = $this->createUser('weblinks-submitter'); $this->loginAs($user);
        $data = $this->submissionData('submitted-link', 'Submitted link');
        self::assertSame(419, $this->public->submit($this->request('POST', '/links/submit', $data))->status());
        $data['_token'] = 'invalid'; self::assertSame(419, $this->public->submit($this->request('POST', '/links/submit', $data))->status());
        $data['_token'] = $this->csrf->token(); self::assertSame(303, $this->public->submit($this->request('POST', '/links/submit', $data))->status());
        $submittedId = (int) $this->db()->query("SELECT id FROM web_links WHERE slug='submitted-link'")->fetchColumn();
        $row = $this->links->find($submittedId);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame('public', $row['audience']);
        self::assertSame($user, (int) $row['submitted_by']);
    }

    public function testSubmissionRateLimitRejectsSixthSubmission(): void
    {
        $user = $this->createUser('weblinks-rate-limited'); $this->loginAs($user);
        for ($number = 1; $number <= 5; $number++) {
            $id = $this->manager->submit($this->input->link(['title' => 'Submission ' . $number, 'slug' => 'submission-' . $number, 'url' => 'https://example.test/' . $number, 'description' => 'Body'], false));
            self::assertGreaterThan(0, $id);
        }
        try { $this->manager->submit($this->input->link(['title' => 'Submission 6', 'slug' => 'submission-6', 'url' => 'https://example.test/6', 'description' => 'Body'], false)); self::fail('The sixth submission must be rate limited.'); }
        catch (RuntimeException $error) { self::assertSame('Too many submissions. Please wait before trying again.', $error->getMessage()); }
        self::assertSame(5, (int) $this->db()->query('SELECT COUNT(*) FROM web_links')->fetchColumn());
    }

    public function testAdminApprovalAndRejectionRequireManagePermission(): void
    {
        $pending = $this->links->save(null, $this->pendingData('moderated-link', 'Moderated'), $this->authorId);
        self::assertSame(302, $this->admin->create()->status());
        $user = $this->createUser('weblinks-no-permission'); $this->loginAs($user); self::assertSame(403, $this->admin->index()->status());
        $unauthorized = $this->httpData($this->publishedData('moderated-link', 'Unauthorized')); $unauthorized['id'] = $pending; $unauthorized['_token'] = $this->csrf->token();
        self::assertSame(403, $this->admin->save($this->request('POST', '/admin/web-links/save', $unauthorized))->status());
        $manager = $this->createUser('weblinks-manager'); $this->grant($manager, 'editor', 'web-links.manage'); $this->loginAs($manager);
        $publish = $this->httpData($this->publishedData('moderated-link', 'Approved')); $publish['id'] = $pending; $publish['_token'] = $this->csrf->token();
        self::assertSame(303, $this->admin->save($this->request('POST', '/admin/web-links/save', $publish))->status());
        self::assertSame('published', $this->links->find($pending)['status']);
        $rejected = $this->httpData($this->publishedData('moderated-link', 'Rejected')); $rejected['id'] = $pending; $rejected['status'] = 'rejected'; $rejected['_token'] = $this->csrf->token();
        self::assertSame(303, $this->admin->save($this->request('POST', '/admin/web-links/save', $rejected))->status());
        self::assertSame('rejected', $this->links->find($pending)['status']);
    }

    public function testVisitRedirectCountsOncePerVisitorAndAcceptsOnlySafeUrls(): void
    {
        $id = $this->links->save(null, $this->publishedData('visit-link', 'Visit', 'https://example.test/destination'), $this->authorId);
        $first = $this->public->visit($this->request('GET', '/links/visit-link/visit')->withAttributes(['slug' => 'visit-link']));
        self::assertSame(302, $first->status()); self::assertSame('https://example.test/destination', $first->header('Location')); self::assertSame('no-referrer', $first->header('Referrer-Policy'));
        $this->public->visit($this->request('GET', '/links/visit-link/visit')->withAttributes(['slug' => 'visit-link']));
        $this->public->visit($this->request('GET', '/links/visit-link/visit', [], ['REMOTE_ADDR' => '127.0.0.2', 'HTTP_USER_AGENT' => 'Visitor-B'])->withAttributes(['slug' => 'visit-link']));
        self::assertSame(2, (int) $this->db()->query('SELECT visit_count FROM web_links WHERE id=' . $id)->fetchColumn());
        self::assertSame(2, (int) $this->db()->query('SELECT COUNT(*) FROM web_link_visits WHERE link_id=' . $id)->fetchColumn());
        $unsafe = $this->links->find($id); $unsafe['url'] = 'javascript:alert(1)';
        try { \NovaNuke\Core\Http\Response::externalRedirect($unsafe['url']); self::fail('Unsafe redirect schemes must be rejected.'); }
        catch (\InvalidArgumentException $error) { self::assertSame('External redirects require a safe HTTP or HTTPS URL.', $error->getMessage()); }
    }

    public function testVisitRejectsPendingDeletedAndRestrictedLinks(): void
    {
        $pending = $this->links->save(null, $this->pendingData('pending-visit', 'Pending'), $this->authorId);
        $deleted = $this->links->save(null, $this->publishedData('deleted-visit', 'Deleted'), $this->authorId); $this->links->delete($deleted);
        $private = $this->links->save(null, $this->publishedData('member-visit', 'Member', 'https://example.test/member', 'member'), $this->authorId);
        self::assertSame(404, $this->public->visit($this->request('GET', '/links/pending-visit/visit')->withAttributes(['slug' => 'pending-visit']))->status());
        self::assertSame(404, $this->public->visit($this->request('GET', '/links/deleted-visit/visit')->withAttributes(['slug' => 'deleted-visit']))->status());
        self::assertSame(404, $this->public->visit($this->request('GET', '/links/member-visit/visit')->withAttributes(['slug' => 'member-visit']))->status());
        self::assertSame(0, (int) $this->db()->query('SELECT COALESCE(SUM(visit_count),0) FROM web_links WHERE id IN (' . $pending . ',' . $deleted . ',' . $private . ')')->fetchColumn());
    }

    public function testReportsAreCsrfProtectedDeduplicatedAndModerated(): void
    {
        $id = $this->links->save(null, $this->publishedData('report-link', 'Reportable'), $this->authorId);
        $missing = $this->request('POST', '/links/' . $id . '/report', ['slug' => 'report-link', 'reason' => 'Broken destination'])->withAttributes(['id' => (string) $id]);
        self::assertSame(419, $this->public->report($missing)->status());
        $valid = $this->request('POST', '/links/' . $id . '/report', ['_token' => $this->csrf->token(), 'slug' => 'report-link', 'reason' => 'Broken destination'])->withAttributes(['id' => (string) $id]);
        self::assertSame(303, $this->public->report($valid)->status());
        $duplicate = $this->request('POST', '/links/' . $id . '/report', ['_token' => $this->csrf->token(), 'slug' => 'report-link', 'reason' => 'Broken destination'])->withAttributes(['id' => (string) $id]);
        self::assertSame(303, $this->public->report($duplicate)->status());
        self::assertSame(1, count($this->links->reports()));
        $reportId = (int) $this->db()->query('SELECT id FROM web_link_reports WHERE link_id=' . $id)->fetchColumn();
        $manager = $this->createUser('weblinks-report-manager'); $this->grant($manager, 'editor', 'web-links.manage'); $this->loginAs($manager);
        $resolve = $this->request('POST', '/admin/web-link-reports/' . $reportId . '/resolve', ['_token' => $this->csrf->token()])->withAttributes(['id' => (string) $reportId]);
        self::assertSame(303, $this->admin->resolve($resolve)->status()); self::assertCount(0, $this->links->reports());
    }

    public function testAdminCrudCategoryCsrfAndValidationResponses(): void
    {
        $manager = $this->createUser('weblinks-crud-manager'); $this->grant($manager, 'editor', 'web-links.manage'); $this->loginAs($manager);
        $data = $this->httpData($this->publishedData('controller-link', 'Before')); self::assertSame(419, $this->admin->save($this->request('POST', '/admin/web-links/save', $data))->status());
        $data['_token'] = 'invalid'; self::assertSame(419, $this->admin->save($this->request('POST', '/admin/web-links/save', $data))->status());
        $data['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->save($this->request('POST', '/admin/web-links/save', $data))->status());
        $id = (int) $this->db()->query("SELECT id FROM web_links WHERE slug='controller-link'")->fetchColumn();
        $edit = $this->httpData($this->publishedData('controller-link', 'After')); $edit['id'] = $id; $edit['_token'] = $this->csrf->token(); self::assertSame(303, $this->admin->save($this->request('POST', '/admin/web-links/save', $edit))->status()); self::assertSame('After', $this->links->find($id)['title']);
        $category = ['name' => 'Controller category', 'slug' => 'controller-category', 'description' => '', '_token' => $this->csrf->token()]; self::assertSame(303, $this->admin->category($this->request('POST', '/admin/web-links/category', $category))->status()); self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM web_link_categories WHERE slug='controller-category'")->fetchColumn());
        $delete = $this->request('POST', '/admin/web-links/' . $id . '/delete', ['_token' => $this->csrf->token(), 'confirm_delete' => '1'])->withAttributes(['id' => (string) $id]); self::assertSame(303, $this->admin->delete($delete)->status()); self::assertNull($this->links->find($id));
        $invalid = ['title' => '', 'slug' => 'invalid', 'url' => 'https://example.test', 'description' => 'Body', '_token' => $this->csrf->token()]; self::assertSame(422, $this->admin->save($this->request('POST', '/admin/web-links/save', $invalid))->status());
    }

    public function testPublicListingDetailCategoryAndRenderedContentAreSafe(): void
    {
        $category = $this->links->saveCategory(['name' => 'Safe category', 'slug' => 'safe-category', 'description' => 'Safe']);
        $data = $this->publishedData('safe-content', 'Safe content'); $data['category_id'] = $category; $data['description'] = '<script>alert(1)</script><p onclick="bad()"><strong>Good</strong></p>';
        $this->links->save(null, $data, $this->authorId);
        $listing = $this->public->index($this->request('GET', '/links')); self::assertSame(200, $listing->status()); self::assertStringContainsString('Safe content', $listing->content()); self::assertStringNotContainsString('<script', strtolower($listing->content()));
        $categoryListing = $this->public->index($this->request('GET', '/links/category/safe-category'), 'safe-category'); self::assertSame(200, $categoryListing->status()); self::assertStringContainsString('Safe content', $categoryListing->content());
        $show = $this->public->show($this->request('GET', '/links/safe-content')->withAttributes(['slug' => 'safe-content'])); self::assertSame(200, $show->status()); self::assertStringContainsString('<strong>Good</strong>', $show->content()); self::assertStringNotContainsString('<script', strtolower($show->content())); self::assertStringNotContainsString('onclick=', strtolower($show->content()));
        self::assertSame(404, $this->public->index($this->request('GET', '/links/category/../bad'), '../bad')->status());
    }

    public function testMarkdownRendererPreservesSupportedFormattingAndRemovesUnsafeMarkup(): void
    {
        $renderer = new ContentRenderer(new HtmlSanitizer(), new MarkdownRenderer(new HtmlSanitizer()));
        $html = $renderer->render('**good** [bad](javascript:alert(1)) <img src=x onerror=bad()>', ContentFormat::Markdown, ContentProfile::Description);
        self::assertStringContainsString('<strong>good</strong>', $html);
        self::assertStringNotContainsString('javascript:', strtolower($html));
        self::assertStringNotContainsString('<script', strtolower($html));
        self::assertStringNotContainsString('onerror=', strtolower($html));
    }

    public function testMissingAppKeyFailsClosedForVisitIdentity(): void
    {
        $id = $this->links->save(null, $this->publishedData('app-key-link', 'App key'), $this->authorId);
        $manager = new WebLinkManager($this->links, $this->auth, new DatabaseRateLimiter($this->db(), 5, 3600, 'weblinks-app-key-submit'), new DatabaseRateLimiter($this->db(), 5, 3600, 'weblinks-app-key-report'), '');
        try { $manager->visit('app-key-link', $this->request('GET', '/links/app-key-link/visit')->withAttributes(['slug' => 'app-key-link'])); self::fail('Visit identity must fail closed without an app key.'); }
        catch (RuntimeException $error) { self::assertSame('APP_KEY is required for Web Links protection.', $error->getMessage()); }
        self::assertSame(0, (int) $this->db()->query('SELECT visit_count FROM web_links WHERE id=' . $id)->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function publishedData(string $slug, string $title, string $url = 'https://example.test/link', string $audience = 'public'): array
    {
        return $this->input->link(['title' => $title, 'slug' => $slug, 'url' => $url, 'description' => 'Description', 'description_format' => 'html', 'status' => 'published', 'audience' => $audience], true);
    }

    /** @return array<string,mixed> */
    private function pendingData(string $slug, string $title): array
    {
        return $this->input->link(['title' => $title, 'slug' => $slug, 'url' => 'https://example.test/' . $slug, 'description' => 'Description', 'description_format' => 'html'], false);
    }

    /** @return array<string,mixed> */
    private function submissionData(string $slug, string $title): array
    {
        return ['title' => $title, 'slug' => $slug, 'url' => 'https://example.test/' . $slug, 'description' => 'Submitted description', 'description_format' => 'markdown'];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function httpData(array $data): array { return $data + ['description_format' => 'html']; }

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

    private function request(string $method, string $path, array $input = [], array $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'WebLinksAcceptance']): Request { return new Request($method, $path, [], $input, [], [], $server); }
}

final class FakeWebLinksMembership implements MembershipManagerInterface
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
