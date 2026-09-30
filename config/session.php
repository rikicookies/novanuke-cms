<?php

declare(strict_types=1);

return [
    'name' => env('SESSION_NAME', 'novanuke_session'),
    'secure' => env_bool('SESSION_SECURE', false),
    'same_site' => env('SESSION_SAME_SITE', 'Lax'),
    'lifetime' => (int) env('SESSION_LIFETIME', '7200'),
    'idle_timeout' => (int) env('SESSION_IDLE_TIMEOUT', '1800'),
    'rotation_interval' => (int) env('SESSION_ROTATION_INTERVAL', '900'),
    'path' => env('SESSION_PATH', '/'),
    'domain' => env('SESSION_DOMAIN', ''),
];
