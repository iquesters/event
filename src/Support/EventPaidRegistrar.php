<?php

namespace Iquesters\Event\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Models\OrderLine;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Commerce\Support\OrderException;
use Illuminate\Validation\ValidationException;
use Iquesters\Event\Contracts\MembershipDetails;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Foundation\Support\UserModel;
use Iquesters\Foundation\System\Traits\Loggable;

/**
 * Paid registration: the registrant and their guests become one commerce
 * order, one line per person for the ticket they picked (the event's tickets,
 * made as commerce products), plus a donation.
 *
 * A ticket with a membership needs the person's email (the membership is
 * theirs) and isn't for someone who already paid that membership. Admins can
 * also give admin-only tickets (crew, invited guests) and add people without
 * an account.
 *
 * Each line keeps the person (name, gender, meal, ticket, email, phone) in its
 * meta, so community can give them their membership when the order is paid.
 * The registration follows its order's status (see syncFromOrder).
 */
class EventPaidRegistrar
{
    use Loggable;

    public const MAX_PEOPLE = 15;

    public function __construct(private OrderService $orders) {}

    /** Validation rules of the registration form (registrant, guests, donation, terms). */
    public static function rules(Event $event, bool $admin = false): array
    {
        $genders = array_keys(EventRegistration::GENDERS);
        $meals = array_keys(EventRegistration::MEALS);
        $codes = array_keys(EventRegistration::PHONE_CODES);
        // A ticket is chosen only when there is a choice; empty: picked by age and membership.
        $tickets = array_map(fn ($t) => (string) $t['product']->id, EventProducts::tickets($event, $admin));
        $phone = ['string', 'max:20', 'regex:/^[0-9 ()-]{5,20}$/'];
        // The membership module's fields (e.g. school_level, school_year): kept here so
        // validate() passes them on; that module checks them (people()).
        $details = app()->bound(MembershipDetails::class) ? app(MembershipDetails::class) : null;
        $detailRules = [];
        $donationProduct = EventProducts::donationOffered($event);
        $donationLimits = $donationProduct ? $donationProduct->amountLimits() : ['min' => 1, 'max' => null];
        foreach ($details ? array_column($details->fields($event), 'id') : [] as $id) {
            $detailRules[$id] = ['nullable'];
            $detailRules['guests.*.'.$id] = ['nullable'];
        }

        // Meals are for people coming in person: an online-only event asks none; "In person and online": only In person.
        $meal = match (true) {
            $event->isHybrid() => ['nullable', 'required_if:attend_mode,'.EventRegistration::ATTEND_IN_PERSON, Rule::in($meals)],
            $event->isOnline() => ['nullable', Rule::in($meals)],
            default => ['required', Rule::in($meals)],
        };

        return $detailRules + [
            'attend_mode' => [$event->isHybrid() ? 'required' : 'nullable', Rule::in(array_keys(EventRegistration::ATTEND_MODES))],
            'name' => ['required', 'string', 'max:255'],
            'email' => [$admin ? 'nullable' : 'required', 'email', 'max:255'],
            'phone_code' => ['nullable', Rule::in($codes)],
            'phone' => ['required', ...$phone],
            'gender' => ['required', Rule::in($genders)],
            'meal' => $meal,
            'member' => ['nullable', 'boolean'],
            'ticket' => ['nullable', Rule::in($tickets)],
            'guests' => ['nullable', 'array', 'max:'.(self::MAX_PEOPLE - 1)],
            'guests.*.member' => ['nullable', 'boolean'],
            'guests.*.name' => ['required', 'string', 'max:255'],
            'guests.*.relation' => ['required', Rule::in(array_keys(EventRegistration::RELATIONS))],
            'guests.*.gender' => ['required', Rule::in($genders)],
            'guests.*.age_group' => ['required', Rule::in(['adult', 'child'])],
            // In person and online: each guest says how they attend; their meal only when in person.
            'guests.*.attend_mode' => [$event->isHybrid() ? 'required' : 'nullable', Rule::in(array_keys(EventRegistration::ATTEND_MODES))],
            'guests.*.meal' => $event->isHybrid() ? ['nullable', 'required_if:guests.*.attend_mode,'.EventRegistration::ATTEND_IN_PERSON, Rule::in($meals)] : $meal,
            'guests.*.email' => ['nullable', 'email', 'max:255', 'required_if_accepted:guests.*.member'],
            'guests.*.phone_code' => ['nullable', Rule::in($codes)],
            // A guest's mobile is optional; a member's is needed (checked in people(): only an adult can be one).
            'guests.*.phone' => ['nullable', ...$phone],
            'guests.*.ticket' => ['nullable', Rule::in($tickets)],
            // Empty: no donation; else within the donation product's limits (₹1 or more when it sets none).
            'donation' => ['nullable', 'numeric', 'min:'.$donationLimits['min'], 'max:'.($donationLimits['max'] ?? 10000000)],
            'accept_terms' => [$admin ? 'nullable' : 'accepted'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public static function messages(?Event $event = null): array
    {
        // The donation's limits in words, e.g. "₹100 or more".
        $donation = $event ? EventProducts::donationOffered($event) : null;
        $limits = $donation ? $donation->amountLimitsText() : '₹1 or more';

        return [
            'donation.min' => "A donation is {$limits} (or leave it empty).",
            'donation.max' => "A donation is {$limits}.",
            'accept_terms.accepted' => 'Please agree to the Terms and the Privacy policy.',
            'attend_mode.required' => 'Choose how you will attend: in person or online.',
            'meal.required_if' => 'Choose the meal.',
            'guests.*.meal.required_if' => 'Choose the meal.',
            'guests.*.attend_mode.required' => 'Choose how this guest will attend: in person or online.',
            'ticket.in' => EventWords::t('Choose one of this event\'s tickets.'),
            'guests.*.ticket.in' => EventWords::t('Choose one of this event\'s tickets.'),
            'phone.regex' => 'Enter the mobile number (digits and spaces), without the country code.',
            'guests.*.phone.regex' => 'Enter the mobile number (digits and spaces), without the country code.',
            'guests.*.relation.required' => 'Choose how this guest is related to you.',
            'guests.*.relation.in' => 'Choose how this guest is related to you.',
            'guests.*.email.required_if_accepted' => 'A member needs their email: the membership is theirs.',
        ];
    }

    /** The person's registration that still holds places (waiting for payment or confirmed). */
    public function activeRegistration(Authenticatable $user, Event $event): ?EventRegistration
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('status', EventRegistration::ACTIVE_STATUSES)
            ->with('order')
            ->first();
    }

    /**
     * @param  array  $data  validated by rules()
     * @param  array{admin?: bool, by?: ?int}  $options  admin: added from the back office (no deadline, private crew pass, no account needed)
     *
     * @throws \DomainException with a message for the person
     * @throws OrderException
     */
    public function register(Event $event, array $data, ?Authenticatable $user, array $options = []): EventRegistration
    {
        $admin = (bool) ($options['admin'] ?? false);
        $by = $options['by'] ?? ($user ? (int) $user->getAuthIdentifier() : null);
        if (! $event->is_paid) {
            throw new \DomainException('This event has no paid registration.');
        }
        if (! $admin && (! $user || ! EventAccess::mayRegister($user, $event))) {
            throw new \DomainException('Only members of '.$event->organisation?->name.' can register.');
        }
        // An admin adds someone: linked to their account when the email has one.
        $userId = $admin ? $this->userIdByEmail($data['email'] ?? null) : (int) $user->getAuthIdentifier();

        // How they attend: chosen on an "In person and online" event, else the event's own way.
        $mode = $event->attendModeFor($data['attend_mode'] ?? null);
        $people = $this->people($event, $data, $admin, $mode);

        $registration = DB::transaction(function () use ($event, $data, &$people, $admin, $by, $userId, $mode) {
            $event = Event::query()->with('organisation')->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $existing = $userId ? EventRegistration::query()->where('event_id', $event->id)->where('user_id', $userId)->first() : null;
            if ($existing?->isActive()) {
                throw new \DomainException($admin ? 'This person is already registered.' : "You're already registered.");
            }
            if ($event->status === Event::STATUS_CANCELLED || $event->isPast()) {
                throw new \DomainException($event->status === Event::STATUS_CANCELLED ? 'This event was cancelled.' : 'This event is over.');
            }
            if (! $admin && ($reason = $event->registrationClosedReason())) {
                throw new \DomainException($reason);
            }
            // The places limit: everyone, or on an "In person and online" event those coming in person.
            $coming = $event->isHybrid() ? count(array_filter($people, fn ($p) => $p['attend_mode'] === EventRegistration::ATTEND_IN_PERSON)) : count($people);
            $left = $coming > 0 ? $event->seatsLeft() : null;
            if (! $admin && $left !== null && $left < $coming) {
                $where = $event->isHybrid() ? ' in person (you can still join online)' : '';
                throw new \DomainException($left === 0 ? "No places are left{$where}." : ($left === 1 ? "Only 1 place is left{$where}." : "Only {$left} places are left{$where}."));
            }

            $values = [
                'status' => EventRegistration::STATUS_AWAITING_PAYMENT,
                'name' => $people[0]['name'],
                'email' => $people[0]['email'],
                'phone' => $people[0]['phone'],
                'gender' => $people[0]['gender'],
                'meal' => $people[0]['meal'],
                // Kept for an "In person and online" event (else the event's own way).
                'attend_mode' => $event->isHybrid() ? $mode : null,
                // How many of them join online (each chooses).
                'online_people' => $event->isHybrid() ? count(array_filter($people, fn ($p) => $p['attend_mode'] === EventRegistration::ATTEND_ONLINE)) : 0,
                'guests' => count($people) - 1,
                'adults' => count(array_filter($people, fn ($p) => $p['age_group'] === 'adult')),
                'children' => count(array_filter($people, fn ($p) => $p['age_group'] === 'child')),
                'veg' => count(array_filter($people, fn ($p) => $p['meal'] === 'veg')),
                'non_veg' => count(array_filter($people, fn ($p) => $p['meal'] === 'non_veg')),
                'donation' => round((float) ($data['donation'] ?? 0), 2),
                'people' => $people,
                'note' => isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 500) : null,
                'source' => $admin ? 'admin' : 'self',
                'terms_accepted_at' => $admin ? null : now(),
                'added_by' => $admin ? $by : null,
                'order_id' => null,
            ];
            // A cancelled registration of the same person is used again (one per person per event).
            $registration = $existing
                ? tap($existing)->update($values)
                : EventRegistration::create($values + ['event_id' => $event->id, 'user_id' => $userId]);

            $order = $this->orders->create($event->organisation, [
                'user_id' => $userId,
                'name' => $people[0]['name'],
                'email' => $people[0]['email'],
                'phone' => $people[0]['phone'],
            ], $this->items($event, $registration, $people, $admin, $userId, (float) ($data['donation'] ?? 0), $mode), [
                // Numbered by commerce (its order_reference_prefix setting).
                'source' => $admin ? 'admin' : 'event_registration',
                'ref_type' => 'event_registration',
                'ref_id' => $registration->id,
                'admin' => $admin,
                'by' => $by,
                'notes' => $event->title,
            ]);

            $registration->update([
                // Each person with their ticket's name.
                'people' => $people,
                'order_id' => $order->id,
                'status' => self::statusFor($order->status) ?? EventRegistration::STATUS_AWAITING_PAYMENT,
            ]);

            return $registration;
        });

        static::logInfo('Paid registration', ['event' => $event->uid, 'registration' => $registration->uid, 'people' => count($people), 'admin' => $admin]);
        // The email: payment due, or confirmed when there's nothing to pay (notifications module, if installed).
        EventNotifications::registered($registration->fresh(['order', 'event.organisation', 'user']));

        return $registration->fresh(['order', 'event']);
    }

    /** Cancel a registration whose order isn't paid (its order is cancelled too, giving back stock). */
    public function cancel(EventRegistration $registration, ?int $by = null, ?string $reason = null): void
    {
        $order = $registration->order;
        if ($order?->isPaid() && (float) $order->total > 0) {
            throw new \DomainException('This registration is paid. Please contact the organisers to cancel it.');
        }
        DB::transaction(function () use ($registration, $order, $by, $reason) {
            // A free order stays paid (nothing to give back); an unpaid one is cancelled.
            if ($order && ! $order->isPaid() && $order->status !== Order::STATUS_CANCELLED) {
                $this->orders->cancel($order, $reason ?? 'Registration cancelled', $by);
            }
            $registration->update(['status' => EventRegistration::STATUS_CANCELLED]);
        });
    }

    /** The order's status changed (commerce OrderStatusChanged): the registration follows it. */
    public static function syncFromOrder(Order $order): void
    {
        if ($order->ref_type !== 'event_registration' || ! $order->ref_id) {
            return;
        }
        $status = self::statusFor($order->status);
        $registration = EventRegistration::query()->whereKey($order->ref_id)->first();
        if ($status && $registration && $registration->status !== $status) {
            $registration->update(['status' => $status]);
            // Paid and joining online: their link by email.
            if ($status === EventRegistration::STATUS_REGISTERED) {
                EventNotifications::joinOnline($registration);
            }
        }
    }

    public static function statusFor(string $orderStatus): ?string
    {
        return match ($orderStatus) {
            Order::STATUS_PAID => EventRegistration::STATUS_REGISTERED,
            Order::STATUS_UNDER_REVIEW => EventRegistration::STATUS_UNDER_REVIEW,
            Order::STATUS_AWAITING_PAYMENT, Order::STATUS_REJECTED => EventRegistration::STATUS_AWAITING_PAYMENT,
            Order::STATUS_CANCELLED => EventRegistration::STATUS_CANCELLED,
            default => null,
        };
    }

    /**
     * The tickets people can pick, for the event page.
     *
     * @return array<int, array{name: string, price: float, includes: ?string, left: ?int}> left: null when not limited
     */
    public static function prices(Event $event): array
    {
        $all = EventProducts::tickets($event);
        $sellsMembership = EventProducts::sellsMembership($event);
        // The tickets people pay for; a bundle with membership isn't a line of its own but
        // a note on its ticket ("Membership 2027 included" or "with Membership 2027: ₹900").
        $lines = collect($all)
            ->filter(fn ($t) => ! $t['bundle'] && $t['product']->isOnSale())
            ->mapWithKeys(fn ($t) => [(int) $t['product']->id => ['name' => $t['name'], 'price' => (float) $t['product']->price, 'includes' => $t['includes'] ?? null, 'left' => $t['product']->stockLeft(), 'membership' => null]])
            ->all();
        foreach ($all as $t) {
            if (! $t['bundle'] || ! $sellsMembership || ! $t['product']->isOnSale()) {
                continue;
            }
            $ticketId = (int) collect($t['ticket_ids'] ?? [])->first(fn ($id) => isset($lines[(int) $id]));
            if (! $ticketId || $lines[$ticketId]['membership'] !== null) {
                continue;
            }
            $ticketIds = array_map('intval', $t['ticket_ids'] ?? []);
            $membership = $t['product']->bundleItems->pluck('product')->filter()
                ->reject(fn ($p) => in_array((int) $p->id, $ticketIds, true))
                ->pluck('name')->implode(' + ');
            $price = (float) $t['product']->price;
            $lines[$ticketId]['membership'] = [
                'name' => $membership ?: $t['name'],
                // null: the same price as the ticket (the membership costs nothing extra).
                'price' => abs($price - $lines[$ticketId]['price']) < 0.005 ? null : $price,
            ];
        }

        return array_values($lines);
    }

    /**
     * What the form as filled in so far costs (shown before the terms): each
     * person's ticket, as the order will pick it, the donation and the total.
     * Lenient: a half-filled person is priced as an adult without membership.
     *
     * @return array{lines: array<int, array{who: string, ticket: ?string, price: ?float}>, donation: float, total: float}
     */
    public function quote(Event $event, array $data, ?Authenticatable $user): array
    {
        $userId = $user ? (int) $user->getAuthIdentifier() : null;
        $sellsMembership = EventProducts::sellsMembership($event);
        $people = [['who' => trim((string) ($data['name'] ?? '')) ?: 'You', 'age_group' => 'adult', 'member' => $sellsMembership, 'email' => $data['email'] ?? null]];
        foreach (array_values(array_filter((array) ($data['guests'] ?? []), 'is_array')) as $i => $guest) {
            $age = ($guest['age_group'] ?? 'adult') === 'child' ? 'child' : 'adult';
            $people[] = [
                'who' => trim((string) ($guest['name'] ?? '')) ?: 'Guest '.($i + 1),
                'age_group' => $age,
                'member' => $sellsMembership && $age === 'adult' && filter_var($guest['member'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'email' => $guest['email'] ?? null,
                'mode' => $event->attendModeFor($guest['attend_mode'] ?? null),
            ];
        }
        // How the registrant attends (each guest says their own).
        $people[0]['mode'] = $event->attendModeFor($data['attend_mode'] ?? null);
        $lines = [];
        foreach ($people as $index => $person) {
            $email = ($e = mb_strtolower(trim((string) $person['email']))) !== '' ? $e : null;
            try {
                $ticket = EventProducts::choose($event, $person['age_group'], $person['member'], null, false,
                    fn (int $membershipId) => $this->hasMembership($membershipId, $index === 0 ? $userId : null, $email), $person['who'], $person['mode']);
                $lines[] = ['who' => $person['who'], 'ticket' => $ticket['name'], 'price' => round((float) $ticket['product']->price, 2)];
            } catch (\DomainException) {
                $lines[] = ['who' => $person['who'], 'ticket' => null, 'price' => null];
            }
        }
        $donation = EventProducts::donationOffered($event) ? max(0, round((float) ($data['donation'] ?? 0), 2)) : 0.0;

        return [
            'lines' => $lines,
            'donation' => $donation,
            'total' => round(array_sum(array_map(fn ($l) => (float) $l['price'], $lines)) + $donation, 2),
        ];
    }

    /** @return array<int, array{name: string, email: ?string, phone: ?string, gender: string, meal: string, age_group: string, ticket_id: int, role: string}> */
    private function people(Event $event, array $data, bool $admin = false, ?string $mode = null): array
    {
        // Membership details (e.g. the school they completed): checked by the membership module.
        $details = app()->bound(MembershipDetails::class) ? app(MembershipDetails::class) : null;
        $fieldIds = $details ? array_column($details->fields($event), 'id') : [];
        $errors = [];

        $sellsMembership = EventProducts::sellsMembership($event);
        $person = function (array $p, string $role, string $at) use ($event, $details, $fieldIds, &$errors, $sellsMembership, $admin, $mode) {
            // How they attend: the registrant as chosen at the top, a guest their own way; meals only in person.
            $way = $role === 'registrant' ? ($mode ?? $event->attendModeFor(null)) : $event->attendModeFor($p['attend_mode'] ?? null);
            $inPerson = $way === EventRegistration::ATTEND_IN_PERSON;
            // The person registering joins (no question); a guest when ticked; an admin says for each.
            $member = $sellsMembership && ($p['age_group'] ?? 'adult') !== 'child'
                && (($role === 'registrant' && ! $admin) || filter_var($p['member'] ?? false, FILTER_VALIDATE_BOOLEAN));
            // A member is an adult (the form offers no "Child" after "Joining as a member?" Yes).
            if ($sellsMembership && ($p['age_group'] ?? 'adult') === 'child' && filter_var($p['member'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $errors[$at.'age_group'] = 'A child can’t join as a member: choose Adult, or answer No to “Joining as a member?”.';
            }
            $phone = trim((string) ($p['phone'] ?? ''));
            // A guest's mobile is optional; a guest joining as a member needs one (the membership is theirs).
            if ($member && $role === 'guest' && $phone === '' && ! $admin) {
                $errors[$at.'phone'] = 'A member needs their mobile number.';
            }
            $kept = [];
            if ($member && $details) {
                [$kept, $fieldErrors] = $details->validate($event, array_intersect_key($p, array_flip($fieldIds)));
                foreach ($fieldErrors as $field => $message) {
                    $errors[$at.$field] = $message;
                }
            }

            return [
                'name' => trim((string) $p['name']),
                'email' => ($e = trim((string) ($p['email'] ?? ''))) !== '' ? mb_strtolower($e) : null,
                // With its country code: "+91 98000 00001".
                'phone' => $phone !== '' ? trim(($p['phone_code'] ?? '+91').' '.$phone) : null,
                'gender' => (string) $p['gender'],
                'meal' => $inPerson ? (string) ($p['meal'] ?? '') : null,
                'attend_mode' => $way,
                'age_group' => ($p['age_group'] ?? 'adult') === 'child' ? 'child' : 'adult',
                // A guest's relation to the person registering.
                'relation' => $role === 'guest' && isset(EventRegistration::RELATIONS[$p['relation'] ?? '']) ? (string) $p['relation'] : null,
                'member' => $member,
                'member_details' => $kept,
                // What they entered (e.g. school_level, school_year), to show again when the details are corrected.
                'member_input' => $member && $details ? array_intersect_key($p, array_flip($fieldIds)) : [],
                'ticket_id' => ! empty($p['ticket']) ? (int) $p['ticket'] : null,
                'role' => $role,
            ];
        };
        $people = [$person($data, 'registrant', '')];
        foreach (array_values((array) ($data['guests'] ?? [])) as $i => $guest) {
            $people[] = $person($guest, 'guest', "guests.{$i}.");
        }
        if (count($people) > self::MAX_PEOPLE) {
            throw new \DomainException('At most '.self::MAX_PEOPLE.' people in one registration.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $people;
    }

    /**
     * One line per person: their ticket. A ticket with a membership needs the
     * person's email (the membership is theirs) and isn't for someone who
     * already paid that membership.
     */
    private function items(Event $event, EventRegistration $registration, array &$people, bool $admin, ?int $userId, float $donation, ?string $mode = null): array
    {
        $items = [];
        $seen = [];
        foreach ($people as $index => &$person) {
            // By age, and with membership for a member (unless they already have it); or the one they chose.
            $ticket = EventProducts::choose(
                $event,
                $person['age_group'],
                $person['member'],
                $person['ticket_id'],
                $admin,
                fn (int $membershipId) => $this->hasMembership($membershipId, $index === 0 ? $userId : null, $person['email']),
                $person['name'],
                $person['attend_mode'] ?? $mode,
            );
            $person['ticket'] = $ticket['name'];
            // Its product: to check it still fits when they change how they attend.
            $person['ticket_product_id'] = (int) $ticket['product']->id;
            $membershipId = (int) ($ticket['membership_product_id'] ?? 0);
            $person['with_membership'] = $membershipId > 0;
            if ($membershipId) {
                if (! $person['email']) {
                    throw new \DomainException(EventWords::t("{$person['name']}: a ticket with membership needs their email (the membership is theirs)."));
                }
                if (in_array($person['email'], $seen, true)) {
                    throw new \DomainException(EventWords::t("{$person['email']} is on two tickets with membership."));
                }
                if ($this->hasMembership($membershipId, $index === 0 ? $userId : null, $person['email'])) {
                    throw new \DomainException(EventWords::t("{$person['name']} already has this membership: choose a ticket without it."));
                }
                $seen[] = $person['email'];
            }
            $items[] = ['product' => (int) $ticket['product']->id, 'meta' => array_filter([
                'person' => $person['name'],
                'gender' => EventRegistration::GENDERS[$person['gender']] ?? $person['gender'],
                'meal' => $person['meal'] ? (EventRegistration::MEALS[$person['meal']] ?? $person['meal']) : null,
                'attend' => $event->isHybrid() ? EventRegistration::ATTEND_MODES[$person['attend_mode'] ?? $mode] ?? null : null,
                'relation' => EventRegistration::RELATIONS[$person['relation'] ?? ''] ?? null,
                'age_group' => $person['age_group'],
                'ticket' => $ticket['name'],
                'role' => $person['role'],
                'email' => $person['email'],
                'phone' => $person['phone'],
                'user_id' => $index === 0 ? $userId : null,
                'event' => $event->uid,
                'registration' => $registration->uid,
                // For the membership module (e.g. the school they completed).
                'member_details' => $membershipId && $person['member_details'] !== [] ? $person['member_details'] : null,
            ], fn ($v) => $v !== null && $v !== '')];
        }
        unset($person);

        if ($donation > 0) {
            $product = EventProducts::donationOffered($event);
            if (! $product) {
                throw new \DomainException('This event takes no donations.');
            }
            $items[] = ['product' => (int) $product->id, 'amount' => $donation, 'meta' => ['registration' => $registration->uid]];
        }

        return $items;
    }

    /** Already paid this membership (by their account, or the email on a paid line)? */
    private function hasMembership(int $membershipProductId, ?int $userId, ?string $email): bool
    {
        if (! $userId && ! $email) {
            return false;
        }

        return OrderLine::query()
            ->where('product_id', $membershipProductId)
            ->whereHas('order', fn ($q) => $q->whereIn('status', [Order::STATUS_PAID, Order::STATUS_UNDER_REVIEW]))
            ->with('order:id,user_id,status')
            ->get(['id', 'order_id', 'meta'])
            ->contains(function (OrderLine $line) use ($userId, $email) {
                $meta = (array) $line->meta;

                return ($userId && ((int) ($meta['user_id'] ?? 0) === $userId))
                    || ($email && mb_strtolower((string) ($meta['email'] ?? '')) === $email);
            });
    }

    private function userIdByEmail(?string $email): ?int
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }
        $id = UserModel::query()->whereRaw('LOWER(email) = ?', [$email])->value('id');

        return $id ? (int) $id : null;
    }
}
