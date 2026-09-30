<?php

declare(strict_types=1);

namespace Modules\Comments\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Comments\CommentCreated;
use NovaNuke\Core\Comments\CommentProviderInterface;
use NovaNuke\Core\Comments\CommentTargetChecking;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Security\DatabaseRateLimiter;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\Content\ContentFormat;
use NovaNuke\Core\Content\ContentProfile;
use NovaNuke\Core\Content\ContentRendererInterface;
use Twig\Markup;
use RuntimeException;

final class CommentService implements CommentProviderInterface
{
    public function __construct(
        private readonly CommentRepository $repository,
        private readonly CommentTreeBuilder $trees,
        private readonly AuthManager $auth,
        private readonly SettingsRepository $settings,
        private readonly EventDispatcher $events,
        private readonly CommentTargetAccessGuard $targetAccess,
        private readonly DatabaseRateLimiter $limiter,
        private readonly string $appKey,
        private readonly ContentRendererInterface $contentRenderer,
    ) {
    }

    public function for(string $type, int $id): array
    {
        if (! $this->targetAccess->allows($type, $id)) return [];
        $viewer = $this->auth->user();
        $comments = $this->repository->approved($type, $id, $viewer ? (int) $viewer['id'] : null);
        foreach ($comments as &$comment) {
            $comment['body_html'] = new Markup($this->contentRenderer->render(
                (string) $comment['body'], ContentFormat::fromInput($comment['body_format'] ?? null, ContentFormat::Markdown), ContentProfile::Comment,
            ), 'UTF-8');
        }
        unset($comment);
        return $this->trees->build($comments);
    }
    public function guestsAllowed(): bool { return $this->settings->boolean('comments.guests_allowed', false); }
    public function moderationRequired(): bool { return $this->settings->boolean('comments.moderation_required', true); }

    public function create(Request $request, string $type, int $contentId): int
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $type) || $contentId < 1) throw new RuntimeException('Invalid comment target.');
        $this->targetAccess->require($type, $contentId);
        $user = $this->auth->user();
        if ($user === null && ! $this->guestsAllowed()) throw new RuntimeException('Sign in to comment.');
        $key = ($user ? 'user:' . $user['id'] : 'ip:' . $request->ip()) . '|' . $type . ':' . $contentId;
        if ($this->limiter->tooManyAttempts($key)) throw new RuntimeException('Too many comments. Please wait before trying again.');
        [$body, $bodyFormat] = $this->body($request->input('body'), $request->input('body_format'));
        $guestName = null;
        if ($user === null) {
            $guestName = trim((string) $request->input('guest_name'));
            if (mb_strlen($guestName) < 2 || mb_strlen($guestName) > 100) throw new RuntimeException('Guest name must contain 2-100 characters.');
        }
        $parent = filter_var($request->input('parent_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $status = $this->moderationRequired() ? 'pending' : 'approved';
        $id = $this->repository->create([
            'content_type' => $type, 'content_id' => $contentId, 'parent_id' => $parent ? (int) $parent : null,
            'user_id' => $user ? (int) $user['id'] : null, 'guest_name' => $guestName, 'body' => $body, 'body_format' => $bodyFormat,
            'status' => $status, 'ip_hash' => $this->hash($request->ip()),
        ]);
        $this->limiter->hit($key);
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::COMMENT_CREATED, new CommentCreated($id, $type, $contentId, $status));
        return $id;
    }

    public function edit(Request $request, int $id): void
    {
        $user = $this->auth->user();
        if ($user === null) throw new RuntimeException('Sign in to edit comments.');
        $this->assertCommentTargetAccessible($id, false);
        [$body, $bodyFormat] = $this->body($request->input('body'), $request->input('body_format'));
        $this->repository->edit($id, (int) $user['id'], $body, $bodyFormat);
    }

    public function react(int $id, mixed $reaction): void
    {
        $user = $this->auth->user();
        if ($user === null) throw new RuntimeException('Sign in to react to comments.');
        $this->assertCommentTargetAccessible($id, true);
        $reaction = (string) $reaction;
        if (! in_array($reaction, ['like', 'dislike'], true)) throw new RuntimeException('Invalid reaction.');
        $this->repository->react($id, (int) $user['id'], $reaction);
    }

    public function report(Request $request, int $id): int
    {
        $this->assertCommentTargetAccessible($id, true);
        $reason = trim((string) $request->input('reason'));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) throw new RuntimeException('Report reason must contain 5-500 characters.');
        $user = $this->auth->user();
        $identity = $user ? 'user:' . $user['id'] : 'ip:' . $request->ip();
        $key = 'report|' . $identity;
        if ($this->limiter->tooManyAttempts($key)) throw new RuntimeException('Too many reports. Please wait before trying again.');
        $report = $this->repository->report($id, $user ? (int) $user['id'] : null, $this->hash($identity), $reason);
        $this->limiter->hit($key);
        return $report;
    }

    private function assertCommentTargetAccessible(int $commentId, bool $approvedOnly): void
    {
        $target = $this->repository->targetForComment($commentId);
        if ($target === null || ($approvedOnly && $target['status'] !== 'approved')) throw new CommentTargetNotFound();
        $this->targetAccess->require($target['content_type'], $target['content_id']);
    }

    /** @return array{string,string} */
    private function body(mixed $value, mixed $formatValue): array
    {
        $body = trim((string) $value);
        if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) throw new RuntimeException('Comment must contain 2-5000 characters.');
        $format = ContentFormat::fromInput($formatValue, ContentFormat::Markdown);
        $rendered = trim(strip_tags($this->contentRenderer->render($body, $format, ContentProfile::Comment)));
        if ($rendered === '') throw new RuntimeException('Comment must contain visible text.');
        return [$body, $format->value];
    }

    private function hash(string $value): string
    {
        if ($this->appKey === '') throw new RuntimeException('APP_KEY is required for comment identity protection.');
        return hash_hmac('sha256', $value, $this->appKey);
    }
}
