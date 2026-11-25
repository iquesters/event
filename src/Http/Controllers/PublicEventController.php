<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Iquesters\Event\Models\Event;

/** /e/{slug}: a public event's page, without signing in. */
class PublicEventController extends Controller
{
    public function show(string $slug): View
    {
        $event = Event::query()->with('organisation')->where('slug', $slug)->firstOrFail();
        abort_unless($event->isPublic(), 404);

        return view('event::public.show', [
            'event' => $event,
            'closedReason' => $event->registrationClosedReason(),
        ]);
    }
}
