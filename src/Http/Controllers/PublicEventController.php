<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventPaidRegistrar;

/** /e/{uid}: a public event's page, without signing in. */
class PublicEventController extends Controller
{
    public function show(string $key): View|RedirectResponse
    {
        $event = Event::query()->with('organisation')->where('uid', $key)->first();
        if (! $event) {
            // An address shared before (by name): the same page at its lasting address.
            $old = Event::query()->where('slug', $key)->firstOrFail();
            abort_unless($old->isPublic(), 404);

            return redirect()->route('events.public', $old->uid, 301);
        }
        abort_unless($event->isPublic(), 404);

        $organisation = $event->organisation;
        // Signing up from here: anything that asks for an organisation has this one chosen.
        $context = 'Iquesters\Organisation\Support\OrganisationContext';
        if ($organisation && class_exists($context) && method_exists($context, 'rememberVisit')) {
            app($context)->rememberVisit($organisation);
        }
        $profile = 'Iquesters\Organisation\Support\OrganisationProfile';

        return view('event::public.show', [
            'event' => $event,
            'closedReason' => $event->registrationClosedReason(),
            'tickets' => $event->is_paid ? EventPaidRegistrar::prices($event) : [],
            'going' => (int) $event->registrations()->where('status', EventRegistration::STATUS_REGISTERED)->sum(\Illuminate\Support\Facades\DB::raw('1 + guests')),
            // The organiser: its logo, and its public page when switched on.
            'logoUrl' => $organisation && class_exists($profile) && $organisation->getMetaValue($profile::LOGO) && Route::has('organisations.logo')
                ? route('organisations.logo', $organisation->uid) : null,
            'organisationUrl' => $organisation && class_exists($profile) && $profile::isPublic($organisation) && Route::has('organisations.public')
                ? route('organisations.public', $organisation->uid) : null,
            'membershipUrl' => Route::has('community.membership') ? route('community.membership', ['organisation' => $organisation?->uid]) : null,
        ]);
    }
}
