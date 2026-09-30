<?php

declare(strict_types=1);

namespace NovaNukeTests\Unit;

use NovaNuke\Core\Http\EmergencyResponse;
use NovaNuke\Core\Http\ErrorHandler;
use NovaNuke\Core\Http\Routing\MethodNotAllowed;
use NovaNuke\Core\Http\Routing\RouteNotFound;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CatastrophicErrorHandlingTest extends TestCase
{
    public function testPreKernelFailureUsesDependencyFreeEmergencyResponse(): void
    {
        ob_start();
        EmergencyResponse::run(static function (): void {
            throw new RuntimeException('DB password=super-secret path=C:\\private\\config.php');
        });
        $body = (string) ob_get_clean();

        self::assertSame(500, http_response_code());
        self::assertStringContainsString('Something went wrong.', $body);
        self::assertStringNotContainsString('super-secret', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('config.php', $body);
    }

    public function testEmergencyResponseHasMinimalHtmlContract(): void
    {
        $response = EmergencyResponse::response();

        self::assertSame(500, $response->status());
        self::assertSame('text/html; charset=UTF-8', $response->header('Content-Type'));
        self::assertStringContainsString('<h1>500</h1>', $response->content());
        self::assertStringNotContainsString('trace', strtolower($response->content()));
    }

    public function testErrorHandlerSurvivesLoggingFailure(): void
    {
        $handler = new ErrorHandler(
            false,
            sys_get_temp_dir() . '/novanuke-error-handler-test.log',
            logger: static function (): void { throw new RuntimeException('logger failed'); },
        );

        $response = $handler->render(new RuntimeException('SQL password=secret path=/srv/app/config.php'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('unexpected', strtolower($response->content()));
        self::assertStringNotContainsString('password=secret', $response->content());
        self::assertStringNotContainsString('/srv/app/config.php', $response->content());
    }

    public function testErrorHandlerSurvivesTranslationFailure(): void
    {
        $handler = new ErrorHandler(
            false,
            sys_get_temp_dir() . '/novanuke-error-handler-test.log',
            logger: static function (): void {},
            translate: static function (): string { throw new RuntimeException('translation failed'); },
        );

        $response = $handler->render(new RuntimeException('internal detail'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('unexpected', strtolower($response->content()));
        self::assertStringNotContainsString('internal detail', $response->content());
    }

    public function testKnownStatusesAndGenericExceptionRemainUnchanged(): void
    {
        $handler = new ErrorHandler(false, sys_get_temp_dir() . '/novanuke-error-handler-test.log', logger: static function (): void {});

        self::assertSame(404, $handler->render(new RouteNotFound())->status());
        self::assertSame(405, $handler->render(new MethodNotAllowed())->status());
        self::assertSame(500, $handler->render(new RuntimeException('hidden'))->status());
    }

    public function testDebugOutputRemainsBoundedAndEscaped(): void
    {
        $handler = new ErrorHandler(true, sys_get_temp_dir() . '/novanuke-error-handler-test.log', logger: static function (): void {});
        $response = $handler->render(new RuntimeException('<script>alert(1)</script>'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('RuntimeException', $response->content());
        self::assertStringNotContainsString('<script>', $response->content());
        self::assertStringContainsString('&lt;script&gt;', $response->content());
        self::assertStringNotContainsString('Stack trace', $response->content());
    }

    public function testPublicEntrypointHasOutermostEmergencyBoundary(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $emergency = file_get_contents(dirname(__DIR__, 2) . '/app/Core/Http/EmergencyResponse.php');

        self::assertStringContainsString('EmergencyResponse::run', $source);
        self::assertStringContainsString('catch (Throwable)', $emergency);
        self::assertStringContainsString("require dirname(__DIR__) . '/vendor/autoload.php';", $source);
        self::assertStringContainsString('Something went wrong.', $source);
        self::assertLessThan(
            strpos($source, 'EmergencyResponse::run'),
            strpos($source, "require dirname(__DIR__) . '/vendor/autoload.php';")
        );
    }
}
