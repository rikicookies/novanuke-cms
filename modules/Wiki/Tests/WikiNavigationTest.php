<?php

declare(strict_types=1);

namespace Modules\Wiki\Tests;

use Modules\Wiki\src\WikiNavigation;
use PHPUnit\Framework\TestCase;

final class WikiNavigationTest extends TestCase
{
    public function testStructuralPagesUseLogicalPathOrderIncludingZeroPaddedPrefixes(): void
    {
        $navigation = new WikiNavigation();
        $tree = $navigation->sitemap([
            ['id' => 9, 'namespace' => '', 'slug' => '10-advanced', 'title' => 'Advanced'],
            ['id' => 2, 'namespace' => '', 'slug' => '02-html', 'title' => 'HTML'],
            ['id' => 1, 'namespace' => '', 'slug' => '01-introduction', 'title' => 'Introduction'],
        ]);

        self::assertSame(['01-introduction', '02-html', '10-advanced'], array_column($tree['pages'], 'slug'));
    }

    public function testNestedNamespaceNodesAndPagesAreOrderedByFullPath(): void
    {
        $navigation = new WikiNavigation();
        $tree = $navigation->sitemap([
            ['id' => 4, 'namespace' => 'guides:install', 'slug' => '02-second', 'title' => 'Second'],
            ['id' => 3, 'namespace' => 'guides:install', 'slug' => '01-first', 'title' => 'First'],
            ['id' => 2, 'namespace' => 'reference', 'slug' => 'index', 'title' => 'Reference'],
            ['id' => 1, 'namespace' => 'guides', 'slug' => 'index', 'title' => 'Guides'],
        ]);

        self::assertSame(['guides', 'reference'], array_column($tree['namespaces'], 'path'));
        self::assertSame(['01-first', '02-second'], array_column($tree['namespaces'][0]['namespaces'][0]['pages'], 'slug'));
    }

    public function testNonZeroPaddedValuesFollowLexicalOrdering(): void
    {
        $navigation = new WikiNavigation();
        $tree = $navigation->sitemap([
            ['id' => 1, 'namespace' => '', 'slug' => '2', 'title' => 'Two'],
            ['id' => 2, 'namespace' => '', 'slug' => '10', 'title' => 'Ten'],
            ['id' => 3, 'namespace' => '', 'slug' => '1', 'title' => 'One'],
        ]);

        self::assertSame(['1', '10', '2'], array_column($tree['pages'], 'slug'));
    }
}
