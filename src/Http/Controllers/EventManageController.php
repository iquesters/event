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
use Iquesters\Event\Support\EventPaidRegistrar;
use Iquesters\Event\Support\EventProducts;
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
        [$data, $paid] = $this->splitPaid($this->validated($request), null);
        $event = Event::create($data + ['paid_settings' => $paid, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->afterSave($event, (int) $request->user()->id);
        static::logInfo('Event created', ['event' => $event->uid, 'user_id' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => $event->status === Event::STATUS_PUBLISHED ? 'Event published.' : 'Event saved.', 'data' => $this->row($event)], 201);
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        [$data, $paid] = $this->splitPaid($this->validated($request), $event);
        $event->update($data + ['paid_settings' => $paid, 'updated_by' => $request->user()->id]);
        $this->afterSave($event, (int) $request->user()->id);

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
        $data = $request->validate(EventPaidRegistrar::rules($event, true), EventPaidRegistrar::messages());

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
    public function registration(Request $request, string $uid): View
    {
        $registration = EventRegistration::query()->with(['event', 'order', 'user:id,name,email'])->where('uid', $uid)->firstOrFail();
        $event = $this->find($request, $registration->event->uid);

        return view('event::manage.registration', [
            'event' => $event,
            'registration' => $registration,
            'canCancel' => $registration->isActive() && ! ($registration->order?->isPaid() && (float) $registration->order->total > 0),
            'payUrl' => $registration->order && ! $registration->order->isPaid() && Route::has('payment.pay')
                ? route('payment.pay', $registration->order->uid) : null,
        ]);
    }

    /** Cancel a registration that isn't paid (its order is cancelled too); form event-registration-admin-cancel, {uid} = the registration. */
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

    /** The banners form (event-banners) posts here. */
    public function banners(Request $request, string $uid): JsonResponse
    {
        $event = $this->find($request, $uid);
        $request->validate([
            'banner_desktop' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'banner_tablet' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'banner_mobile' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove' => ['nullable', 'boolean'],
        ]);

        $disk = Storage::disk('public');
        $changes = [];
        foreach (Event::BANNER_SIZES as $size) {
            $column = 'banner_'.$size;
            if ($request->boolean('remove') || $request->hasFile($column)) {
                if ($event->{$column}) {
                    $disk->delete($event->{$column});
                }
                $changes[$column] = $request->hasFile($column) && ! $request->boolean('remove')
                    ? $request->file($column)->store('events/'.$event->uid.'/banners', 'public')
                    : null;
            }
        }
        $event->update($changes + ['updated_by' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => $request->boolean('remove') ? 'Banners removed.' : 'Banners saved.']);
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
            fputcsv($out, ['Reference', 'Status', 'Person', 'Registrant / guest', 'Gender', 'Age', 'Meal', 'Alumnus', 'Email', 'Mobile', 'Registered by', 'Order total', 'Registered at'], ',', '"', '');
            foreach ($registrations as $registration) {
                foreach ((array) $registration->people as $person) {
                    fputcsv($out, [
                        $registration->order?->reference,
                        $registration->statusLabel(),
                        $person['name'] ?? '',
                        ($person['role'] ?? '') === 'registrant' ? 'Registrant' : 'Guest',
                        EventRegistration::GENDERS[$person['gender'] ?? ''] ?? '',
                        ($person['age_group'] ?? 'adult') === 'child' ? $event->paid('free_child_age').' or under' : 'Adult',
                        EventRegistration::MEALS[$person['meal'] ?? ''] ?? '',
                        ! empty($person['alumnus']) ? 'Yes' : 'No',
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
    private function afterSave(Event $event, ?int $by = null): void
    {
        // Paid: its products in commerce and its registration forms.
        $event->load('organisation');
        if ($event->is_paid || ! empty(((array) $event->paid_settings)['products'])) {
            EventProducts::sync($event, $by);
            EventForms::syncPaid($event->fresh('organisation'));
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
            'is_paid' => (bool) $event->is_paid,
            'pass_price' => $event->is_paid ? $event->paid('pass_price') : null,
            'bundle_price' => $event->is_paid ? $event->paid('bundle_price') : null,
            'membership_price' => $event->is_paid ? $event->paid('membership_price') : null,
            'membership_from' => $event->paid('membership_from'),
            'membership_until' => $event->paid('membership_until'),
            'free_child_age' => $event->paid('free_child_age'),
            'reference_prefix' => $event->reference_prefix,
            'donation' => (bool) $event->paid('donation'),
            'approval' => $event->paid('approval'),
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
            'is_paid' => ['nullable', 'boolean'],
            'pass_price' => ['nullable', 'required_if_accepted:is_paid', 'numeric', 'min:0', 'max:10000000'],
            'bundle_price' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'required_with:membership_from'],
            'membership_price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'membership_from' => ['nullable', 'date', 'required_with:membership_until'],
            'membership_until' => ['nullable', 'date', 'after:membership_from', 'required_with:membership_from'],
            'free_child_age' => ['nullable', 'integer', 'min:0', 'max:17'],
            'reference_prefix' => ['nullable', 'string', 'max:24', 'regex:/^[A-Za-z0-9 -]+$/'],
            'donation' => ['nullable', 'boolean'],
            'approval' => ['nullable', Rule::in(['together', 'separate'])],
        ], [
            'pass_price.required_if_accepted' => 'Set the pass price of a paid event.',
            'reference_prefix.regex' => 'Letters, digits and dashes only (e.g. TTV-MEET).',
            'organisation_id.in' => "You don't manage that organisation.",
            'visibility.in' => 'Public events are switched off for this app.',
        ]);

        foreach (['registration_open', 'feedback_enabled', 'feedback_anonymous', 'is_paid', 'donation'] as $flag) {
            $data[$flag] = $request->boolean($flag);
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
        $keys = ['pass_price', 'bundle_price', 'membership_price', 'membership_from', 'membership_until', 'free_child_age', 'donation', 'approval'];
        $settings = (array) ($event?->paid_settings ?? []);
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
            }
            unset($data[$key]);
        }
        $settings['free_child_age'] = (int) ($settings['free_child_age'] ?? Event::PAID_DEFAULTS['free_child_age']);
        $settings['approval'] = ($settings['approval'] ?? null) ?: Event::PAID_DEFAULTS['approval'];
        if (array_key_exists('reference_prefix', $data)) {
            $data['reference_prefix'] = $data['reference_prefix'] ? strtoupper(trim((string) preg_replace('/[\s-]+/', '-', $data['reference_prefix']), '-')) : null;
        }

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
        $event = Event::query()->with('organisation:id,name')->where('uid', $uid)->firstOrFail();
        abort_unless(EventAccess::canManageEvent($request->user(), $event), 404);

        return $event;
    }
}
