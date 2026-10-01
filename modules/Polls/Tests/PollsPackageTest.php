<?php

declare(strict_types=1);

namespace Modules\Polls\Tests;

use Modules\Polls\src\PollInput;
use NovaNuke\Core\ModuleApi;
use NovaNuke\Core\Modules\ModuleManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PollsPackageTest extends TestCase
{
    public function testPackageProvidesItsCompleteModuleSurface(): void
    {
        $module = dirname(__DIR__);
        $manifest = ModuleManifest::fromArray(
            json_decode((string) file_get_contents($module . '/module.json'), true, 32, JSON_THROW_ON_ERROR),
            $module,
        );
        self::assertSame('polls', $manifest->slug);
        self::assertSame('1.2.0', $manifest->version);
        self::assertSame(ModuleApi::VERSION, $manifest->apiVersion);
        self::assertSame([], $manifest->dependencies);
        self::assertSame('/polls', $manifest->navigation['url']);
        foreach ([
            'database/migrations/2026_09_07_000001_create_polls_tables.php',
            'database/migrations/2026_09_07_000002_restore_poll_block.php',
            'src/PollsModule.php', 'src/PollInput.php', 'src/PollRepository.php', 'src/PollService.php',
            'src/PublicPollsController.php', 'src/AdminPollsController.php',
            'views/index.twig', 'views/show.twig', 'views/block.twig', 'views/admin/index.twig',
            'language/en.json', 'language/es.json', 'README.md',
        ] as $file) self::assertFileExists($module . '/' . $file, $file);
    }

    public function testProviderOwnsItsAdminPresentationAndDynamicBlockIntegration(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/PollsModule.php');
        self::assertStringContainsString("'polls.manage','poll','resources'", $source);
        self::assertStringContainsString('EventName::BLOCK_RENDERING', $source);
        self::assertStringContainsString("'polls-active'", $source);
        foreach (['polls.index', 'polls.show'] as $route) self::assertStringContainsString("'{$route}'", $source);
    }

    public function testCataloguesCoverPublicAdminAndBackendTranslationKeys(): void
    {
        $module = dirname(__DIR__);
        $en = json_decode((string) file_get_contents($module . '/language/en.json'), true, 512, JSON_THROW_ON_ERROR);
        $es = json_decode((string) file_get_contents($module . '/language/es.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($en), array_keys($es));
        foreach (['views/index.twig', 'views/show.twig', 'views/block.twig', 'views/admin/index.twig'] as $file) {
            $source = (string) file_get_contents($module . '/' . $file);
            preg_match_all("/trans\\('polls::([a-z0-9_.-]+)'/", $source, $matches);
            self::assertNotEmpty($matches[1], $file);
            foreach ($matches[1] as $key) self::assertArrayHasKey($key, $en, $file . ': ' . $key);
        }
        foreach (['message.voted', 'error.csrf', 'error.not_found', 'error.already_voted', 'admin.message.saved', 'admin.error.forbidden'] as $key) {
            self::assertArrayHasKey($key, $en, $key);
        }
        $public = (string) file_get_contents($module . '/src/PublicPollsController.php');
        $admin = (string) file_get_contents($module . '/src/AdminPollsController.php');
        self::assertStringContainsString('private function error(string', $public);
        self::assertStringContainsString('private function error(string', $admin);
    }

    public function testVotingFormKeepsProgressivePostFallback(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/views/show.twig');
        self::assertStringContainsString('action="/polls/{{ poll.id }}/vote"', $source);
        self::assertStringContainsString('data-ajax-replace=".poll-detail"', $source);
    }

    public function testInputNormalizesAMultipleChoicePoll(): void
    {
        $data = (new PollInput())->poll(['question' => '<b>Best color?</b>', 'options' => "Blue\nGreen\nRed", 'status' => 'active', 'allow_multiple' => '1', 'max_selections' => '9', 'starts_at' => '2026-09-01T10:00', 'ends_at' => '2026-09-02T10:00']);
        self::assertSame('Best color?', $data['question']);
        self::assertSame(['Blue', 'Green', 'Red'], $data['options']);
        self::assertSame(3, $data['max_selections']);
        self::assertSame('2026-09-01 10:00:00', $data['starts_at']);
    }

    #[DataProvider('invalidPolls')]
    public function testInputRejectsInvalidPollDefinitions(array $input): void
    {
        $this->expectException(RuntimeException::class);
        (new PollInput())->poll($input);
    }

    public static function invalidPolls(): array
    {
        return [
            [['question' => 'No?', 'options' => "Yes\nNo"]],
            [['question' => 'Valid question?', 'options' => 'Only one']],
            [['question' => 'Valid question?', 'options' => "Yes\nyes"]],
            [['question' => 'Valid question?', 'options' => "Yes\nNo", 'starts_at' => '2026-09-02T10:00', 'ends_at' => '2026-09-01T10:00']],
        ];
    }
}
