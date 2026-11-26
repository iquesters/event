<?php

namespace Iquesters\Event\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Models\OrderLine;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Commerce\Support\OrderException;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Foundation\Support\UserModel;
use Iquesters\Foundation\System\Traits\Loggable;

/**
 * Paid registration: the registrant and their guests become one commerce
 * order, one line per person (plus a donation):
 *
 * - an alumnus (the registrant, or a guest who is one): the pass with
 *   membership, unless they already paid the membership for that period
 *   (then the pass);
 * - another guest over the free age: the pass;
 * - a child up to the free age: the free child pass;
 * - crew / invited guests (admins only): the private free pass.
 *
 * Each line keeps the person (name, gender, meal, email, phone) in its meta, so
 * community can give alumni their membership when the order is paid. The
 * registration follows its order's status (see syncFromOrder).
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9 +()-]{7,32}$/'],
            'email' => [$admin ? 'nullable' : 'required', 'email', 'max:255'],
            'gender' => ['required', Rule::in($genders)],
            'meal' => ['required', Rule::in($meals)],
            'alumnus' => ['nullable', 'boolean'],
            'guests' => ['nullable', 'array', 'max:'.(self::MAX_PEOPLE - 1)],
            'guests.*.name' => ['required', 'string', 'max:255'],
            'guests.*.gender' => ['required', Rule::in($genders)],
            'guests.*.age_group' => ['required', Rule::in(['adult', 'child'])],
            'guests.*.meal' => ['required', Rule::in($meals)],
            'guests.*.alumnus' => ['nullable', 'boolean'],
            'guests.*.email' => ['nullable', 'email', 'max:255', 'required_if_accepted:guests.*.alumnus'],
            'guests.*.phone' => ['nullable', 'string', 'max:32'],
            'donation' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'accept_terms' => [$admin ? 'nullable' : 'accepted'],
            'crew' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public static function messages(): array
    {
        return [
            'accept_terms.accepted' => 'Please agree to the Terms and the Privacy policy.',
            'guests.*.email.required_if_accepted' => 'An alumnus guest needs their email (their membership is linked to it).',
            'phone.regex' => 'Enter a mobile number (digits, + and spaces).',
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

        $people = $this->people($data);
        $crew = $admin && ! empty($data['crew']);

        $registration = DB::transaction(function () use ($event, $data, $people, $crew, $admin, $by, $userId) {
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
            $left = $event->seatsLeft();
            if (! $admin && $left !== null && $left < count($people)) {
                throw new \DomainException($left === 1 ? 'Only 1 place is left.' : "Only {$left} places are left.");
            }

            $values = [
                'status' => EventRegistration::STATUS_AWAITING_PAYMENT,
                'name' => $people[0]['name'],
                'email' => $people[0]['email'],
                'phone' => $people[0]['phone'],
                'gender' => $people[0]['gender'],
                'meal' => $people[0]['meal'],
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
            ], $this->items($event, $registration, $people, $crew, $userId, (float) ($data['donation'] ?? 0)), [
                'prefix' => $event->reference_prefix ?: 'EVT',
                'source' => $admin ? 'admin' : 'event_registration',
                'ref_type' => 'event_registration',
                'ref_id' => $registration->id,
                'admin' => $admin,
                'by' => $by,
                'notes' => $event->title,
            ]);

            $registration->update([
                'order_id' => $order->id,
                'status' => self::statusFor($order->status) ?? EventRegistration::STATUS_AWAITING_PAYMENT,
            ]);

            return $registration;
        });

        static::logInfo('Paid registration', ['event' => $event->uid, 'registration' => $registration->uid, 'people' => count($people), 'admin' => $admin]);

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
     * What each person costs (for the page before paying): role => price.
     *
     * @return array{pass: ?float, bundle: ?float, child: ?float}
     */
    public static function prices(Event $event): array
    {
        $products = EventProducts::products($event);

        return [
            'pass' => isset($products['pass']) ? (float) $products['pass']->price : null,
            'bundle' => isset($products['bundle']) && $products['bundle']->isOnSale() && $products['bundle']->isActive() ? (float) $products['bundle']->price : null,
            'child' => isset($products['child']) ? (float) $products['child']->price : null,
        ];
    }

    /** @return array<int, array{name: string, email: ?string, phone: ?string, gender: string, meal: string, age_group: string, alumnus: bool, role: string}> */
    private function people(array $data): array
    {
        $people = [[
            'name' => trim((string) $data['name']),
            'email' => ($e = trim((string) ($data['email'] ?? ''))) !== '' ? mb_strtolower($e) : null,
            'phone' => ($p = trim((string) ($data['phone'] ?? ''))) !== '' ? $p : null,
            'gender' => (string) $data['gender'],
            'meal' => (string) $data['meal'],
            'age_group' => 'adult',
            'alumnus' => filter_var($data['alumnus'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'role' => 'registrant',
        ]];
        foreach (array_values((array) ($data['guests'] ?? [])) as $guest) {
            $child = ($guest['age_group'] ?? 'adult') === 'child';
            $people[] = [
                'name' => trim((string) $guest['name']),
                'email' => ($e = trim((string) ($guest['email'] ?? ''))) !== '' ? mb_strtolower($e) : null,
                'phone' => ($p = trim((string) ($guest['phone'] ?? ''))) !== '' ? $p : null,
                'gender' => (string) $guest['gender'],
                'meal' => (string) $guest['meal'],
                'age_group' => $child ? 'child' : 'adult',
                // Children aren't alumni.
                'alumnus' => ! $child && filter_var($guest['alumnus'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'role' => 'guest',
            ];
        }
        if (count($people) > self::MAX_PEOPLE) {
            throw new \DomainException('At most '.self::MAX_PEOPLE.' people in one registration.');
        }

        return $people;
    }

    private function items(Event $event, EventRegistration $registration, array $people, bool $crew, ?int $userId, float $donation): array
    {
        $ids = (array) (((array) $event->paid_settings)['products'] ?? []);
        $require = function (string $role) use ($ids): int {
            if (empty($ids[$role])) {
                throw new \DomainException('This event\'s tickets are not set up yet. Please try again later.');
            }

            return (int) $ids[$role];
        };

        $items = [];
        $seenEmails = [];
        foreach ($people as $index => $person) {
            $meta = array_filter([
                'person' => $person['name'],
                'gender' => EventRegistration::GENDERS[$person['gender']] ?? $person['gender'],
                'meal' => EventRegistration::MEALS[$person['meal']] ?? $person['meal'],
                'age_group' => $person['age_group'],
                'role' => $person['role'],
                'email' => $person['email'],
                'phone' => $person['phone'],
                'user_id' => $index === 0 ? $userId : null,
                'event' => $event->uid,
                'registration' => $registration->uid,
            ], fn ($v) => $v !== null && $v !== '');

            if ($crew) {
                $product = $require('crew');
            } elseif ($person['age_group'] === 'child') {
                $product = $require('child');
            } elseif ($person['alumnus'] && ! empty($ids['bundle']) && ! empty($ids['membership'])
                && ! in_array($person['email'], $seenEmails, true)
                && ! $this->hasMembership((int) $ids['membership'], $index === 0 ? $userId : null, $person['email'])) {
                $product = (int) $ids['bundle'];
                // Community reads these when the order is paid (the membership's approval).
                $meta['alumnus'] = true;
                $meta['approval'] = (string) $event->paid('approval');
                if ($person['email']) {
                    $seenEmails[] = $person['email'];
                }
            } else {
                $product = $require('pass');
                if ($person['alumnus']) {
                    $meta['alumnus'] = true;
                }
            }
            $items[] = ['product' => $product, 'meta' => $meta];
        }

        if ($donation > 0) {
            $items[] = ['product' => $require('donation'), 'amount' => $donation, 'meta' => ['registration' => $registration->uid]];
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
