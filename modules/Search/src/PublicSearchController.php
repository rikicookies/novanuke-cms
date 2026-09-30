<?php

declare(strict_types=1);

namespace Modules\Search\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Search\SearchProviderInterface;
use NovaNuke\Core\Search\SearchProvidersRegistering;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class PublicSearchController
{
    public function __construct(
        private readonly EventDispatcher $events,
        private readonly SearchRepository $repository,
        private readonly SettingsRepository $settings,
        private readonly AuthManager $auth,
        private readonly ViewRenderer $views,
        private readonly Translator $translator,
    ) {
    }

    public function index(Request $request): Response
    {
        $term = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $registry = new SearchProviderRegistry();
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::SEARCH_PROVIDERS_REGISTERING, new SearchProvidersRegistering($registry));
        $types = array_map(static fn (SearchProviderInterface $provider): array => [
            'value' => $provider->type(), 'label' => $provider->label(),
        ], array_values($registry->all()));

        $result = null; $error = null;
        if ($term !== '') {
            try {
                $user = $this->auth->user();
                $result = (new SearchService(
                    $registry,
                    new SafeHighlighter(),
                    $this->settings->integer('site.per_page', 10, 5, 100),
                ))->search($term, $type, (int) $page, $user ? (int) $user['id'] : null);
                if ((int) $page === 1 && $this->settings->boolean('search.log_terms', false)) {
                    $this->repository->record(mb_strtolower($term, 'UTF-8'));
                }
            } catch (RuntimeException $exception) {
                $error = $this->error($exception->getMessage());
            }
        }

        return Response::html($this->views->render('@search/index.twig', [
            'term' => $term, 'selected_type' => $type, 'types' => $types,
            'result' => $result, 'error' => $error,
        ]), $error === null ? 200 : 422);
    }

    private function error(string $message): string
    {
        $keys = [
            'Search terms must contain between 2 and 100 characters.' => 'term_length',
            'Unknown search content type.' => 'unknown_type',
        ];
        return isset($keys[$message]) ? $this->translator->translate('search::error.' . $keys[$message]) : $message;
    }
}
