<?php

declare(strict_types=1);

namespace Modules\Comments\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use RuntimeException;

final class PublicCommentsController
{
    public function __construct(
        private readonly CommentService $comments, private readonly AuthManager $auth,
        private readonly ActivityLogger $activity, private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session, private readonly Translator $translator,
    ) {
    }

    public function create(Request $request): Response
    {
        return $this->perform($request, function () use ($request): array {
            $type = (string) $request->attribute('type');
            $contentId = $this->id($request->attribute('id'));
            $id = $this->comments->create($request, $type, $contentId);
            return [$id, \NovaNuke\Core\Events\EventName::COMMENT_CREATED, 'comments::message.submitted'];
        });
    }

    public function edit(Request $request): Response
    {
        return $this->perform($request, function () use ($request): array {
            $id = $this->id($request->attribute('id'));
            $this->comments->edit($request, $id);
            return [$id, 'comment.edited', 'comments::message.updated'];
        });
    }

    public function report(Request $request): Response
    {
        return $this->perform($request, function () use ($request): array {
            $commentId = $this->id($request->attribute('id'));
            $reportId = $this->comments->report($request, $commentId);
            return [$reportId, 'comment.reported', 'comments::message.reported'];
        });
    }

    public function react(Request $request): Response
    {
        return $this->perform($request, function () use ($request): array {
            $commentId = $this->id($request->attribute('id'));
            $this->comments->react($commentId, $request->input('reaction'));
            return [$commentId, 'comment.reacted', 'comments::message.reacted'];
        });
    }

    private function perform(Request $request, callable $operation): Response
    {
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('comments::error.csrf'), 419);
        $returnTo = $this->returnTo($request->input('return_to'));
        try {
            [$id, $action, $message] = $operation();
            $user = $this->auth->user();
            $this->activity->log($user ? (int) $user['id'] : null, $action, 'comment', $id, [], $request->ip());
            $this->session->put('comments.message', $this->translator->translate($message));
            return Response::redirect($returnTo, 303);
        } catch (CommentTargetNotFound) {
            return Response::html($this->translator->translate('comments::error.not_found'), 404);
        } catch (RuntimeException $error) {
            $this->session->put('comments.error', $this->error($error->getMessage()));
            return Response::redirect($returnTo, 303);
        }
    }

    private function error(string $message): string
    {
        $keys = [
            'Invalid comment identifier.' => 'invalid_identifier', 'Comment not found.' => 'comment_not_found',
            'The reply target is invalid.' => 'reply_target', 'Maximum reply depth reached.' => 'reply_depth',
            'You already reported this comment.' => 'already_reported', 'This comment can no longer be edited.' => 'no_longer_editable',
            'Invalid comment target.' => 'invalid_target', 'Sign in to comment.' => 'sign_in_comment',
            'Too many comments. Please wait before trying again.' => 'too_many_comments', 'Guest name must contain 2-100 characters.' => 'guest_name',
            'Sign in to edit comments.' => 'sign_in_edit', 'Sign in to react to comments.' => 'sign_in_react',
            'Invalid reaction.' => 'invalid_reaction', 'Report reason must contain 5-500 characters.' => 'report_reason',
            'Too many reports. Please wait before trying again.' => 'too_many_reports', 'Comment must contain 2-5000 characters.' => 'comment_length',
            'Comment must contain visible text.' => 'visible_text', 'APP_KEY is required for comment identity protection.' => 'app_key',
        ];
        return isset($keys[$message]) ? $this->translator->translate('comments::error.' . $keys[$message]) : $message;
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('Invalid comment identifier.');
        return (int) $id;
    }

    private function returnTo(mixed $value): string
    {
        $value = (string) $value;
        if ($value === ''
            || ! str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            return '/';
        }
        return $value;
    }
}
