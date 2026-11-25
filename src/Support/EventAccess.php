<?php

namespace Iquesters\Event\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Iquesters\Event\Models\Event;
use Iquesters\Organisation\Support\OrganisationAccess;

/**
 * Who may see and manage events.
 *
 * - manage (create, edit, attendees): super-admin, and the organisation's
 *   admins (OrganisationAccess: Track B assignments, or the interim
 *   "admin" + member rule);
 * - see and register: members of the organisation (published and cancelled
 *   events); anyone signed in for a public event.
 */
class EventAccess
{
    public static function canManage(?Authenticatable $user): bool
    {
        return OrganisationAccess::isPlatformAdmin($user) || OrganisationAccess::adminOrganisationIds($user) !== [];
    }

    /** @return int[] */
    public static function manageableOrganisationIds(?Authenticatable $user): array
    {
        return OrganisationAccess::adminOrganisationIds($user);
    }

    public static function canManageEvent(?Authenticatable $user, Event $event): bool
    {
        return in_array((int) $event->organisation_id, self::manageableOrganisationIds($user), true);
    }

    public static function isMember(?Authenticatable $user, Event $event): bool
    {
        return in_array((int) $event->organisation_id, OrganisationAccess::memberOrganisationIds($user), true);
    }

    public static function canSee(?Authenticatable $user, Event $event): bool
    {
        if (self::canManageEvent($user, $event)) {
            return true;
        }
        if ($event->status === Event::STATUS_DRAFT) {
            return false;
        }

        return self::isMember($user, $event) || $event->isPublic();
    }

    /** Members always; others only for public events. */
    public static function mayRegister(?Authenticatable $user, Event $event): bool
    {
        return $user !== null && (self::isMember($user, $event) || $event->isPublic());
    }
}
