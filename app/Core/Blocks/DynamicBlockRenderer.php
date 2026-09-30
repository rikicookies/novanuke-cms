<?php

declare(strict_types=1);

namespace NovaNuke\Core\Blocks;

use Closure;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Logging\SensitiveDataRedactor;
use Throwable;

final class DynamicBlockRenderer
{
    private readonly Closure $logger;

    public function __construct(
        private readonly EventDispatcher $events,
        private readonly SensitiveDataRedactor $redactor,
        ?Closure $logger = null,
    ) {
        $this->logger = $logger ?? static fn (string $message): bool => error_log($message);
    }

    /** @param array<string,mixed> $block */
    public function render(array $block): ?string
    {
        try {
            $rendering = new BlockRendering($block);
            $this->events->dispatch(\NovaNuke\Core\Events\EventName::BLOCK_RENDERING, $rendering);
            return $rendering->html;
        } catch (Throwable $error) {
            $type = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($block['type'] ?? 'unknown')) ?: 'unknown';
            $id = max(0, (int) ($block['id'] ?? 0));
            ($this->logger)(sprintf(
                'Dynamic block rendering failed for type %s (ID %d): %s',
                $type,
                $id,
                $this->redactor->redact($error->getMessage()),
            ));
            return null;
        }
    }
}
