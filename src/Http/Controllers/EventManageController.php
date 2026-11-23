<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Event\Enums\Module;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventAccess;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\Support\OrganisationContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Managing events (super-admins and organisation admins, each for their
 * organisations): create, edit, publish or cancel, and see the attendees.
 */
class EventManageController extends Controller
{
    use Loggable;

    public function __construct(private OrganisationContext $context) {}

    public function index(Request $request): View
    {
        $allowed = $this->allowedIds($request);

        $events = Event::query()
            ->with('organisation:id,name')
            ->withCount(['registrations as registered_count' => fn ($q) => $q->where('status', EventRegistration::STATUS_REGISTERED)])
            ->whereIn('organisation_id', $allowed)
            ->when(in_array($request->query('status'), Event::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('starts_at')
            ->paginate(25)
            ->withQueryString();

        return view('event::manage.index', compact('events'));
    }

    public function create(Request $request): View
    {
        return $this->form($request, new Event([
            'visibility' => Event::VISIBILITY_MEMBERS,
            'status' => Event::STATUS_DRAFT,
            'registration_open' => true,
            'organisation_id' => $this->context->id($request->user()),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $event = Event::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        static::logInfo('Event created', ['event' => $event->uid, 'user_id' => $request->user()->id]);

        return redirect()->route('events.manage.edit', $event->uid)->with('success', $event->status === Event::STATUS_PUBLISHED ? 'Event published.' : 'Event saved as a draft.');
    }

    public function edit(Request $request, string $uid): View
    {
        return $this->form($request, $this->find($request, $uid));
    }

    public function update(Request $request, string $uid): RedirectResponse
    {
        $event = $this->find($request, $uid);
        $event->update($this->validated($request, $event) + ['updated_by' => $request->user()->id]);

        return redirect()->route('events.manage.edit', $event->uid)->with('success', 'Event saved.');
    }

    public function attendees(Request $request, string $uid): View
    {
        $event = $this->find($request, $uid);
        $registrations = $event->registrations()->with('user:id,name,email')->orderByDesc('status')->orderBy('created_at')->get();

        return view('event::manage.attendees', compact('event', 'registrations'));
    }

    public function export(Request $request, string $uid): StreamedResponse
    {
        $event = $this->find($request, $uid);
        $registrations = $event->registrations()->with('user:id,name,email')->where('status', EventRegistration::STATUS_REGISTERED)->orderBy('created_at')->get();

        return response()->streamDownload(function () use ($registrations) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel opens it as UTF-8
            fputcsv($out, ['Name', 'Email', 'Guests', 'Note', 'Registered at'], ',', '"', '');
            foreach ($registrations as $registration) {
                fputcsv($out, [
                    $registration->user?->name,
                    $registration->user?->email,
                    $registration->guests,
                    $registration->note,
                    $registration->created_at?->format('Y-m-d H:i'),
                ], ',', '"', '');
            }
            fclose($out);
        }, $event->slug.'-attendees.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function form(Request $request, Event $event): View
    {
        $allowed = EventAccess::manageableOrganisationIds($request->user());

        return view('event::manage.form', [
            'event' => $event,
            'organisations' => Organisation::query()->whereIn('id', $allowed)->orderBy('name')->get(['id', 'name']),
            'types' => ConfProvider::from(Module::EVENT)->types(),
            'publicAllowed' => (bool) ConfProvider::from(Module::EVENT)->public_events,
        ]);
    }

    private function validated(Request $request, ?Event $event = null): array
    {
        $publicAllowed = (bool) ConfProvider::from(Module::EVENT)->public_events;

        $data = $request->validate([
            'organisation_id' => ['required', 'integer', Rule::in(EventAccess::manageableOrganisationIds($request->user()))],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:10000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'venue' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'online_url' => ['nullable', 'url:http,https', 'max:255'],
            'visibility' => ['required', Rule::in($publicAllowed ? [Event::VISIBILITY_MEMBERS, Event::VISIBILITY_PUBLIC] : [Event::VISIBILITY_MEMBERS])],
            'status' => ['required', Rule::in(Event::STATUSES)],
            'registration_open' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'registration_closes_at' => ['nullable', 'date', 'before_or_equal:starts_at'],
        ], ['organisation_id.in' => "You don't manage that organisation."]);

        $data['registration_open'] = $request->boolean('registration_open');

        return $data;
    }

    /** @return int[] the organisations shown: the one worked in, if any, else all managed */
    private function allowedIds(Request $request): array
    {
        $allowed = EventAccess::manageableOrganisationIds($request->user());
        $context = $this->context->id($request->user());

        return $context && in_array($context, $allowed, true) ? [$context] : $allowed;
    }

    /** Another organisation's event looks like a missing one. */
    private function find(Request $request, string $uid): Event
    {
        $event = Event::query()->where('uid', $uid)->firstOrFail();
        abort_unless(EventAccess::canManageEvent($request->user(), $event), 404);

        return $event;
    }
}
