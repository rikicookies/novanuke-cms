<?php

declare(strict_types=1);

namespace Modules\Pages\src;

use NovaNuke\Core\Comments\CommentProviderInterface;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Content\ContentFormat;
use NovaNuke\Core\Content\ContentProfile;
use NovaNuke\Core\Content\ContentRendererInterface;
use Twig\Markup;

final class PublicPagesController
{
    public function __construct(
        private readonly PageRepository $pages, private readonly AuthManager $auth,
        private readonly SessionManager $session, private readonly ViewRenderer $views,
        private readonly EventDispatcher $events, private readonly ContentRendererInterface $contentRenderer,
        private readonly ?CommentProviderInterface $comments = null,
        private readonly ?CsrfTokenManager $csrf = null, private readonly ?AuthorizationService $authorization = null,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        return Response::html($this->views->render('@pages/index.twig', [
            'pages' => $this->pages->directory($user ? (int) $user['id'] : null),
            'manage_url' => $user !== null && $this->authorization?->allows((int) $user['id'], 'pages.edit')
                ? '/admin/pages'
                : null,
        ]));
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->attribute('slug');
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) return Response::html($this->translate('error.not_found', 'Page not found.'), 404);
        $page = $this->pages->publicPage($slug);
        if ($page === null) return Response::html($this->translate('error.not_found', 'Page not found.'), 404);
        $user = $this->auth->user();
        if (! $this->pages->canView($page, $user ? (int) $user['id'] : null)) return $user === null ? Response::redirect('/login') : Response::html($this->translate('error.unavailable_account', 'This page is not available for your account.'), 403);
        if ($page['parent_id'] !== null && ($page['parent_title'] === null || ! $this->pages->canView([
            'id' => $page['parent_id'], 'access_type' => $page['parent_access_type'],
        ], $user ? (int) $user['id'] : null))) {
            $page['parent_title'] = null; $page['parent_slug'] = null;
        }
        $page['content_html'] = new Markup($this->contentRenderer->render(
            (string) $page['content'],
            ContentFormat::fromInput($page['content_format'] ?? null),
            ContentProfile::FullContent,
        ), 'UTF-8');
        $rendering = new PageRendering($page);
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::PAGE_RENDERING, $rendering);
        $page = $rendering->page;
        $template = in_array($page['template'] ?? null, ['default', 'landing'], true) ? (string) $page['template'] : 'default';
        $editUrl = $user !== null && $this->authorization?->allows((int) $user['id'], 'pages.edit')
            ? '/admin/pages/' . (int) $page['id'] . '/edit'
            : null;
        $data = [
            'page' => $page,
            'contact_form' => $this->contactForm($request, $page),
            'comments_available' => false,
            'edit_url' => $editUrl,
            'delete_url' => $editUrl !== null ? '/admin/pages/' . (int) $page['id'] . '/delete' : null,
            'content_csrf_token' => $editUrl !== null ? $this->csrf?->token() : null,
            'delete_return_to' => '/pages',
        ];
        if ($this->comments !== null && $this->csrf !== null && (int) $page['comments_enabled'] === 1) {
            $data = array_merge($data, [
                'comments_available' => true, 'comments' => $this->comments->for('pages', (int) $page['id']),
                'comments_guests_allowed' => $this->comments->guestsAllowed(), 'comments_csrf_token' => $this->csrf->token(),
                'comments_return_to' => '/pages/' . $page['slug'], 'comments_message' => $this->session->pull('comments.message'),
                'comments_error' => $this->session->pull('comments.error'), 'comments_user' => $user,
            ]);
        }
        return Response::html($this->views->render('@pages/' . $template . '.twig', $data));
    }

    /** @param array<string, mixed> $page
     *  @return array{action:string,csrf_token:?string,result:?string,return_to:string}
     */
    private function contactForm(Request $request, array $page): array
    {
        $result = $request->query('form');
        $result = is_string($result) && in_array($result, ['sent', 'invalid', 'limited', 'unavailable'], true)
            ? $result
            : null;

        return [
            'action' => '/forms/contact',
            'csrf_token' => $this->csrf?->token(),
            'result' => $result,
            'return_to' => '/pages/' . (string) $page['slug'],
        ];
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate('pages::' . $key) ?? $fallback;
    }
}
