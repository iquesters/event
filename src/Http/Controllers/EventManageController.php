<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Event\Enums\Module;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventAccess;
use Iquesters\Event\Support\EventForms;
use Iquesters\Event\Support\FeedbackPresets;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\System\Http\ApiResponse;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\Support\OrganisationContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Managing events (super-admins and organisation admins, each for their
 * organisations). The list is the generic table (event-manage-table, rows from
 * data()); the form is the event-details form schema, which posts JSON here.
 */
class EventManageController extends Controller
{
    use Loggable;

    public const TABLE = 'event-manage-table';

    public function __construct(private OrganisationContext $context) {}

    /** The generic table (old address). */
    public function index(): RedirectResponse
    {
        $uid = DB::table('table_schemas')->where('slug', self::TABLE)->value('uid');
        abort_unless($uid, 404, 'Run event:seed to create the events table.');

        return redirect()->to(url("/list/{$uid}"));
    }

    /** One event in the table (old address). */
    public function edit(Request $request, string $uid): RedirectResponse
    {
        $event = $this->find($request, $uid);
        $table = DB::table('table_schemas')->where('slug', self::TABLE)->value('uid');
        $form = DB::table('form_schemas')->where('slug', EventForms::DETAILS)->value('uid');
        abort_unless($table && $form, 404);

        return redirect()->to(url("/list/{$table}/view/form/{$form}/data/{$event->uid}"));
    }

    /** The table's rows (data_url). */
    public function data(Request $request): JsonResponse
    {
        $query = Event::query()
            ->with('organisation:id,name')
            ->withCount(['registrations as registered_count' => fn ($q) => $q->where('status', EventRegistration::STATUS_REGISTERED)])
            ->whereIn('organisation_id', $this->allowedIds($request))
            ->when(in_array($request->query('status'), Event::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')));

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('venue', 'like', $like));
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $total = (clone $query)->count();

        $sorted = false;
        foreach (array_filter(explode(',', (string) $request->query('sort', ''))) as $sort) {
            if (in_array(ltrim($sort, '-'), ['title', 'starts_at', 'status', 'created_at'], true)) {
                $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
                $sorted = true;
            }
        }
        if (! $sorted) {
            $query->orderByDesc('starts_at');
        }

        $rows = $query->skip($offset)->take($length)->get()->map(fn (Event $e) => $this->row($e))->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $total, 'offset' => $offset, 'length' => $length]]);
    }

    public function record(Request $request, string $uid): JsonResponse
    {
        return ApiResponse::success($this->row($this->find($request, $uid)->loadCount([
            'registrations as registered_count' => fn ($q) => $q->where('status', EventRegistration::STATUS_REGISTERED),
        ])));
    }

    /** Record section: links to the event's attendees, feedback and pages. */
    public function links(Request $request, string $uid): View
    {
        return view('event::manage.links', ['event' => $this->find($request, $uid)]);
    }

    public function store(Request $request): JsonResponse
    {
        $event = Event::create($this->validated($request) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->afterSave($event);
        static::logInfo('Event created', ['event' => $event->uid, 'user_id' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => $event->status === Event::STATUS_PUBLISHED ? 'Event published.' : 'Event saved.', 'data' => $this->row($event)], 201);
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        $event->update($this->validated($request) + ['updated_by' => $request->user()->id]);
        $this->afterSave($event);

        return response()->json(['success' => true, 'message' => 'Event saved.', 'data' => $this->row($event->fresh('organisation'))]);
    }

    /** Options of the event form's selects. */
    public function organisationOptions(Request $request): JsonResponse
    {
        $organisations = Organisation::query()->whereIn('id', EventAccess::manageableOrganisationIds($request->user()))->orderBy('name')->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => [
            ['value' => '', 'label' => 'Choose…'],
            ...$organisations->map(fn ($o) => ['value' => (int) $o->id, 'label' => $o->name])->all(),
        ]]);
    }

    public function typeOptions(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            ['value' => '', 'label' => '—'],
            ...array_map(fn ($t) => ['value' => $t, 'label' => $t], ConfProvider::from(Module::EVENT)->types()),
        ]]);
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

    /** Feedback on: start from the event type's questions; keep the attendee form in step. */
    private function afterSave(Event $event): void
    {
        if ($event->feedback_enabled) {
            FeedbackPresets::apply($event); // only when it has no questions yet
            EventForms::syncFeedback($event);
        }
    }

    private function row(Event $event): array
    {
        return [
            'uid' => $event->uid,
            'organisation_id' => $event->organisation_id,
            'organisation' => $event->organisation?->name,
            'title' => $event->title,
            'type' => $event->type,
            'description' => $event->description,
            'starts_at' => $event->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $event->ends_at?->format('Y-m-d\TH:i'),
            'when' => $event->starts_at?->format('j M Y, g:i A'),
            'venue' => $event->venue,
            'address' => $event->address,
            'online_url' => $event->online_url,
            'visibility' => $event->visibility,
            'status' => $event->status,
            'status_label' => $event->statusLabel(),
            'registration_open' => $event->registration_open,
            'capacity' => $event->capacity,
            'registration_closes_at' => $event->registration_closes_at?->format('Y-m-d\TH:i'),
            'feedback_enabled' => $event->feedback_enabled,
            'feedback_anonymous' => $event->feedback_anonymous,
            'registered' => ($event->registered_count ?? 0).($event->capacity ? ' / '.$event->capacity : ''),
        ];
    }

    private function validated(Request $request): array
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
            'feedback_enabled' => ['nullable', 'boolean'],
            'feedback_anonymous' => ['nullable', 'boolean'],
        ], [
            'organisation_id.in' => "You don't manage that organisation.",
            'visibility.in' => 'Public events are switched off for this app.',
        ]);

        foreach (['registration_open', 'feedback_enabled', 'feedback_anonymous'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

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
        $event = Event::query()->with('organisation:id,name')->where('uid', $uid)->firstOrFail();
        abort_unless(EventAccess::canManageEvent($request->user(), $event), 404);

        return $event;
    }
}
