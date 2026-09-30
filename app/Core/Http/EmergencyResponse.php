<?php

declare(strict_types=1);

namespace NovaNuke\Core\Http;

use Throwable;

final class EmergencyResponse
{
    private const BODY = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Internal Server Error</title><h1>500</h1><p>Something went wrong.</p>';

    public static function run(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            self::send();
        }
    }

    public static function response(): Response
    {
        return Response::html(self::BODY, 500);
    }

    public static function send(): void
    {
        http_response_code(500);
        if (! headers_sent()) {
            @header('Content-Type: text/html; charset=UTF-8', true);
        }
        echo self::BODY;
    }
}
