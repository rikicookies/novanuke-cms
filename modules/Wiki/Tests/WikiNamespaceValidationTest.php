<?php

declare(strict_types=1);

namespace Modules\Wiki\Tests;

use Modules\Wiki\src\WikiRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WikiNamespaceValidationTest extends TestCase
{
    public function testExactDatabaseLimitsAreAccepted(): void
    {
        $namespace = str_repeat('a', 190);
        WikiRepository::validateNamespaceMoveTargets([['id' => 1, 'from' => 'old:page', 'to' => $namespace . ':' . str_repeat('b', 120)]]);
        self::assertTrue(true);
    }

    public function testNamespaceBeyondDatabaseLimitIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        WikiRepository::validateNamespaceMoveTargets([['id' => 1, 'from' => 'old:page', 'to' => str_repeat('a', 191) . ':page']]);
    }

    public function testDeepDescendantExpansionIsValidatedBeforeMutation(): void
    {
        $this->expectException(RuntimeException::class);
        WikiRepository::validateNamespaceMoveTargets([['id' => 1, 'from' => 'old:child:page', 'to' => str_repeat('a', 186) . ':child:page']]);
    }

    public function testMultibytePathIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        WikiRepository::validateNamespaceMoveTargets([['id' => 1, 'from' => 'old:page', 'to' => 'carpeta:niño']]);
    }
}
