<?php

declare(strict_types=1);

use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\EmergencyResponse;

$emergency = static function (): void {
    http_response_code(500);
    if (! headers_sent()) {
        @header('Content-Type: text/html; charset=UTF-8', true);
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Internal Server Error</title><h1>500</h1><p>Something went wrong.</p>';
};

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    EmergencyResponse::run(static function (): void {
        $application = require dirname(__DIR__) . '/bootstrap/app.php';
        $application->kernel()->handle(Request::capture())->send();
    });
} catch (\Throwable) {
    $emergency();
}
