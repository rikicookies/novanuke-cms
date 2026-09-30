<?php

declare(strict_types=1);

namespace NovaNuke\Core\Events;

/**
 * Stable names for Core-owned extension events.
 *
 * EventDispatcher intentionally keeps its string-based API for Module API 1.0
 * compatibility. New Core and bundled-module code should reference these
 * constants instead of repeating magic strings.
 */
final class EventName
{
    public const ADMIN_MENU_BUILDING = 'admin.menu.building';
    public const BLOCK_RENDERING = 'block.rendering';
    public const COMMENT_CREATED = 'comment.created';
    public const COMMENTS_CONTENT_CHECKING = 'comments.content.checking';
    public const CONTENT_CREATED = 'content.created';
    public const CONTENT_UPDATED = 'content.updated';
    public const DOWNLOAD_COMPLETED = 'download.completed';
    public const FRIEND_ACCEPTED = 'friend.accepted';
    public const FRIEND_REQUESTED = 'friend.requested';
    public const MAINTENANCE_PRUNING = 'maintenance.pruning';
    public const MEDIA_USAGE_CHECKING = 'media.usage.checking';
    public const MEMBERSHIP_ASSIGNED = 'membership.assigned';
    public const MEMBERSHIP_EXTENDED = 'membership.extended';
    public const MEMBERSHIP_ACTIVATED = 'membership.activated';
    public const MEMBERSHIP_REVOKED = 'membership.revoked';
    public const MEMBERSHIP_SCHEDULED = 'membership.scheduled';
    public const MEMBERSHIP_SCHEDULE_CANCELLED = 'membership.schedule_cancelled';
    public const MEMBERSHIP_EXPIRED = 'membership.expired';
    public const PAGE_RENDERING = 'page.rendering';
    public const PRIVATE_MESSAGE_SENT = 'private-message.sent';
    public const PROFILE_ACTIONS_BUILDING = 'profile.actions.building';
    public const PROFILE_STATISTICS_BUILDING = 'profile.statistics.building';
    public const SEARCH_PROVIDERS_REGISTERING = 'search.providers.registering';
    public const SITEMAP_COLLECTING = 'sitemap.collecting';
    public const THEME_ACTIVATED = 'theme.activated';
    public const USER_ANONYMIZED = 'user.anonymized';
    public const USER_EMAIL_CHANGED = 'user.email_changed';
    public const USER_EMAIL_VERIFIED = 'user.email_verified';
    public const USER_LOGGED_IN = 'user.logged_in';
    public const USER_REGISTERED = 'user.registered';

    private function __construct()
    {
    }
}
