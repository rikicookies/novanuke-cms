<?php

declare(strict_types=1);

namespace Modules\Notifications\src;

use NovaNuke\Core\Comments\CommentCreated;
use NovaNuke\Core\Messaging\PrivateMessageSent;
use NovaNuke\Core\Social\FriendAccepted;
use NovaNuke\Core\Social\FriendRequested;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Maintenance\MaintenancePruning;
use NovaNuke\Core\Membership\MembershipAssigned;
use NovaNuke\Core\Membership\MembershipExtended;
use NovaNuke\Core\Membership\MembershipActivated;
use NovaNuke\Core\Membership\MembershipRevoked;
use NovaNuke\Core\Membership\MembershipExpired;
use NovaNuke\Core\Membership\MembershipScheduled;
use NovaNuke\Core\Membership\MembershipScheduleCancelled;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\View\ViewRenderer;
use Throwable;

final class NotificationsModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container->get(ViewRenderer::class)->addNamespace('notifications', $context->basePath . '/views');
        $context->container->bind(NotificationRepository::class, static fn (Container $c) => new NotificationRepository($c->get(\PDO::class)));
        $context->container->bind(NotificationPublisher::class, static fn (Container $c) => new NotificationPublisher($c->get(NotificationRepository::class)));
    }

    public function boot(ModuleContext $context): void
    {
        $repository = $context->container->get(NotificationRepository::class);
        $publisher = $context->container->get(NotificationPublisher::class);
        $user = $context->container->get(\NovaNuke\Auth\AuthManager::class)->user();
        $context->container->get(ViewRenderer::class)->addGlobal(
            'notification_unread_count',
            $user === null ? 0 : $repository->unreadCount((int) $user['id']),
        );

        $context->events->listen(\NovaNuke\Core\Events\EventName::PRIVATE_MESSAGE_SENT, static function (object $event) use ($publisher): void {
            if (! $event instanceof PrivateMessageSent) return;
            try {
                $publisher->toUser(
                    $event->recipientId,
                    'private-message.received',
                    'New private message',
                    'You received a new private message.',
                    '/messages/' . $event->conversationId,
                    'private-message:' . $event->messageKey,
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::COMMENT_CREATED, static function (object $event) use ($publisher): void {
            if (! $event instanceof CommentCreated || $event->status !== 'pending') return;
            try {
                $publisher->toPermission(
                    'comments.moderate',
                    'comment.pending',
                    'Comment awaiting moderation',
                    'A new comment requires review.',
                    '/admin/comments',
                    'comment-pending:' . $event->id,
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::FRIEND_REQUESTED, static function (object $event) use ($publisher): void {
            if (! $event instanceof FriendRequested) return;
            try {
                $publisher->toUser($event->recipientId, \NovaNuke\Core\Events\EventName::FRIEND_REQUESTED, 'New friend request', 'You received a friend request.', '/friends');
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::FRIEND_ACCEPTED, static function (object $event) use ($publisher): void {
            if (! $event instanceof FriendAccepted) return;
            try {
                $publisher->toUser($event->recipientId, \NovaNuke\Core\Events\EventName::FRIEND_ACCEPTED, 'Friend request accepted', 'Your friend request was accepted.', '/friends');
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::MEMBERSHIP_ACTIVATED, static function (object $event) use ($publisher): void {
            if (! $event instanceof MembershipActivated) return;
            try {
                $message=$event->lifetime
                    ? 'Your scheduled VIP membership is now active with lifetime access.'
                    : 'Your scheduled VIP membership is now active until ' . ($event->expiresAt ?? 'its configured expiration') . ' UTC.';
                $publisher->toUser(
                    $event->userId,
                    'membership.activated',
                    'Scheduled VIP is now active',
                    $message,
                    '/account/profile',
                    'membership-activated:' . $event->entitlementId,
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::MEMBERSHIP_ASSIGNED, static function (object $event) use ($publisher): void {
            if (! $event instanceof MembershipAssigned) return;
            try {
                $message=$event->lifetime
                    ? 'Your VIP membership is now active with lifetime access.'
                    : 'Your VIP membership is active until ' . ($event->expiresAt ?? 'its configured expiration') . ' UTC.';
                $publisher->toUser(
                    $event->userId,
                    'membership.assigned',
                    'VIP membership active',
                    $message,
                    '/account/profile',
                    'membership-assigned:' . $event->userId . ':' . md5($event->planKey . ':' . ($event->expiresAt ?? 'lifetime')),
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::MEMBERSHIP_EXTENDED, static function (object $event) use ($publisher): void {
            if (! $event instanceof MembershipExtended) return;
            try {
                $publisher->toUser(
                    $event->userId,
                    'membership.extended',
                    'VIP membership extended',
                    'Your VIP membership was extended by ' . $event->days . ' day(s) and now expires ' . $event->expiresAt . ' UTC.',
                    '/account/profile',
                    'membership-extended:' . $event->userId . ':' . md5($event->planKey . ':' . $event->expiresAt),
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });

        $context->events->listen(\NovaNuke\Core\Events\EventName::MEMBERSHIP_REVOKED, static function (object $event) use ($publisher): void {
            if (! $event instanceof MembershipRevoked) return;
            try {
                $publisher->toUser(
                    $event->userId,
                    'membership.revoked',
                    'VIP membership ended',
                    'Your account now has Free membership access.',
                    '/account/profile',
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::MEMBERSHIP_EXPIRED, static function (object $event) use ($publisher): void {
            if (! $event instanceof MembershipExpired) return;
            try {
                $publisher->toUser(
                    $event->userId,
                    'membership.expired',
                    'VIP membership expired',
                    'Your VIP membership expired and your account now has Free membership access.',
                    '/account/profile',
                    'membership-expired:' . $event->entitlementId,
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });

        $context->events->listen(\NovaNuke\Core\Events\EventName::MEMBERSHIP_SCHEDULED, static function (object $event) use ($publisher): void {
            if (! $event instanceof MembershipScheduled) return;
            try {
                $message='Your ' . $event->planKey . ' membership is scheduled to begin ' . $event->startsAt . ' UTC.';
                $publisher->toUser(
                    $event->userId,
                    'membership.scheduled',
                    'VIP membership scheduled',
                    $message,
                    '/account/profile',
                    'membership-scheduled:' . $event->userId . ':' . md5($event->planKey . ':' . $event->startsAt),
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::MEMBERSHIP_SCHEDULE_CANCELLED, static function (object $event) use ($publisher): void {
            if (! $event instanceof MembershipScheduleCancelled) return;
            try {
                $publisher->toUser(
                    $event->userId,
                    'membership.schedule-cancelled',
                    'Scheduled VIP cancelled',
                    'Your scheduled VIP membership was cancelled before activation.',
                    '/account/profile',
                );
            } catch (Throwable $error) {
                error_log('Notification delivery failed: ' . $error->getMessage());
            }
        });

        $context->events->listen(\NovaNuke\Core\Events\EventName::MAINTENANCE_PRUNING, static function (object $event) use ($repository): void {
            if ($event instanceof MaintenancePruning) $event->add('notifications.read', $repository->prune($event->dryRun));
        });

        $controller = static fn (Container $c) => new PublicNotificationsController(
            $c->get(NotificationRepository::class),
            $c->get(\NovaNuke\Auth\AuthManager::class),
            $c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $c->get(\NovaNuke\Core\Security\SessionManager::class),
            $c->get(ViewRenderer::class),
            $c->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $context->router->get('/notifications', static fn (Request $r, Container $c): Response => $controller($c)->index(), 'notifications.index');
        $context->router->post('/notifications/read-all', static fn (Request $r, Container $c): Response => $controller($c)->readAll($r));
        $context->router->post('/notifications/{id}/read', static fn (Request $r, Container $c): Response => $controller($c)->read($r));
    }
}
