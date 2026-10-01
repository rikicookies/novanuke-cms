<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Http\EmergencyResponse;
use NovaNuke\Core\Http\ErrorHandler;
use NovaNuke\Core\Http\ErrorPageRenderer;
use NovaNuke\Core\Http\Routing\MethodNotAllowed;
use NovaNuke\Core\Http\Routing\RouteNotFound;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\View\ViewRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreErrorPagesTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/novanuke-error-pages-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->temporaryRoot)) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temporaryRoot);
    }

    public function testMissingRouteRendersPolished404(): void
    {
        $response = $this->handler()->render(new RouteNotFound());

        self::assertSame(404, $response->status());
        self::assertStringContainsString('nn-error-page', $response->content());
        self::assertStringContainsString('This page wandered off the map.', $response->content());
        self::assertStringContainsString('alert-danger', $response->content());
    }

    public function testGenericProductionFailureIsPolishedButSafe(): void
    {
        $response = $this->handler()->render(new RuntimeException('SQL password=secret /srv/private/config.php'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Reference:', $response->content());
        self::assertStringContainsString('Something went sideways.', $response->content());
        self::assertStringNotContainsString('password=secret', $response->content());
        self::assertStringNotContainsString('/srv/private/config.php', $response->content());
        self::assertStringNotContainsString('RuntimeException', $response->content());
    }

    public function testDebugDetailIsEscapedAndBounded(): void
    {
        $response = $this->handler(true)->render(new RuntimeException('<script>alert(1)</script>'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('RuntimeException', $response->content());
        self::assertStringContainsString('&lt;script&gt;', $response->content());
        self::assertStringNotContainsString('<script>', $response->content());
        self::assertStringNotContainsString('Stack trace', $response->content());
    }

    public function testRendererFailureFallsBackToEmergencyResponse(): void
    {
        $views = new ViewRenderer($this->temporaryRoot, $this->temporaryRoot . '/cache', false);
        $views->addNamespace('error-core', $this->temporaryRoot);
        $handler = new ErrorHandler(
            false,
            $this->temporaryRoot . '/error.log',
            logger: static function (): void {},
            pageRenderer: new ErrorPageRenderer($views),
        );

        $response = $handler->render(new RuntimeException('renderer failed'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Something went wrong.', $response->content());
        self::assertStringNotContainsString('nn-error-page', $response->content());
    }

    public function test405AndSharedStatusPagesPreserveStatus(): void
    {
        $handler = $this->handler();

        self::assertSame(405, $handler->render(new MethodNotAllowed())->status());
        self::assertSame(403, $handler->renderStatus(403)->status());
        self::assertSame(503, $handler->renderStatus(503)->status());
    }

    public function testSpanishCopyRendersThroughCoreTranslations(): void
    {
        $response = $this->handler(false, 'es')->render(new RouteNotFound());

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Esta página se perdió del mapa.', $response->content());
    }

    public function testProgressiveFailureHasRecognizableMarkupButNoReplacementTarget(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/resources/views/errors/page.twig');
        $progressive = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/progressive-actions.js');

        self::assertIsString($template);
        self::assertIsString($progressive);
        self::assertStringContainsString('alert-danger', $template);
        self::assertStringNotContainsString('data-ajax-replace', $template);
        self::assertStringContainsString('if (!incoming || !current) return false;', $progressive);
    }

    public function testCorePageDoesNotDependOnThemesAndEmergencyRemainsMinimal(): void
    {
        $response = $this->handler()->render(new RuntimeException('theme unavailable'));
        $emergency = file_get_contents(dirname(__DIR__, 2) . '/app/Core/Http/EmergencyResponse.php');

        self::assertStringContainsString('nn-error-page', $response->content());
        self::assertIsString($emergency);
        self::assertStringNotContainsString('ViewRenderer', $emergency);
        self::assertStringNotContainsString('Translator', $emergency);
        self::assertStringNotContainsString('nn-error-page', $emergency);
    }

    public function testEnglishAndSpanishCoreKeysExist(): void
    {
        $required = [
            'error.page.title', 'error.page.brand', 'error.page.status', 'error.page.reference',
            'error.page.debug_details', 'error.page.actions', 'error.page.home', 'error.page.footer',
            'error.page.403.heading', 'error.page.403.description', 'error.page.404.heading',
            'error.page.405.heading', 'error.page.500.heading', 'error.page.503.heading',
        ];

        foreach (['en', 'es'] as $locale) {
            $catalogue = $this->translations($locale);
            foreach ($required as $key) self::assertArrayHasKey($key, $catalogue, $locale . ': ' . $key);
        }
    }

    public function testApplicationUsesCoreRendererAndMaintenanceKeepsRetryContract(): void
    {
        $application = file_get_contents(dirname(__DIR__, 2) . '/app/Core/Application.php');
        $kernel = file_get_contents(dirname(__DIR__, 2) . '/app/Core/Http/Kernel.php');

        self::assertStringContainsString("addNamespace('error-core'", $application);
        self::assertStringContainsString('ErrorPageRenderer::class', $application);
        self::assertStringContainsString("renderStatus(503)", $kernel);
        self::assertStringContainsString("withHeader('Retry-After', '900')", $kernel);
        self::assertStringContainsString('if ($maintenance->status() === 503)', $kernel);
    }

    /** @return array<string, string> */
    private function translations(string $locale): array
    {
        $path = dirname(__DIR__, 2) . '/language/' . $locale . '.json';
        $decoded = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function handler(bool $debug = false, string $locale = 'en'): ErrorHandler
    {
        $translator = new Translator($locale, 'en', dirname(__DIR__, 2) . '/language');
        $views = new ViewRenderer(
            dirname(__DIR__, 2) . '/resources/views',
            $this->temporaryRoot . '/cache',
            $debug,
            $translator,
        );
        $views->addNamespace('error-core', dirname(__DIR__, 2) . '/resources/views');

        return new ErrorHandler(
            $debug,
            $this->temporaryRoot . '/error.log',
            translator: $translator,
            logger: static function (): void {},
            pageRenderer: new ErrorPageRenderer($views),
        );
    }
}
