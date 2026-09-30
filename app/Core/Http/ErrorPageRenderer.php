<?php

declare(strict_types=1);

namespace NovaNuke\Core\Http;

use NovaNuke\Core\View\ViewRenderer;

/**
 * Renders the dependency-light, normal-operation error document.
 *
 * The caller owns the small, already-translated data contract. This renderer
 * deliberately uses only the protected core view namespace; it never loads a
 * theme, menu, block, module, settings value, or database record.
 */
final class ErrorPageRenderer
{
    public function __construct(private readonly ViewRenderer $views) {}

    /** @param array<string, mixed> $data */
    public function render(array $data): Response
    {
        return Response::html(
            $this->views->render('@error-core/errors/page.twig', $data),
            (int) ($data['status'] ?? 500),
        );
    }
}
