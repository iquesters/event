<?php

namespace Iquesters\Event\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Commerce\Support\OrderException;
use Iquesters\Event\Enums\Module;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventAccess;
use Iquesters\Event\Support\EventForms;
use Iquesters\Event\Support\EventTables;
use Iquesters\Event\Support\EventRegistrationEditor;
use Iquesters\Event\Support\EventPaidRegistrar;
use Iquesters\Event\Support\EventProducts;
use Iquesters\Commerce\Models\Product;
use Illuminate\Validation\ValidationException;
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

    public function __construct(private OrganisationContext $context, private EventPaidRegistrar $paid) {}

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
            ->with('organisation:id,uid,name')
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
        $validated = $this->validated($request);
        [$data, $paid] = $this->splitPaid($validated, null);
        $event = Event::create($data + ['paid_settings' => $paid, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->saveCover($event, $request);
        $this->afterSave($event, (int) $request->user()->id, $validated['tickets'] ?? []);
        static::logInfo('Event created', ['event' => $event->uid, 'user_id' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => $event->status === Event::STATUS_PUBLISHED ? 'Event published.' : 'Event saved.', 'data' => $this->row($event)], 201);
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        $validated = $this->validated($request);
        [$data, $paid] = $this->splitPaid($validated, $event);
        $event->update($data + ['paid_settings' => $paid, 'updated_by' => $request->user()->id]);
        $this->saveCover($event, $request);
        $this->afterSave($event, (int) $request->user()->id, $validated['tickets'] ?? []);

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
        // The add-attendee form follows what's on sale now.
        if ($event->is_paid) {
            EventForms::syncPaid($event->loadMissing('organisation'));
        }
        $registrations = $event->registrations()->with(['user:id,name,email', 'order:id,uid,reference,total,currency,status'])->orderBy('created_at')->get();
        $active = $registrations->filter->isActive();
        $confirmed = $registrations->where('status', EventRegistration::STATUS_REGISTERED);
        $count = fn ($rows, string $field) => $event->is_paid ? (int) $rows->sum($field) : null;
        $totals = [
            'registrations' => $active->count(),
            'people' => $active->count() + (int) $active->sum('guests'),
            'confirmed_people' => $confirmed->count() + (int) $confirmed->sum('guests'),
            'adults' => $count($confirmed, 'adults'),
            'children' => $count($confirmed, 'children'),
            'veg' => $count($confirmed, 'veg'),
            'non_veg' => $count($confirmed, 'non_veg'),
            'paid' => $event->is_paid ? (float) $confirmed->sum(fn ($r) => (float) $r->order?->total) : null,
        ];

        return view('event::manage.attendees', [
            'event' => $event,
            'registrations' => $registrations,
            // The list: the generic table (form schemas and record sections), this event's rows.
            'table' => EventTables::ensureAttendees(),
            'dataUrl' => route('events.manage.attendees.data', $event->uid, false),
            'totals' => $totals,
            'addFormId' => $event->is_paid ? EventForms::addAttendeeSlug($event) : null,
            'payRoute' => Route::has('payment.pay'),
        ]);
    }

    /** The add-attendee form (event-add-attendee-{uid}) posts here: crew, invited guests, people paying at the desk. */
    public function addAttendee(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        abort_unless($event->is_paid, 404);
        $data = $request->validate(EventPaidRegistrar::rules($event, true), EventPaidRegistrar::messages($event));

        try {
            $registration = $this->paid->register($event, $data, $request->user(), ['admin' => true, 'by' => (int) $request->user()->id]);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (OrderException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        $order = $registration->order;
        $message = $order?->isPaid()
            ? 'Added: '.$registration->displayName().' (free).'
            : 'Added: '.$registration->displayName().'. Order '.$order?->reference.' waits for payment (Payments → Record a payment, or send them the pay link).';
        session()->flash('success', $message);

        return response()->json(['success' => true, 'message' => $message, 'redirect_url' => route('events.manage.attendees', $event->uid)]);
    }

    /** One registration: everyone in it, its order, and Cancel. */
    /** The Attendees table's rows (generic table, data_url): this event's registrations. */
    public function attendeesData(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        $query = $event->registrations()->with(['user:id,name,email', 'order:id,uid,reference,total,currency,status']);
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)
                ->orWhereHas('order', fn ($o) => $o->where('reference', 'like', $like))
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
        }
        // Active first (waiting for payment, being checked, confirmed), then cancelled; newest first.
        $query->orderByRaw('case when status = ? then 1 else 0 end', [EventRegistration::STATUS_CANCELLED])->latest('id');

        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($length)->get()->map(fn (EventRegistration $r) => $this->attendeeRow($event, $r))->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $total, 'offset' => $offset, 'length' => $length]]);
    }

    /** One row of the Attendees table (the table's record address: {data_url}/{registration}). */
    public function attendeeRecord(Request $request, string $uid, string $registration): JsonResponse
    {
        $event = $this->find($request, $uid);
        $row = $event->registrations()->with(['user:id,name,email', 'order:id,uid,reference,total,currency,status'])->where('uid', $registration)->firstOrFail();

        return ApiResponse::success($this->attendeeRow($event, $row));
    }

    /** The registration in the Attendees side panel (record section): people, tickets, the order. */
    public function registrationSummary(Request $request, string $uid): View
    {
        $registration = EventRegistration::query()->with(['event', 'order', 'user:id,name,email'])->where('uid', $uid)->firstOrFail();
        $event = $this->find($request, $registration->event->uid);

        return view('event::manage.partials.registration-card', [
            'event' => $event,
            'registration' => $registration,
            'payUrl' => $registration->order && ! $registration->order->isPaid() && Route::has('payment.pay')
                ? route('payment.pay', $registration->order->uid) : null,
        ]);
    }

    private const STATUS_TONES = [
        EventRegistration::STATUS_REGISTERED => 'success',
        EventRegistration::STATUS_UNDER_REVIEW => 'info',
        EventRegistration::STATUS_AWAITING_PAYMENT => 'warning',
        EventRegistration::STATUS_CANCELLED => 'secondary',
    ];

    private function attendeeRow(Event $event, EventRegistration $r): array
    {
        $order = $r->order;
        $people = 1 + (int) $r->guests;
        $money = fn ($v) => '₹'.rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

        return [
            'uid' => $r->uid,
            'name' => $r->displayName() ?? '—',
            'contact' => trim(($r->email ?: $r->user?->email ?: '').($r->phone ? ' · '.$r->phone : ''), ' ·'),
            'reference' => $order?->reference,
            'people' => $people,
            'people_label' => $people.' '.($people === 1 ? 'person' : 'people').($r->children ? ' ('.$r->children.' '.($r->children === 1 ? 'child' : 'children').')' : ''),
            'meals' => $event->is_paid ? $r->veg.' veg · '.$r->non_veg.' non-veg' : null,
            'total_label' => $order ? $money($order->total) : null,
            'donation_label' => (float) $r->donation > 0 ? 'Donation '.$money($r->donation) : null,
            'status' => $r->status,
            'status_label' => $r->statusLabel(),
            'status_tone' => self::STATUS_TONES[$r->status] ?? 'secondary',
            'added_by_admin' => $r->source === 'admin' ? 'Added by admin' : null,
            'registered_at' => $r->created_at?->format('j M, g:i A'),
            'can_cancel' => $r->isActive() && ! ($order?->isPaid() && (float) $order->total > 0) ? '1' : '0',
        ];
    }

    public function registration(Request $request, string $uid): View
    {
        $registration = EventRegistration::query()->with(['event', 'order', 'user:id,name,email'])->where('uid', $uid)->firstOrFail();
        $event = $this->find($request, $registration->event->uid);

        return view('event::manage.registration', [
            'event' => $event,
            'registration' => $registration,
            'editFormId' => EventRegistrationEditor::mayEdit($request->user(), $registration, true) ? EventRegistrationEditor::adminSlug($event) : null,
            'editFormData' => is_array($registration->people) ? EventRegistrationEditor::formData($registration) : null,
            'canCancel' => $registration->isActive() && ! ($registration->order?->isPaid() && (float) $registration->order->total > 0),
            'payUrl' => $registration->order && ! $registration->order->isPaid() && Route::has('payment.pay')
                ? route('payment.pay', $registration->order->uid) : null,
        ]);
    }

    /** Cancel a registration that isn't paid (its order is cancelled too); form event-registration-admin-cancel, {uid} = the registration. */
    /** An admin corrects a registration's details (form schema event-registration-admin-edit-{event uid}; {uid} = the registration). */
    public function updateRegistration(Request $request, string $uid): JsonResponse
    {
        $registration = EventRegistration::query()->with(['event', 'order.lines'])->where('uid', $uid)->firstOrFail();
        $event = $this->find($request, $registration->event->uid);
        if (! EventRegistrationEditor::mayEdit($request->user(), $registration, true)) {
            return response()->json(['success' => false, 'message' => 'This registration can no longer be changed (cancelled, or a free registration).'], 422);
        }
        app(EventRegistrationEditor::class)->update($registration, $request->all(), $request->user(), true);
        $message = 'Details of '.$registration->fresh()->displayName().' saved.';
        session()->flash('success', $message);

        return response()->json(['success' => true, 'message' => $message, 'redirect_url' => route('events.manage.registrations.show', $registration->uid)]);
    }

    public function cancelRegistration(Request $request, string $uid): JsonResponse
    {
        $registration = EventRegistration::query()->with(['event', 'order'])->where('uid', $uid)->firstOrFail();
        $event = $this->find($request, $registration->event->uid);

        try {
            $event->is_paid && $registration->order_id
                ? $this->paid->cancel($registration, (int) $request->user()->id, 'Cancelled by an admin')
                : $registration->update(['status' => EventRegistration::STATUS_CANCELLED]);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $message = 'Registration of '.$registration->displayName().' cancelled.';
        session()->flash('success', $message);

        return response()->json(['success' => true, 'message' => $message, 'redirect_url' => route('events.manage.attendees', $event->uid)]);
    }

    public function export(Request $request, string $uid): StreamedResponse
    {
        $event = $this->find($request, $uid);
        $registrations = $event->registrations()->with(['user:id,name,email', 'order:id,reference,total,status'])
            ->whereIn('status', EventRegistration::ACTIVE_STATUSES)->orderBy('created_at')->get();

        return response()->streamDownload(function () use ($event, $registrations) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel opens it as UTF-8
            if (! $event->is_paid) {
                fputcsv($out, ['Name', 'Email', 'Guests', 'Note', 'Status', 'Registered at'], ',', '"', '');
                foreach ($registrations as $registration) {
                    fputcsv($out, [
                        $registration->displayName(),
                        $registration->email ?: $registration->user?->email,
                        $registration->guests,
                        $registration->note,
                        $registration->statusLabel(),
                        $registration->created_at?->format('Y-m-d H:i'),
                    ], ',', '"', '');
                }
                fclose($out);

                return;
            }

            // Paid: one row per person (the caterer's and the desk's list).
            fputcsv($out, ['Reference', 'Status', 'Person', 'Registrant / guest', 'Gender', 'Age', 'Ticket', 'Meal', 'With membership', 'Email', 'Mobile', 'Registered by', 'Order total', 'Registered at'], ',', '"', '');
            foreach ($registrations as $registration) {
                foreach ((array) $registration->people as $person) {
                    fputcsv($out, [
                        $registration->order?->reference,
                        $registration->statusLabel(),
                        $person['name'] ?? '',
                        ($person['role'] ?? '') === 'registrant' ? 'Registrant' : 'Guest',
                        EventRegistration::GENDERS[$person['gender'] ?? ''] ?? '',
                        ($person['age_group'] ?? 'adult') === 'child' ? 'Child' : 'Adult',
                        $person['ticket'] ?? '',
                        EventRegistration::MEALS[$person['meal'] ?? ''] ?? '',
                        ! empty($person['with_membership']) ? 'Yes' : '',
                        $person['email'] ?? '',
                        $person['phone'] ?? '',
                        $registration->displayName(),
                        $registration->order?->total,
                        $registration->created_at?->format('Y-m-d H:i'),
                    ], ',', '"', '');
                }
            }
            fclose($out);
        }, $event->slug.'-attendees.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Feedback on: start from the event type's questions; keep the attendee form in step. */
    /** A new cover replaces the old one; "remove" deletes it. Kept on the public disk. */
    private function saveCover(Event $event, Request $request): void
    {
        $file = $request->file('cover_image');
        if (! $file && ! $request->boolean('remove_cover')) {
            return;
        }
        if ($event->cover_image) {
            Storage::disk('public')->delete($event->cover_image);
        }
        $event->forceFill(['cover_image' => $file ? $file->store('events/'.$event->uid, 'public') : null])->saveQuietly();
    }

    private function afterSave(Event $event, ?int $by = null, array $tickets = []): void
    {
        // Paid: its tickets as commerce products, and its registration forms. Free: off sale.
        if ($event->is_paid) {
            $settings = (array) $event->paid_settings;
            $settings['tickets'] = EventProducts::sync($event->fresh(), $tickets, $by);
            $event->forceFill(['paid_settings' => $settings])->saveQuietly();
            // Asks for a donation: the organisation needs a donation product (made once, then reused).
            if (! empty($settings['ask_donation'])) {
                EventProducts::ensureDonation($event, $by);
            }
            EventForms::syncPaid($event->fresh('organisation'));
        } elseif (! empty(((array) $event->paid_settings)['tickets'])) {
            EventProducts::stopSelling($event);
        }

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
            'location_type' => $event->location_type ?: Event::LOCATION_IN_PERSON,
            'theme' => $event->color(),
            'maps_url' => $event->maps_url,
            // "Remove the current cover image" shows only when there is one.
            'has_cover' => (bool) $event->cover_image,
            'remove_cover' => false,
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
            'limit_places' => $event->capacity !== null,
            'is_paid' => (bool) $event->is_paid,
            // The ticket rows of the form (their products' current prices).
            'child_age' => $event->paid('child_age'),
            'ask_donation' => (bool) $event->paid('ask_donation', false),
            'with_membership' => (bool) $event->paid('with_membership', false),
            'tickets' => collect((array) $event->paid('tickets', []))->map(fn ($t) => [
                'product_id' => $t['product_id'] ?? null,
                'name' => $t['name'] ?? '',
                'price' => $t['price'] ?? 0,
                'limit' => $t['limit'] ?? null,
                'includes' => $t['includes'] ?? null,
                'for' => $t['for'] ?? 'everyone',
                'admin_only' => (bool) ($t['admin_only'] ?? false),
            ])->values()->all(),
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
            'location_type' => ['nullable', Rule::in(Event::LOCATIONS)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            // In person needs the venue; online needs the link.
            'venue' => ['nullable', 'string', 'max:255', 'required_unless:location_type,online'],
            'address' => ['nullable', 'string', 'max:1000'],
            'online_url' => ['nullable', 'url:http,https', 'max:255', 'required_if:location_type,online'],
            'visibility' => ['required', Rule::in($publicAllowed ? [Event::VISIBILITY_MEMBERS, Event::VISIBILITY_PUBLIC] : [Event::VISIBILITY_MEMBERS])],
            'status' => ['required', Rule::in(Event::STATUSES)],
            'registration_open' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_if_accepted:limit_places'],
            'registration_closes_at' => ['nullable', 'date', 'before_or_equal:starts_at'],
            'feedback_enabled' => ['nullable', 'boolean'],
            'feedback_anonymous' => ['nullable', 'boolean'],
            'is_paid' => ['nullable', 'boolean'],
            'limit_places' => ['nullable', 'boolean'],
            // The page's look.
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_cover' => ['nullable', 'boolean'],
            'theme' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'maps_url' => ['nullable', 'url:http,https', 'max:500'],
            // The tickets (made into commerce products when saved).
            'tickets' => ['nullable', 'array', 'max:30', 'required_if_accepted:is_paid'],
            'tickets.*.product_id' => ['nullable', 'integer'],
            'tickets.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'tickets.*.price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'tickets.*.limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'tickets.*.includes' => ['nullable', 'string', 'max:255'],
            'tickets.*.for' => ['nullable', Rule::in(EventProducts::FOR)],
            'child_age' => ['nullable', 'integer', 'min:0', 'max:17'],
            'ask_donation' => ['nullable', 'boolean'],
            'with_membership' => ['nullable', 'boolean'],
            'tickets.*.admin_only' => ['nullable', 'boolean'],
        ], [
            'tickets.required_if_accepted' => 'Add at least one ticket with its price.',
            'tickets.*.name.required' => 'Name the ticket (e.g. Standard pass).',
            'tickets.*.name.distinct' => 'Two tickets have the same name.',
            'tickets.*.price.required' => 'Enter the price (0 for free).',
            'capacity.required_if_accepted' => 'How many places?',
            'venue.required_unless' => 'Where is it? Enter the venue (or choose Online).',
            'theme.regex' => 'Choose a colour.',
            'maps_url.url' => 'Paste the link from Google Maps (it starts with https://).',
            'online_url.required_if' => 'Enter the link people join with.',
            'organisation_id.in' => "You don't manage that organisation.",
            'visibility.in' => 'Public events are switched off for this app.',
        ]);

        foreach (['registration_open', 'feedback_enabled', 'feedback_anonymous', 'is_paid'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        // Places: a number only when limited (the tick box; older clients send just the number).
        if ($request->has('limit_places') && ! $request->boolean('limit_places')) {
            $data['capacity'] = null;
        }
        unset($data['limit_places'], $data['cover_image'], $data['remove_cover']);
        $data['theme'] = strtolower(($data['theme'] ?? null) ?: Event::DEFAULT_COLOR);

        // Only what fits where it happens is kept.
        $data['location_type'] = ($data['location_type'] ?? null) ?: Event::LOCATION_IN_PERSON;
        if ($data['location_type'] === Event::LOCATION_ONLINE) {
            $data['venue'] = null;
            $data['address'] = null;
            $data['maps_url'] = null;
        } else {
            $data['online_url'] = null;
        }

        return $data;
    }

    /**
     * The event's own columns, and its paid settings (kept with the product ids made earlier).
     *
     * @return array{0: array, 1: array}
     */
    private function splitPaid(array $data, ?Event $event): array
    {
        $settings = (array) ($event?->paid_settings ?? []);
        if (array_key_exists('child_age', $data)) {
            $settings['child_age'] = $data['child_age'] === null ? Event::PAID_DEFAULTS['child_age'] : (int) $data['child_age'];
        }
        if (array_key_exists('ask_donation', $data)) {
            $settings['ask_donation'] = filter_var($data['ask_donation'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('with_membership', $data)) {
            $settings['with_membership'] = filter_var($data['with_membership'], FILTER_VALIDATE_BOOLEAN);
        }
        unset($data['tickets'], $data['child_age'], $data['ask_donation'], $data['with_membership']);

        return [$data, $settings];
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
        $event = Event::query()->with('organisation:id,uid,name')->where('uid', $uid)->firstOrFail();
        abort_unless(EventAccess::canManageEvent($request->user(), $event), 404);

        return $event;
    }
}
