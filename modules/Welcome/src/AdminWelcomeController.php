<?php

declare(strict_types=1);

namespace Modules\Welcome\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\View\ViewRenderer;
final class AdminWelcomeController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly SettingsRepository $settings,
        private readonly WelcomeContentResolver $content,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly Translator $translator,
    ) {
    }

    public function show(): Response
    {
        if ($guard = $this->guard()) return $guard;
        return $this->view($this->content->formValues(), [], (bool) $this->session->pull('welcome.saved', false));
    }

    public function save(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translator->translate('admin.error.csrf'), 419);
        }

        $result = $this->content->validate($request->allInput());
        if ($result['errors'] !== []) return $this->view($result['data'], $result['errors'], false, 422);

        $settings = [];
        foreach (['en', 'es'] as $locale) {
            foreach ($this->content->fields() as $field => $_definition) {
                $input = 'welcome_' . $locale . '_' . str_replace('.', '_', $field);
                $settings["welcome.content.{$locale}.{$field}"] = [
                    'value' => $result['data'][$input], 'type' => 'string', 'group' => 'welcome',
                ];
            }
        }
        foreach ($this->content->urlDefaults() as $key => $_default) {
            $input = 'welcome_link_' . $key . '_url';
            $settings['welcome.link.' . $key . '.url'] = [
                'value' => $result['data'][$input], 'type' => 'string', 'group' => 'welcome',
            ];
        }
        $before = $this->content->formValues();
        $this->settings->setMany($settings);
        $changed = [];
        foreach ($result['data'] as $field => $value) if (($before[$field] ?? null) !== $value) $changed[] = $field;

        $user = $this->auth->user();
        $this->activity->log((int) $user['id'], 'welcome.content.updated', 'module', 'welcome', [
            'changed_fields' => implode(',', $changed),
        ], $request->ip());
        $this->session->put('welcome.saved', true);
        return Response::redirect('/admin/welcome', 303);
    }

    /** @param array<string,string> $values @param array<string,string> $errors */
    private function view(array $values, array $errors, bool $saved, int $status = 200): Response
    {
        return Response::html($this->views->render('@admin-welcome/index.twig', [
            'sections' => $this->content->sections(),
            'values' => $values,
            'errors' => $errors,
            'saved' => $saved,
            'csrf_token' => $this->csrf->token(),
        ]), $status);
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if (! $this->authorization->allows((int) $user['id'], 'welcome.manage')) {
            return Response::html($this->translator->translate('admin.error.forbidden'), 403);
        }
        return null;
    }
}
