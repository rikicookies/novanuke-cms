<?php

declare(strict_types=1);

namespace Modules\Wiki\Tests;

use PHPUnit\Framework\TestCase;

final class WikiScalabilityContractTest extends TestCase
{
    public function testAdminListingUsesWhitelistedSqlPaginationAndDoesNotScanMissingLinks(): void
    {
        $repository = (string) file_get_contents(dirname(__DIR__) . '/src/WikiRepository.php');
        $controller = (string) file_get_contents(dirname(__DIR__) . '/src/AdminWikiController.php');

        self::assertStringContainsString('LIMIT :limit OFFSET :offset', $repository);
        self::assertStringContainsString("'path' => 'w.namespace ASC,w.slug ASC,w.id ASC'", $repository);
        self::assertStringContainsString("'updated' => 'w.updated_at DESC,w.id DESC'", $repository);
        self::assertStringContainsString("'missing_links' => \$this->pages->missingLinks()", $controller);
        self::assertStringContainsString("'pages' => \$result['items']", $controller);
        self::assertStringNotContainsString("'missing_links' => \$this->pages->missingLinks(),\n            'message'", $controller);
    }

    public function testMissingLinksHasAnExplicitModuleOwnedRoute(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__) . '/src/WikiModule.php');
        $view = dirname(__DIR__) . '/views/admin/missing-links.twig';

        self::assertStringContainsString("'/admin/wiki/missing-links'", $module);
        self::assertFileExists($view);
    }
}
