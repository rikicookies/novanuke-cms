<?php

declare(strict_types=1);

return [
    'mailer' => env('MAIL_MAILER', 'log'),
    'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@localhost'),
    'from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'NovaNuke')),
    'log_path' => dirname(__DIR__) . '/storage/logs/mail.log',
    'host' => env('MAIL_HOST', ''),
    'port' => (int) env('MAIL_PORT', '465'),
    'username' => env('MAIL_USERNAME', ''),
    'password' => env('MAIL_PASSWORD', ''),
    'encryption' => env('MAIL_ENCRYPTION', 'ssl'),
    'timeout' => (int) env('MAIL_TIMEOUT', '15'),
];
