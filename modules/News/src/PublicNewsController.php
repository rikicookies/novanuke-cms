<?php

declare(strict_types=1);

namespace Modules\News\src;

use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Comments\CommentProviderInterface;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Content\ContentFormat;
use NovaNuke\Core\Content\ContentProfile;
use NovaNuke\Core\Content\ContentRendererInterface;
use Twig\Markup;

final class PublicNewsController
{
    public function __construct(
        private readonly NewsRepository $news, private readonly SessionManager $session,
        private readonly ViewRenderer $views, private readonly ContentRendererInterface $contentRenderer,
        private readonly ?CommentProviderInterface $comments = null,
        private readonly ?CsrfTokenManager $csrf = null, private readonly ?AuthManager $auth = null,
        private readonly ?AuthorizationService $authorization = null,
        private readonly ?Translator $translator = null,
    )
    {
    }

    public function index(Request $request, ?string $category = null): Response
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $user = $this->auth?->user();
        $result = $this->news->publicArticles((int) $page, $category, $user ? (int) $user['id'] : null);
        foreach ($result['items'] as &$article) {
            $article['summary_html'] = new Markup($this->contentRenderer->render(
                (string) ($article['summary'] ?? ''), ContentFormat::fromInput($article['summary_format'] ?? null), ContentProfile::Description,
            ), 'UTF-8');
        }
        unset($article);
        return Response::html($this->views->render('@news/index.twig', [
            'result' => $result,
            'categories' => $this->news->categories(), 'selected_category' => $category,
            'manage_url' => $user !== null && $this->authorization?->allows((int) $user['id'], 'news.edit')
                ? '/admin/news'
                : null,
        ]));
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->attribute('slug');
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) return Response::html($this->translate('error.not_found', 'News article not found.'), 404);
        $article = $this->news->publicArticle($slug);
        if ($article === null) return Response::html($this->translate('error.not_found', 'News article not found.'), 404);
        $user = $this->auth?->user();
        if (! $this->news->canView($article, $user ? (int) $user['id'] : null)) {
            return $user === null ? Response::redirect('/login') : Response::html($this->translate('error.unavailable_account', 'This article is not available for your account.'), 403);
        }
        $viewed = array_map('intval', (array) $this->session->get('news.viewed', []));
        if (! in_array((int) $article['id'], $viewed, true)) {
            $this->news->incrementViews((int) $article['id']);
            $viewed[] = (int) $article['id'];
            $this->session->put('news.viewed', array_slice(array_unique($viewed), -100));
            $article['view_count'] = (int) $article['view_count'] + 1;
        }
        $article['summary_html'] = new Markup($this->contentRenderer->render(
            (string) ($article['summary'] ?? ''), ContentFormat::fromInput($article['summary_format'] ?? null), ContentProfile::Description,
        ), 'UTF-8');
        $article['summary_text'] = trim(strip_tags((string) $article['summary_html']));
        $article['content_html'] = new Markup($this->contentRenderer->render(
            (string) $article['content'], ContentFormat::fromInput($article['content_format'] ?? null), ContentProfile::FullContent,
        ), 'UTF-8');
        $commentData = ['comments_available' => false];
        if ($this->comments !== null && $this->csrf !== null && (int) $article['comments_enabled'] === 1) {
            $commentData = [
                'comments_available' => true,
                'comments' => $this->comments->for('news', (int) $article['id']),
                'comments_guests_allowed' => $this->comments->guestsAllowed(),
                'comments_csrf_token' => $this->csrf->token(),
                'comments_return_to' => '/news/' . $article['slug'],
                'comments_message' => $this->session->pull('comments.message'),
                'comments_error' => $this->session->pull('comments.error'),
                'comments_user' => $this->auth?->user(),
            ];
        }
        $editUrl = $this->editUrl('news.edit', (int) $article['id'], '/admin/news/%d/edit');
        return Response::html($this->views->render('@news/show.twig', [
            'article' => $article,
            'edit_url' => $editUrl,
            'delete_url' => $editUrl !== null ? '/admin/news/' . (int) $article['id'] . '/delete' : null,
            'content_csrf_token' => $editUrl !== null ? $this->csrf?->token() : null,
            'delete_return_to' => '/news',
        ] + $commentData));
    }

    private function editUrl(string $permission, int $id, string $pattern): ?string
    {
        $user = $this->auth?->user();
        return $user !== null && $this->authorization?->allows((int) $user['id'], $permission)
            ? sprintf($pattern, $id)
            : null;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate('news::' . $key) ?? $fallback;
    }
}
