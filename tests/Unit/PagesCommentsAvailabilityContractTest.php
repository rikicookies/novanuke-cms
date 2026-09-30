<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PagesCommentsAvailabilityContractTest extends TestCase
{
    public function testEnabledCommentsCanOverrideDefaultUnavailableState(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/Pages/src/PublicPagesController.php');

        self::assertStringContainsString("'comments_available' => false", $source);
        self::assertStringContainsString('array_merge($data, [', $source);
        self::assertStringContainsString("'comments_available' => true", $source);
        self::assertStringNotContainsString('$data += [', $source);
    }
}
