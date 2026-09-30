<?php

declare(strict_types=1);

namespace NovaNuke\Core\Security;

use NovaNuke\Core\Http\Response;

final class SecurityHeaders
{
    public function __construct(
        private readonly bool $enabled,
        private readonly bool $hstsEnabled,
        private readonly int $hstsMaxAge,
        private readonly string $appUrl,
        private readonly string $environment,
    ) {
    }

    public function apply(Response $response): Response
    {
        if (! $this->enabled) {
            return $response;
        }

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'",
        ];

        if ($this->environment === 'production'
            && strtolower((string) parse_url($this->appUrl, PHP_URL_SCHEME)) === 'https') {
            $defaults['Content-Security-Policy'] .= '; upgrade-insecure-requests';
        }

        foreach ($defaults as $name => $value) {
            if ($response->header($name) === null) {
                $response = $response->withHeader($name, $value);
            }
        }

        if ($this->hstsEnabled && $this->environment === 'production'
            && strtolower((string) parse_url($this->appUrl, PHP_URL_SCHEME)) === 'https') {
            $maxAge = max(300, min($this->hstsMaxAge, 63072000));
            $response = $response->withHeader('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }
}
