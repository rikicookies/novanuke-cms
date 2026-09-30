<?php

declare(strict_types=1);

namespace NovaNuke\Core\Security;

use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;

final class AdminAccessGate
{
    public function __construct(private readonly ?Translator $translator = null) {}

    /** @param array<string,mixed>|null $user */
    public function guard(Request $request, ?array $user, bool $allowed): ?Response
    {
        if (! $this->protects($request)) {
            return null;
        }
        if ($user === null) {
            return Response::redirect('/login');
        }
        return $allowed ? null : Response::html(
            $this->translator?->translate('error.forbidden') ?? 'Forbidden',
            403,
        );
    }

    public function protects(Request $request): bool
    {
        $path = $request->path();
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }
}
