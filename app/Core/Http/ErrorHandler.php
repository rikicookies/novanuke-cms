<?php

declare(strict_types=1);

namespace NovaNuke\Core\Http;

use Closure;
use ErrorException;
use NovaNuke\Core\Http\Routing\MethodNotAllowed;
use NovaNuke\Core\Http\Routing\RouteNotFound;
use Throwable;
use NovaNuke\Core\Logging\SensitiveDataRedactor;
use NovaNuke\Core\I18n\Translator;

final class ErrorHandler
{
    private bool $rendering = false;

    public function __construct(
        private readonly bool $debug,
        private readonly string $logPath,
        private readonly SensitiveDataRedactor $redactor = new SensitiveDataRedactor(),
        private readonly ?string $projectRoot = null,
        private readonly ?Translator $translator = null,
        private readonly ?Closure $logger = null,
        private readonly ?Closure $translate = null,
        private readonly ?ErrorPageRenderer $pageRenderer = null,
    ) {
    }

    public function register(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line) {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public function render(Throwable $error): Response
    {
        if ($this->rendering) {
            return EmergencyResponse::response();
        }

        $this->rendering = true;
        try {
            $id = $this->reference();
            $status = match (true) {
                $error instanceof RouteNotFound => 404,
                $error instanceof MethodNotAllowed => 405,
                default => 500,
            };

            $this->writeLog($id, $error);

            $copy = $this->copy($status, $id);
            $message = $this->debug
                ? $error::class . ': ' . $this->redactor->redact($error->getMessage())
                : $copy['description'];

            return $this->page($status, $copy['heading'], $message, $status === 500 ? $id : null, $this->debug ? $message : null);
        } catch (Throwable) {
            return EmergencyResponse::response();
        } finally {
            $this->rendering = false;
        }
    }

    public function renderStatus(int $status): Response
    {
        if ($this->rendering) {
            return EmergencyResponse::response();
        }

        $this->rendering = true;
        try {
            $copy = $this->copy($status, '');
            return $this->page($status, $copy['heading'], $copy['description'], null, null);
        } catch (Throwable) {
            return EmergencyResponse::response();
        } finally {
            $this->rendering = false;
        }
    }

    /** @return array{heading:string,description:string} */
    private function copy(int $status, string $reference): array
    {
        $fallbacks = match ($status) {
            403 => ['This area is off limits.', 'Access to this area is not available.'],
            404 => ['This page wandered off the map.', 'The requested page was not found.'],
            405 => ['That action is not available here.', 'The requested method is not allowed.'],
            503 => ['We are tuning things up.', 'The site is temporarily unavailable. Please try again shortly.'],
            default => ['Something went sideways.', "An unexpected error occurred. Reference: {$reference}"],
        };

        $description = match ($status) {
            404 => $this->translate('error.not_found', [], $fallbacks[1]),
            405 => $this->translate('error.method_not_allowed', [], $fallbacks[1]),
            503 => $this->translate('maintenance.message', [], $fallbacks[1]),
            500 => $this->translate('error.unexpected', ['reference' => $reference], $fallbacks[1]),
            default => $this->translate("error.page.{$status}.description", [], $fallbacks[1]),
        };

        return [
            'heading' => $this->translate("error.page.{$status}.heading", [], $fallbacks[0]),
            'description' => $description,
        ];
    }

    private function page(int $status, string $heading, string $description, ?string $reference, ?string $debugDetail): Response
    {
        if ($this->pageRenderer !== null) {
            return $this->pageRenderer->render([
                'status' => $status,
                'title' => $this->translate('error.page.title', [], $this->translate('error.title', [], 'Error')),
                'brand' => $this->translate('error.page.brand', [], 'NovaNuke'),
                'status_label' => $this->translate('error.page.status', ['status' => (string) $status], "HTTP {$status}"),
                'heading' => $heading,
                'description' => $description,
                'reference' => $reference,
                'reference_label' => $this->translate('error.page.reference', [], 'Reference:'),
                'debug_detail' => $debugDetail,
                'debug_label' => $this->translate('error.page.debug_details', [], 'Technical details'),
                'action_label' => $this->translate('error.page.actions', [], 'Recovery actions'),
                'home_url' => '/',
                'home_label' => $this->translate('error.page.home', [], 'Go home'),
                'footer' => $this->translate('error.page.footer', [], 'Core error page'),
                'locale' => $this->safeLocale(),
            ]);
        }

        $title = $this->translate('error.title', [], 'Error');
        $locale = $this->safeLocale();
        return Response::html(
            '<!doctype html><html lang="' . htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><meta charset="utf-8"><title>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '<h1>' . $status . '</h1><p>' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
            $status,
        );
    }

    /** @param array<string, scalar|null> $parameters */
    private function translate(string $key, array $parameters, string $fallback): string
    {
        try {
            if ($this->translate !== null) {
                return ($this->translate)($key, $parameters) ?: $fallback;
            }

            return $this->translator?->translate($key, $parameters) ?? $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function reference(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    private function safeLocale(): string
    {
        try {
            return $this->translator?->locale() ?? 'en';
        } catch (Throwable) {
            return 'en';
        }
    }

    private function logFile(string $file): string
    {
        if ($this->debug) {
            return $file;
        }

        $root = $this->projectRoot !== null ? realpath($this->projectRoot) : null;
        $resolved = realpath($file);
        if ($root !== false && $root !== null && $resolved !== false && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            return '[APP]/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($root) + 1));
        }

        return '[INTERNAL]';
    }

    private function writeLog(string $id, Throwable $error): void
    {
        try {
            if ($this->logger !== null) {
                ($this->logger)($id, $error);
                return;
            }

            $directory = dirname($this->logPath);
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return;
            }

            $line = sprintf(
                "[%s] %s %s: %s in %s:%d%s",
                gmdate('c'),
                $id,
                $error::class,
                $this->redactor->redact($error->getMessage()),
                $this->logFile($error->getFile()),
                $error->getLine(),
                PHP_EOL,
            );
            @error_log($line, 3, $this->logPath);
        } catch (Throwable) {
            // Error reporting must never depend on diagnostic storage.
        }
    }
}
