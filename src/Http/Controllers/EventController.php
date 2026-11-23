<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventAccess;
use Iquesters\Event\Support\EventRegistrar;
use Iquesters\Organisation\Support\OrganisationAccess;
use Iquesters\Organisation\Support\OrganisationContext;

/**
 * The members' side: events of their organisations, the event page, and
 * registering or cancelling.
 */
class EventController extends Controller
{
    public function __construct(private EventRegistrar $registrar, private OrganisationContext $context) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        // The organisation they work in (header switcher), else all of theirs.
        $organisationIds = $this->context->id($user)
            ? [$this->context->id($user)]
            : array_unique([...OrganisationAccess::memberOrganisationIds($user), ...EventAccess::manageableOrganisationIds($user)]);
        $past = $request->query('when') === 'past';

        $events = Event::query()
            ->with('organisation:id,name')
            ->whereIn('organisation_id', $organisationIds)
            ->whereIn('status', [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED])
            ->when($past,
                fn ($q) => $q->where('starts_at', '<', now()->startOfDay())->orderByDesc('starts_at'),
                fn ($q) => $q->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at'))
            ->paginate(12)
            ->withQueryString();

        $mine = EventRegistration::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', EventRegistration::STATUS_REGISTERED)
            ->whereIn('event_id', $events->pluck('id'))
            ->pluck('event_id')
            ->all();

        return view('event::events.index', compact('events', 'mine', 'past'));
    }

    public function show(Request $request, string $uid): View
    {
        $event = $this->find($request, $uid);
        $user = $request->user();

        return view('event::events.show', [
            'event' => $event,
            'registration' => $this->registrar->registrationOf($user, $event),
            'closedReason' => $event->registrationClosedReason(),
            'mayRegister' => EventAccess::mayRegister($user, $event),
            'canManage' => EventAccess::canManageEvent($user, $event),
        ]);
    }

    public function register(Request $request, string $uid): RedirectResponse
    {
        $event = $this->find($request, $uid);
        $validated = $request->validate([
            'guests' => ['nullable', 'integer', 'min:0', 'max:'.EventRegistration::MAX_GUESTS],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->registrar->register($request->user(), $event, (int) ($validated['guests'] ?? 0), $validated['note'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('events.show', $event->uid)->with('success', "You're registered.");
    }

    public function cancel(Request $request, string $uid): RedirectResponse
    {
        $event = $this->find($request, $uid);
        $this->registrar->cancel($request->user(), $event);

        return redirect()->route('events.show', $event->uid)->with('success', 'Your registration is cancelled.');
    }

    /** An event the user may not see looks like a missing one. */
    private function find(Request $request, string $uid): Event
    {
        $event = Event::query()->with('organisation')->where('uid', $uid)->firstOrFail();
        abort_unless(EventAccess::canSee($request->user(), $event), 404);

        return $event;
    }
}
