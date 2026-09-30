<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests;

use PHPUnit\Framework\TestCase;

final class NotificationsPackageTest extends TestCase
{
    public function testPackageProvidesItsCompleteStandaloneSurface(): void
    {
        $root = dirname(__DIR__);
        $manifest = json_decode((string) file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('notifications', $manifest['slug']);
        self::assertSame('1.2.0', $manifest['version']);
        self::assertSame('Modules\\Notifications\\src\\NotificationsModule', $manifest['provider']);
        self::assertSame('member', $manifest['navigation']['audience']);
        foreach (['README.md', 'src/NotificationsModule.php', 'src/NotificationPublisher.php', 'src/NotificationRepository.php', 'src/PublicNotificationsController.php', 'views/index.twig', 'database/migrations/2026_09_07_000001_create_notifications_table.php'] as $file) {
            self::assertFileExists($root . '/' . $file, $file);
        }
    }

    public function testCataloguesMatchAndViewsUseDeclaredKeys(): void
    {
        $root = dirname(__DIR__);
        $en = json_decode((string) file_get_contents($root . '/language/en.json'), true, 128, JSON_THROW_ON_ERROR);
        $es = json_decode((string) file_get_contents($root . '/language/es.json'), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($en), array_keys($es));
        $view = (string) file_get_contents($root . '/views/index.twig');
        preg_match_all("/trans\\('notifications::([a-z0-9_.-]+)'/", $view, $matches);
        foreach ($matches[1] as $key) self::assertArrayHasKey($key, $en, $key);
        foreach (['error.csrf', 'error.not_found', 'error.invalid_identifier'] as $key) self::assertArrayHasKey($key, $en);
    }

    public function testProviderConsumesCoreEventsWithoutDependingOnProducerModules(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/NotificationsModule.php');
        foreach (['PRIVATE_MESSAGE_SENT', 'COMMENT_CREATED', 'FRIEND_REQUESTED', 'FRIEND_ACCEPTED', 'MEMBERSHIP_ASSIGNED', 'MEMBERSHIP_ACTIVATED', 'MEMBERSHIP_REVOKED', 'MEMBERSHIP_EXPIRED', 'MEMBERSHIP_SCHEDULED', 'MEMBERSHIP_SCHEDULE_CANCELLED', 'MAINTENANCE_PRUNING'] as $event) {
            self::assertStringContainsString('EventName::' . $event, $source, $event);
        }
        self::assertStringNotContainsString('Modules\\Friends', $source);
        self::assertStringNotContainsString('Modules\\PrivateMessages', $source);
        self::assertStringNotContainsString('Modules\\Comments', $source);
    }

    public function testInboxActionsRemainProgressivelyEnhanced(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__) . '/views/index.twig');
        self::assertStringContainsString('action="/notifications/read-all"', $view);
        self::assertStringContainsString('data-ajax-replace=".notifications-page"', $view);
        self::assertStringContainsString('action="/notifications/{{ item.id }}/read"', $view);
    }
}
