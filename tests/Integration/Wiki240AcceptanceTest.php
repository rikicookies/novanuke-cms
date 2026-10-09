<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use Modules\Wiki\src\WikiInput;
use Modules\Wiki\src\WikiLinkIndexer;
use Modules\Wiki\src\WikiRepository;
use NovaNuke\Core\Database\Migrator;
use NovaNuke\Core\Membership\MembershipManagerInterface;
use NovaNuke\Core\Modules\ModuleManifest;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;
use PDOException;
use RuntimeException;

final class Wiki240AcceptanceTest extends MySqlIntegrationTestCase
{
    private WikiRepository $wiki;
    private int $authorId;

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        (new Migrator($database))->run(dirname(__DIR__, 2) . '/database/migrations');
        $manifestData = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/Wiki/module.json'), true, 32, JSON_THROW_ON_ERROR);
        $manifest = ModuleManifest::fromArray($manifestData, dirname(__DIR__, 2) . '/modules/Wiki');
        (new ModuleMigrator($database))->run($manifest);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->prepare(
            'INSERT INTO users (username,email,password_hash,status,created_at,updated_at) VALUES (:username,:email,:password,:status,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute([
            'username' => 'wiki-acceptance', 'email' => 'wiki-acceptance@example.test',
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'status' => 'active',
        ]);
        $this->authorId = (int) $this->db()->lastInsertId();
        $membership = new class implements MembershipManagerInterface {
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
        };
        $this->wiki = new WikiRepository($this->db(), $membership, new WikiLinkIndexer(new WikiInput()));
    }

    public function testFreshAndIdempotentWikiMigrationsCreateAliasSchema(): void
    {
        self::assertSame(5, (int) $this->db()->query("SELECT COUNT(*) FROM module_migrations WHERE module_slug='wiki'")->fetchColumn());
        self::assertSame([], (new ModuleMigrator($this->db()))->run(ModuleManifest::fromArray(
            json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/Wiki/module.json'), true, 32, JSON_THROW_ON_ERROR),
            dirname(__DIR__, 2) . '/modules/Wiki',
        )));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wiki_path_aliases'")->fetchColumn());
        self::assertSame('utf8mb4', (string) $this->db()->query("SELECT CCSA.character_set_name FROM information_schema.TABLES T JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA ON CCSA.collation_name=T.table_collation WHERE T.table_schema=DATABASE() AND T.table_name='wiki_path_aliases'")->fetchColumn());
        self::assertSame(311, (int) $this->db()->query("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wiki_path_aliases' AND column_name='historical_path'")->fetchColumn());
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='wiki_path_aliases' AND index_name='wiki_path_aliases_path_unique' AND non_unique=0")->fetchColumn());
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND constraint_name='wiki_path_aliases_page_fk'")->fetchColumn());
    }

    public function testNamespaceMovePreservesIdentityRevisionsAttachmentsAndAliases(): void
    {
        $direct = $this->createPage('old', 'one', 'Original', 'first');
        $descendant = $this->createPage('old:child', 'two', 'Child', 'child');
        $outside = $this->createPage('outside', 'three', 'Outside', 'outside');
        $this->db()->prepare('INSERT INTO wiki_attachments (wiki_page_id,uploaded_by,original_name,stored_name,mime_type,file_size,created_at) VALUES (:page,:user,:original,:stored,:mime,:size,UTC_TIMESTAMP())')->execute([
            'page' => $direct, 'user' => $this->authorId, 'original' => 'one.txt', 'stored' => str_repeat('a', 64), 'mime' => 'text/plain', 'size' => 5,
        ]);
        $plan = $this->wiki->renameNamespace('old', 'new', true);
        self::assertSame(2, $plan['affected_count']);
        self::assertSame('new:one', $this->pagePath($direct));
        self::assertSame('new:child:two', $this->pagePath($descendant));
        self::assertSame('outside:three', $this->pagePath($outside));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM wiki_page_revisions WHERE wiki_page_id={$direct}")->fetchColumn());
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM wiki_attachments WHERE wiki_page_id={$direct}")->fetchColumn());
        self::assertSame('new:one', $this->wiki->publishedByAlias('old:one')['path']);

        $this->wiki->renameNamespace('new', 'final', true);
        self::assertSame('final:one', $this->pagePath($direct));
        self::assertSame('final:one', $this->wiki->publishedByAlias('old:one')['path']);
        self::assertSame('final:one', $this->wiki->publishedByAlias('new:one')['path']);
    }

    public function testCanonicalAndAliasCollisionsRollbackWithoutPartialMutation(): void
    {
        $original = $this->createPage('old', 'one', 'Original', 'original');
        $moving = $this->createPage('other', 'one', 'Moving', 'moving');
        $this->wiki->renameNamespace('old', 'new', false);
        self::assertSame('new:one', $this->pagePath($original));
        $this->expectException(RuntimeException::class);
        try {
            $this->wiki->renameNamespace('other', 'old', false);
        } finally {
            self::assertSame('other:one', $this->pagePath($moving));
            self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM wiki_path_aliases WHERE historical_path='old:one'")->fetchColumn());
        }
    }

    public function testAliasOwnershipIsImmutableAndSamePageReuseIsAllowed(): void
    {
        $page = $this->createPage('old', 'one', 'Original', 'original');
        $this->wiki->renameNamespace('old', 'new', false);
        $this->wiki->renameNamespace('new', 'old', false);
        $this->wiki->renameNamespace('old', 'new', false);

        self::assertSame('new:one', $this->pagePath($page));
        self::assertSame($page, (int) $this->db()->query("SELECT wiki_page_id FROM wiki_path_aliases WHERE historical_path='old:one'")->fetchColumn());
        self::assertSame($page, (int) $this->db()->query("SELECT wiki_page_id FROM wiki_path_aliases WHERE historical_path='new:one'")->fetchColumn());

        $owner = $this->createPage('owner', 'page', 'Owner', 'owner');
        $moving = $this->createPage('moving', 'page', 'Moving', 'moving');
        $this->db()->prepare('INSERT INTO wiki_path_aliases (historical_path,wiki_page_id,created_at) VALUES (:path,:page,UTC_TIMESTAMP())')
            ->execute(['path' => 'moving:page', 'page' => $owner]);

        $this->expectException(RuntimeException::class);
        try {
            $this->wiki->renameNamespace('moving', 'destination', false);
        } finally {
            self::assertSame('moving:page', $this->pagePath($moving));
            self::assertSame($owner, (int) $this->db()->query("SELECT wiki_page_id FROM wiki_path_aliases WHERE historical_path='moving:page'")->fetchColumn());
        }
    }

    public function testCompetingTransactionsCannotReassignAnAliasAfterLockRelease(): void
    {
        $owner = $this->createPage('owner', 'page', 'Owner', 'owner');
        $other = $this->createPage('other', 'page', 'Other', 'other');
        $first = $this->newIntegrationConnection();
        $second = $this->newIntegrationConnection();
        $path = 'race:page';

        $first->beginTransaction();
        $first->prepare('INSERT INTO wiki_path_aliases (historical_path,wiki_page_id,created_at) VALUES (:path,:page,UTC_TIMESTAMP())')
            ->execute(['path' => $path, 'page' => $owner]);
        $first->prepare('SELECT wiki_page_id FROM wiki_path_aliases WHERE historical_path=:path FOR UPDATE')
            ->execute(['path' => $path]);

        $second->exec('SET SESSION innodb_lock_wait_timeout=1');
        $second->beginTransaction();
        try {
            $second->prepare('INSERT INTO wiki_path_aliases (historical_path,wiki_page_id,created_at) VALUES (:path,:page,UTC_TIMESTAMP())')
                ->execute(['path' => $path, 'page' => $other]);
            self::fail('A competing insert unexpectedly bypassed the alias row lock.');
        } catch (PDOException $error) {
            self::assertContains((int) $error->errorInfo[1], [1205, 1213]);
        } finally {
            if ($second->inTransaction()) $second->rollBack();
        }
        $first->commit();

        $second->beginTransaction();
        try {
            $second->prepare('INSERT INTO wiki_path_aliases (historical_path,wiki_page_id,created_at) VALUES (:path,:page,UTC_TIMESTAMP())')
                ->execute(['path' => $path, 'page' => $other]);
            self::fail('A duplicate historical path was accepted for another page.');
        } catch (PDOException $error) {
            self::assertSame('23000', (string) $error->getCode());
        } finally {
            if ($second->inTransaction()) $second->rollBack();
        }

        self::assertSame($owner, (int) $this->db()->query("SELECT wiki_page_id FROM wiki_path_aliases WHERE historical_path='{$path}'")->fetchColumn());
    }

    public function testDestinationBoundaryAndUnsupportedCharactersAreRejectedBeforeWrite(): void
    {
        $page = $this->createPage('old', str_repeat('p', 120), 'Boundary', 'content');
        $this->wiki->renameNamespace('old', str_repeat('a', 190), false);
        self::assertSame(str_repeat('a', 190) . ':' . str_repeat('p', 120), $this->pagePath($page));
        $this->expectException(RuntimeException::class);
        try {
            $this->wiki->renameNamespace(str_repeat('a', 190), str_repeat('b', 191), false);
        } finally {
            self::assertSame(str_repeat('a', 190) . ':' . str_repeat('p', 120), $this->pagePath($page));
        }
    }

    public function testRevisionRestoreKeepsCurrentPathAndAliases(): void
    {
        $page = $this->createPage('old', 'page', 'Title', 'historical content');
        $this->wiki->save($page, ['namespace' => 'old', 'slug' => 'page', 'title' => 'Current', 'content' => 'current content', 'status' => 'published', 'audience' => 'public', 'comments_enabled' => 0], $this->authorId);
        $this->wiki->renameNamespace('old', 'new', false);
        $revisionId = (int) $this->db()->query("SELECT id FROM wiki_page_revisions WHERE wiki_page_id={$page} AND revision_number=1")->fetchColumn();
        $result = $this->wiki->restore($page, $revisionId, $this->authorId, true);
        self::assertSame('new:page', $result['path']);
        self::assertSame('new:page', $this->pagePath($page));
        self::assertSame('historical content', (string) $this->db()->query("SELECT content FROM wiki_pages WHERE id={$page}")->fetchColumn());
        self::assertSame('new:page', $this->wiki->publishedByAlias('old:page')['path']);
        self::assertSame(3, (int) $this->db()->query("SELECT COUNT(*) FROM wiki_page_revisions WHERE wiki_page_id={$page}")->fetchColumn());
    }

    private function createPage(string $namespace, string $slug, string $title, string $content): int
    {
        return $this->wiki->save(null, [
            'namespace' => $namespace, 'slug' => $slug, 'title' => $title, 'content' => $content,
            'status' => 'published', 'audience' => 'public', 'comments_enabled' => 0,
        ], $this->authorId);
    }

    private function pagePath(int $id): string
    {
        $row = $this->db()->query("SELECT namespace,slug FROM wiki_pages WHERE id={$id}")->fetch();
        return ((string) $row['namespace'] === '' ? '' : $row['namespace'] . ':') . $row['slug'];
    }

    private function newIntegrationConnection(): PDO
    {
        $host = (string) env('NOVANUKE_TEST_DB_HOST', '127.0.0.1');
        $port = (int) env('NOVANUKE_TEST_DB_PORT', '3306');
        $username = (string) env('NOVANUKE_TEST_DB_USERNAME', 'root');
        $password = (string) env('NOVANUKE_TEST_DB_PASSWORD', '');
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$this->integrationDatabaseName()};charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }
}
