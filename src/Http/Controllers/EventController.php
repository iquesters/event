<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Iquesters\Commerce\Support\OrderException;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventAccess;
use Iquesters\Event\Support\EventFeedback;
use Iquesters\Event\Support\EventForms;
use Iquesters\Event\Support\EventPaidRegistrar;
use Iquesters\Event\Support\EventRegistrar;
use Iquesters\Organisation\Support\OrganisationAccess;
use Iquesters\Organisation\Support\OrganisationContext;

/**
 * The members' side: events of their organisations, the event page, and
 * registering or cancelling.
 */
class EventController extends Controller
{
    public function __construct(private EventRegistrar $registrar, private OrganisationContext $context, private EventFeedback $feedback, private EventPaidRegistrar $paid) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        // The organisation they work in (header switcher), else all of theirs.
        $organisationIds = $this->context->id($user)
            ? [$this->context->id($user)]
            : array_unique([...OrganisationAccess::memberOrganisationIds($user), ...EventAccess::manageableOrganisationIds($user)]);
        $past = $request->query('when') === 'past';

        $events = Event::query()
            ->with('organisation:id,uid,name')
            ->whereIn('organisation_id', $organisationIds)
            ->whereIn('status', [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED])
            ->when($past,
                fn ($q) => $q->where('starts_at', '<', now()->startOfDay())->orderByDesc('starts_at'),
                fn ($q) => $q->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at'))
            ->paginate(12)
            ->withQueryString();

        $mine = EventRegistration::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('status', EventRegistration::ACTIVE_STATUSES)
            ->whereIn('event_id', $events->pluck('id'))
            ->pluck('event_id')
            ->all();

        return view('event::events.index', compact('events', 'mine', 'past'));
    }

    public function show(Request $request, string $uid): View
    {
        $event = $this->find($request, $uid);
        $user = $request->user();
        $registration = $this->registrar->registrationOf($user, $event);
        // The registration form follows what's on sale now (e.g. a bundle whose sale starts today).
        if ($event->is_paid && ! $registration) {
            EventForms::syncPaid($event);
        }

        return view('event::events.show', [
            'event' => $event,
            'registration' => $registration,
            'paidFormId' => $event->is_paid ? EventForms::paidRegistrationSlug($event) : null,
            'prices' => $event->is_paid ? EventPaidRegistrar::prices($event) : [],
            'payUrl' => $registration?->order && ! $registration->order->isPaid() && Route::has('payment.pay')
                ? route('payment.pay', ['uid' => $registration->order->uid, 'return' => '/events/'.$event->uid])
                : null,
            // Prefill: their account.
            // Prefill: their account, and their membership details (e.g. their school records) when the membership module knows them.
            'paidFormData' => ['name' => $user->name ?? '', 'email' => $user->email ?? '', 'phone_code' => '+91', 'phone' => $user->phone ?? '', 'guests' => []]
                + ($event->is_paid && app()->bound(\Iquesters\Event\Contracts\MembershipDetails::class)
                    ? app(\Iquesters\Event\Contracts\MembershipDetails::class)->prefill($event, $user)
                    : []),
            // Closed: new people can still become members (membership on its own).
            'membershipUrl' => Route::has('community.membership') ? route('community.membership', ['organisation' => $event->organisation?->uid]) : null,
            'closedReason' => $event->registrationClosedReason(),
            'mayRegister' => EventAccess::mayRegister($user, $event),
            'canManage' => EventAccess::canManageEvent($user, $event),
            'feedbackClosed' => $this->feedback->closedReason($user, $event),
            'rating' => $event->isPast() ? $this->feedback->summary($event) : null,
        ]);
    }

    /** The registration form (form schema event-registration) posts here. */
    public function register(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        $validated = $request->validate([
            'guests' => ['nullable', 'integer', 'min:0', 'max:'.EventRegistration::MAX_GUESTS],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->registrar->register($request->user(), $event, (int) ($validated['guests'] ?? 0), $validated['note'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->done($event, "You're registered.");
    }

    /** The paid registration form (event-paid-registration-{uid}) posts here: an order, then the pay page. */
    public function payRegister(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        abort_unless($event->is_paid, 404);

        // Already registered: straight to their payment (or the event page).
        if ($existing = $this->paid->activeRegistration($request->user(), $event)) {
            return $this->toPayment($event, $existing, "You're already registered.");
        }

        $data = $request->validate(EventPaidRegistrar::rules($event), EventPaidRegistrar::messages($event));

        try {
            $registration = $this->paid->register($event, $data, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (OrderException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        return $this->toPayment($event, $registration, $registration->order?->isPaid() ? "You're registered." : 'Registered: now pay '.$registration->order?->reference.' to confirm.');
    }

    /** The total of the paid form as filled in so far (shown above the terms). */
    public function payQuote(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        abort_unless($event->is_paid, 404);

        return response()->json(['success' => true, 'data' => $this->paid->quote($event, $request->all(), $request->user())]);
    }

    public function cancel(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        if ($event->is_paid && ($registration = $this->paid->activeRegistration($request->user(), $event)) && $registration->order_id) {
            try {
                $this->paid->cancel($registration, (int) $request->user()->id, 'Cancelled by the registrant');
            } catch (\DomainException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return $this->done($event, 'Your registration is cancelled.');
        }
        $this->registrar->cancel($request->user(), $event);

        return $this->done($event, 'Your registration is cancelled.');
    }

    /** The form engine follows redirect_url; the event page then shows the message. */
    private function done(Event $event, string $message): JsonResponse
    {
        session()->flash('success', $message);

        return response()->json(['success' => true, 'message' => $message, 'redirect_url' => route('events.show', $event->uid)]);
    }

    /** The pay page of the registration's order (when payment is installed and something is due), else the event page. */
    private function toPayment(Event $event, EventRegistration $registration, string $message): JsonResponse
    {
        $order = $registration->order;
        session()->flash('success', $message);
        $url = $order && ! $order->isPaid() && Route::has('payment.pay')
            ? route('payment.pay', ['uid' => $order->uid, 'return' => '/events/'.$event->uid])
            : route('events.show', $event->uid);

        return response()->json(['success' => true, 'message' => $message, 'redirect_url' => $url]);
    }

    /** An event the user may not see looks like a missing one. */
    private function find(Request $request, string $uid): Event
    {
        $event = Event::query()->with('organisation')->where('uid', $uid)->firstOrFail();
        abort_unless(EventAccess::canSee($request->user(), $event), 404);

        return $event;
    }
}
