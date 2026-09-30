<?php

declare(strict_types=1);

return [
    'headers_enabled' => env_bool('SECURITY_HEADERS_ENABLED', true),
    'hsts_enabled' => env_bool('SECURITY_HSTS_ENABLED', false),
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', '31536000'),
];
