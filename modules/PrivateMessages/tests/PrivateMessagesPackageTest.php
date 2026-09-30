<?php

declare(strict_types=1);

namespace Modules\PrivateMessages\Tests;

use Modules\PrivateMessages\src\PrivateMessageInput;
use Modules\PrivateMessages\src\PrivateMessageService;
use NovaNuke\Core\Content\ContentFormat;
use NovaNuke\Core\Messaging\PrivateMessageComposerInterface;
use NovaNuke\Core\Messaging\PrivateMessageSent;
use PHPUnit\Framework\TestCase;

final class PrivateMessagesPackageTest extends TestCase
{
    public function testPackageProvidesItsCompleteStandaloneSurface(): void
    {
        $root = dirname(__DIR__);
        $manifest = json_decode((string) file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('private-messages', $manifest['slug']);
        self::assertSame('1.3.0', $manifest['version']);
        self::assertSame('Modules\\PrivateMessages\\src\\PrivateMessagesModule', $manifest['provider']);
        self::assertSame(['private-messages.moderate'], $manifest['permissions']);
        self::assertSame('member', $manifest['navigation']['audience']);
        self::assertTrue(is_a(PrivateMessageService::class, PrivateMessageComposerInterface::class, true));
        foreach (['README.md', 'src/PrivateMessagesModule.php', 'src/PublicPrivateMessagesController.php', 'src/AdminPrivateMessagesController.php', 'database/migrations/2026_09_06_000001_create_private_messages_tables.php', 'database/migrations/2026_09_08_000002_add_body_format.php'] as $file) self::assertFileExists($root . '/' . $file, $file);
    }

    public function testCataloguesMatchAndViewsUseDeclaredKeys(): void
    {
        $root = dirname(__DIR__);
        $en = json_decode((string) file_get_contents($root . '/language/en.json'), true, 128, JSON_THROW_ON_ERROR);
        $es = json_decode((string) file_get_contents($root . '/language/es.json'), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($en), array_keys($es));
        foreach (glob($root . '/views/*.twig') ?: [] as $view) $this->assertViewKeys($view, $en);
        foreach (glob($root . '/views/admin/*.twig') ?: [] as $view) $this->assertViewKeys($view, $en);
    }

    public function testInputPreservesSourceAndSelectsSafeContentFormats(): void
    {
        $input = new PrivateMessageInput();
        self::assertSame('Riki_01', $input->recipient(' Riki_01 '));
        self::assertSame('Hello world', $input->subject('<b>Hello</b> world'));
        self::assertSame('<strong>Safe message</strong>', $input->body(' <strong>Safe message</strong> '));
        self::assertSame(ContentFormat::Markdown, $input->format(null));
        self::assertSame(ContentFormat::Html, $input->format('html'));
    }

    public function testProviderOwnsAvailabilityAdminPlacementAndCoreEventAlias(): void
    {
        $root = dirname(__DIR__);
        $provider = (string) file_get_contents($root . '/src/PrivateMessagesModule.php');
        self::assertStringContainsString("addGlobal('private_messages_available',true)", $provider);
        self::assertStringContainsString("'private-messages.moderate','message','community'", $provider);
        self::assertStringContainsString('PrivateMessageComposerInterface::class', $provider);
        self::assertInstanceOf(PrivateMessageSent::class, new \Modules\PrivateMessages\src\PrivateMessageSent(9, 12, '34'));
    }

    /** @param array<string,mixed> $catalogue */
    private function assertViewKeys(string $view, array $catalogue): void
    {
        $source = (string) file_get_contents($view);
        preg_match_all("/trans\\('private-messages::([a-z0-9_.-]+)'/", $source, $matches);
        foreach ($matches[1] as $key) self::assertArrayHasKey($key, $catalogue, basename($view) . ': ' . $key);
    }
}
