<?php

namespace Iquesters\Event\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;

/**
 * Registering and cancelling, with the capacity checked under a lock so two
 * people can't take the last seat at once.
 */
class EventRegistrar
{
    /**
     * @throws \DomainException with a message for the person
     */
    public function register(Authenticatable $user, Event $event, int $guests = 0, ?string $note = null): EventRegistration
    {
        if (! EventAccess::mayRegister($user, $event)) {
            throw new \DomainException('Only members of '.$event->organisation?->name.' can register.');
        }

        if ($event->is_paid) {
            throw new \DomainException('This event has paid registration: use its registration form.');
        }

        $guests = max(0, min(EventRegistration::MAX_GUESTS, $guests));

        return DB::transaction(function () use ($user, $event, $guests, $note) {
            // Lock the event row: registrations for it are counted one at a time.
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $existing = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('user_id', $user->getAuthIdentifier())
                ->first();
            if ($existing?->isActive()) {
                throw new \DomainException("You're already registered.");
            }

            if ($reason = $event->registrationClosedReason()) {
                throw new \DomainException($reason);
            }
            $left = $event->seatsLeft();
            if ($left !== null && $left < 1 + $guests) {
                throw new \DomainException($left === 1 ? 'Only 1 place is left.' : "Only {$left} places are left.");
            }

            $values = ['status' => EventRegistration::STATUS_REGISTERED, 'guests' => $guests, 'note' => $note ? mb_substr(trim($note), 0, 500) : null];

            return $existing
                ? tap($existing)->update($values)
                : EventRegistration::create($values + ['event_id' => $event->id, 'user_id' => $user->getAuthIdentifier()]);
        });
    }

    public function cancel(Authenticatable $user, Event $event): void
    {
        EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', EventRegistration::STATUS_REGISTERED)
            ->update(['status' => EventRegistration::STATUS_CANCELLED]);
    }

    /** Their registration holding places (confirmed, or a paid one waiting for payment). */
    public function registrationOf(Authenticatable $user, Event $event): ?EventRegistration
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('status', EventRegistration::ACTIVE_STATUSES)
            ->with('order')
            ->first();
    }
}
