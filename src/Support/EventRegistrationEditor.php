<?php

namespace Iquesters\Event\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Iquesters\Event\Contracts\MembershipDetails;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;

/**
 * Correcting a paid registration's details: names, mobiles, gender, meals and
 * a member's details (e.g. their batch). What decides the price or who owns a
 * membership stays as it is: adult or child, membership, tickets, the number
 * of guests, and a member's email. The person registering may correct theirs
 * until registration closes; the event's admins at any time. Each change is
 * kept in the registration's history; an unpaid order's lines follow.
 */
class EventRegistrationEditor
{
    /** The form people use on the event page ({uid} = the event): event-registration-edit-{uid}. */
    public static function slug(Event $event): string
    {
        return 'event-registration-edit-'.strtolower($event->uid);
    }

    /** The admins' form ({uid} = the registration): event-registration-admin-edit-{uid}. */
    public static function adminSlug(Event $event): string
    {
        return 'event-registration-admin-edit-'.strtolower($event->uid);
    }

    /** May this person correct the registration? Admins: while it isn't cancelled. The registrant: their own, until registration closes. */
    public static function mayEdit(?Authenticatable $user, EventRegistration $registration, bool $admin = false): bool
    {
        if (! $registration->isActive() || ! is_array($registration->people) || $registration->people === []) {
            return false;
        }
        if ($admin) {
            return true;
        }

        return $user && (int) $registration->user_id === (int) $user->getAuthIdentifier()
            && $registration->event && ! $registration->event->registrationClosedReason();
    }

    /** The form (form schema) for an event. */
    public static function form(Event $event, bool $admin): array
    {
        $details = app()->bound(MembershipDetails::class) ? app(MembershipDetails::class) : null;
        $memberFields = $details ? $details->fields($event) : [];
        $member = ['field' => 'member', 'equals' => true];
        $guestMember = ['field' => 'member', 'equals' => true];
        $pairs = fn (array $map) => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys($map), $map);
        $codes = $pairs(EventRegistration::PHONE_CODES);
        $age = (int) $event->paid('child_age');
        // Meals are for those coming in person: none online; "In person and online": only while In person.
        $hybrid = $event->isHybrid();
        $mealFor = fn (array $f) => $event->isOnline() ? [] : [$hybrid ? $f + ['showWhen' => ['field' => 'attend_mode', 'equals' => EventRegistration::ATTEND_IN_PERSON]] : $f];

        $fields = [
            // In person and online: switch how everyone attends (when their tickets allow it).
            ...($hybrid ? [
                ['id' => 'attending', 'type' => 'section', 'label' => 'How '.($admin ? 'they' : 'you').' will attend', 'description' => 'You, and each guest below, can switch how you attend when the tickets are for both ways (the price stays the same).'],
                ['id' => 'attend_mode', 'label' => 'Attending', 'type' => 'radio', 'required' => true, 'options' => $pairs(EventRegistration::ATTEND_MODES)],
            ] : []),
            ['id' => 'you', 'type' => 'section', 'label' => $admin ? 'The person registering' : 'Your details', 'description' => 'Correct names, mobile numbers, meals and details. Tickets, adult or child, membership and the number of guests stay as they are.'],
            ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
            ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'readonly' => true, 'helperText' => 'The email of the account (a membership is theirs).'],
            ['id' => 'phone_code', 'label' => 'Country code', 'type' => 'select', 'required' => true, 'default' => '+91', 'options' => $codes],
            ['id' => 'phone', 'label' => 'Mobile (WhatsApp)', 'type' => 'tel', 'required' => true, 'maxLength' => 20, 'placeholder' => 'e.g. 98000 00000'],
            ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => $pairs(EventRegistration::GENDERS)],
            ...$mealFor(['id' => 'meal', 'label' => 'Meal', 'type' => 'radio', 'required' => true, 'options' => $pairs(EventRegistration::MEALS)]),
            // A member's details (e.g. what they completed at the school), when they are one.
            ...array_map(fn ($f) => $f + ['showWhen' => $member], $memberFields),
            ['id' => 'people', 'type' => 'section', 'label' => 'Guests', 'description' => 'To add or remove a guest, or change who is an adult, a child or a member, '.($admin ? 'cancel and add the registration again (if not paid), or use Add attendee.' : 'please contact the organisers.')],
            [
                'id' => 'guests',
                'type' => 'repeater',
                'rowLabel' => 'Guest',
                // The same guests: none added or removed here.
                'fixedRows' => true,
                'fields' => [
                    ['id' => 'age_group', 'label' => 'Adult or child', 'type' => 'select', 'readonly' => true, 'options' => [['value' => 'adult', 'label' => 'Adult (over '.$age.')'], ['value' => 'child', 'label' => 'Child ('.$age.' or under)']]],
                    ['id' => 'member', 'label' => 'Joining as a member?', 'type' => 'checkbox', 'readonly' => true],
                    ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
                    ['id' => 'relation', 'label' => $admin ? 'Relation to the registrant' : 'Relation to you', 'type' => 'select', 'required' => true, 'options' => $pairs(EventRegistration::RELATIONS)],
                    // In person and online: each guest chooses; their meal shows after "In person" (this card's own field).
                    ...($hybrid ? [['id' => 'attend_mode', 'label' => 'Attending', 'type' => 'select', 'required' => true, 'default' => EventRegistration::ATTEND_IN_PERSON, 'options' => $pairs(EventRegistration::ATTEND_MODES)]] : []),
                    ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => $pairs(EventRegistration::GENDERS)],
                    ...$mealFor(['id' => 'meal', 'label' => 'Meal', 'type' => 'select', 'required' => true, 'options' => $pairs(EventRegistration::MEALS)]),
                    ...array_map(fn ($f) => $f + ['showWhen' => $guestMember], $memberFields),
                    // A member's email can't change (the membership is theirs); others' can.
                    ['id' => 'member_email', 'label' => 'Email', 'type' => 'email', 'readonly' => true, 'showWhen' => $guestMember],
                    ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'maxLength' => 255, 'showWhen' => ['field' => 'member', 'equals' => false]],
                    ['id' => 'phone_code', 'label' => 'Country code', 'type' => 'select', 'default' => '+91', 'options' => $codes, 'showWhen' => ['field' => 'age_group', 'equals' => 'adult']],
                    // Optional; a member's is needed.
                    ['id' => 'phone', 'label' => 'Mobile', 'type' => 'tel', 'maxLength' => 20, 'placeholder' => 'e.g. 98000 00000', 'showWhen' => ['field' => 'age_group', 'equals' => 'adult']]
                        + ($admin ? [] : ['requiredWhen' => $guestMember]),
                ],
            ],
        ];

        return [
            'endpoint' => $admin ? '/events/manage/registrations/{uid}' : '/events/{uid}/registration',
            'method' => 'PUT',
            'submitButtonLabel' => 'Save changes',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'defaultFieldSize' => 'auto',
            'disableSnackbar' => true,
            'fields' => $fields,
        ];
    }

    /** The registration's values for the form. */
    public static function formData(EventRegistration $registration): array
    {
        $people = array_values((array) $registration->people);
        $values = fn (array $p) => ['name' => $p['name'] ?? '', 'gender' => $p['gender'] ?? '', 'meal' => $p['meal'] ?? '']
            + self::splitPhone($p['phone'] ?? null)
            + (array) ($p['member_input'] ?? []);
        $you = $people[0] ?? [];
        // The registrant's details when they weren't kept (registered before they were): from their member record.
        $prefill = [];
        if (! empty($you['member']) && empty($you['member_input']) && $registration->user && $registration->event && app()->bound(MembershipDetails::class)) {
            $prefill = array_diff_key(app(MembershipDetails::class)->prefill($registration->event, $registration->user), ['member' => 1]);
        }

        return $values($you) + $prefill + [
            'attend_mode' => $registration->event ? $registration->event->attendModeFor($registration->attend_mode) : EventRegistration::ATTEND_IN_PERSON,
            'email' => $you['email'] ?? $registration->email ?? $registration->user?->email ?? '',
            'member' => (bool) ($you['member'] ?? false),
            'guests' => array_map(fn (array $p) => $values($p) + [
                'age_group' => ($p['age_group'] ?? 'adult') === 'child' ? 'child' : 'adult',
                'relation' => $p['relation'] ?? '',
                'attend_mode' => $registration->attendModeOf($p),
                'member' => (bool) ($p['member'] ?? false),
                'email' => $p['email'] ?? '',
                'member_email' => $p['email'] ?? '',
            ], array_slice($people, 1)),
        ];
    }

    /**
     * Save the corrections. Only the details: the same people, each with their
     * ticket, age group and membership; a member's email kept.
     *
     * @throws ValidationException
     */
    public function update(EventRegistration $registration, array $input, ?Authenticatable $by, bool $admin): EventRegistration
    {
        $event = $registration->event;
        $people = array_values((array) $registration->people);
        $guests = array_values(array_filter((array) ($input['guests'] ?? []), 'is_array'));
        if (count($guests) !== count($people) - 1) {
            throw ValidationException::withMessages(['guests' => 'The guests can\'t be added or removed here.']);
        }

        $codes = array_keys(EventRegistration::PHONE_CODES);
        $phone = ['string', 'max:20', 'regex:/^[0-9 ()-]{5,20}$/'];
        // How each person attends: switched on an "In person and online" event (the registrant at
        // the top, each guest on their card), else as it is.
        $registration->setRelation('event', $event);
        $oldModes = $newModes = [];
        foreach ($people as $i => $old) {
            $oldModes[$i] = $registration->attendModeOf($old);
            $chosen = $i === 0 ? ($input['attend_mode'] ?? null) : ($guests[$i - 1]['attend_mode'] ?? null);
            $newModes[$i] = $event->isHybrid() ? $event->attendModeFor($chosen ?? $oldModes[$i]) : $oldModes[$i];
        }
        $mode = $newModes[0];
        // Meals: required for those coming in person (checked per person below).
        $meal = ['nullable', Rule::in(array_keys(EventRegistration::MEALS))];
        Validator::make($input, [
            'attend_mode' => ['nullable', Rule::in(array_keys(EventRegistration::ATTEND_MODES))],
            'guests.*.attend_mode' => ['nullable', Rule::in(array_keys(EventRegistration::ATTEND_MODES))],
            'name' => ['required', 'string', 'max:255'],
            'phone_code' => ['nullable', Rule::in($codes)],
            'phone' => ['required', ...$phone],
            'gender' => ['required', Rule::in(array_keys(EventRegistration::GENDERS))],
            'meal' => $meal,
            'guests.*.name' => ['required', 'string', 'max:255'],
            'guests.*.relation' => ['required', Rule::in(array_keys(EventRegistration::RELATIONS))],
            'guests.*.gender' => ['required', Rule::in(array_keys(EventRegistration::GENDERS))],
            'guests.*.meal' => $meal,
            'guests.*.email' => ['nullable', 'email', 'max:255'],
            'guests.*.phone_code' => ['nullable', Rule::in($codes)],
            'guests.*.phone' => ['nullable', ...$phone],
        ], [
            'phone.regex' => 'Enter the mobile number (digits and spaces), without the country code.',
            'guests.*.phone.regex' => 'Enter the mobile number (digits and spaces), without the country code.',
            'guests.*.relation.required' => 'Choose how this guest is related.',
            'guests.*.relation.in' => 'Choose how this guest is related.',
            'meal.required' => 'Choose the meal.',
            'guests.*.meal.required' => 'Choose the meal.',
        ])->validate();

        // Switching how someone attends: only when their ticket is for both ways (the price stays the same),
        // and those newly coming in person need places left.
        $tickets = EventProducts::tickets($event, true);
        $newlyHere = 0;
        foreach ($people as $i => $p) {
            if ($newModes[$i] === $oldModes[$i]) {
                continue;
            }
            $field = $i === 0 ? 'attend_mode' : 'guests.'.($i - 1).'.attend_mode';
            $ticket = collect($tickets)->first(fn ($t) => (int) $t['product']->id === (int) ($p['ticket_product_id'] ?? 0))
                ?? collect($tickets)->first(fn ($t) => $t['name'] === ($p['ticket'] ?? null));
            if ($ticket && ! EventProducts::allows($ticket, $newModes[$i])) {
                throw ValidationException::withMessages([$field => ($p['name'] ?? 'Someone').EventWords::t("'s ticket (").$ticket['name'].') is for '.strtolower(EventProducts::ATTEND_LABELS[$ticket['attend']]).' only: the price would change. '.($admin ? 'Cancel and add the registration again.' : 'Please contact the organisers.')]);
            }
            $newlyHere += $newModes[$i] === EventRegistration::ATTEND_IN_PERSON ? 1 : 0;
        }
        $left = $newlyHere > 0 ? $event->seatsLeft() : null;
        if (! $admin && $left !== null && $left < $newlyHere) {
            throw ValidationException::withMessages(['attend_mode' => $left === 0 ? 'No places are left in person.' : "Only {$left} ".($left === 1 ? 'place is' : 'places are').' left in person.']);
        }

        $details = app()->bound(MembershipDetails::class) ? app(MembershipDetails::class) : null;
        $fieldIds = $details ? array_column($details->fields($event), 'id') : [];
        $errors = [];
        $changes = [];
        $updated = [];
        foreach ($people as $i => $old) {
            $p = $i === 0 ? $input : $guests[$i - 1];
            $at = $i === 0 ? '' : 'guests.'.($i - 1).'.';
            $who = $i === 0 ? ($admin ? 'Registrant' : 'You') : 'Guest '.$i;
            $adult = ($old['age_group'] ?? 'adult') !== 'child';
            $new = $old;
            $new['name'] = trim((string) $p['name']);
            $new['gender'] = (string) $p['gender'];
            // Meals are for those coming in person.
            $inPerson = $newModes[$i] === EventRegistration::ATTEND_IN_PERSON;
            if ($inPerson && trim((string) ($p['meal'] ?? '')) === '') {
                $errors[$at.'meal'] = 'Choose the meal.';
            }
            $new['meal'] = $inPerson ? (string) ($p['meal'] ?? '') : null;
            if ($event->isHybrid()) {
                $new['attend_mode'] = $newModes[$i];
            }
            if ($i > 0) {
                $new['relation'] = (string) $p['relation'];
            }
            $number = trim((string) ($p['phone'] ?? ''));
            // A guest's mobile is optional; a member's is needed.
            if ($adult && $i > 0 && $number === '' && ! $admin && ! empty($old['member'])) {
                $errors[$at.'phone'] = 'A member needs their mobile number.';
            }
            $new['phone'] = $number !== '' ? trim(($p['phone_code'] ?? '+91').' '.$number) : ($adult ? ($old['phone'] ?? null) : null);
            // A member's email stays (the membership is theirs); the registrant's is their account's.
            if ($i > 0 && empty($old['member'])) {
                $email = trim((string) ($p['email'] ?? ''));
                $new['email'] = $email !== '' ? mb_strtolower($email) : null;
            }
            // A member's details, checked by the membership module.
            if (! empty($old['member']) && $details) {
                $entered = array_intersect_key($p, array_flip($fieldIds));
                [$kept, $fieldErrors] = $details->validate($event, $entered);
                foreach ($fieldErrors as $field => $message) {
                    $errors[$at.$field] = $message;
                }
                if (! $fieldErrors) {
                    $new['member_details'] = $kept;
                    $new['member_input'] = $entered;
                }
            }
            $changes = [...$changes, ...self::differences($who, $old, $new)];
            $updated[$i] = $new;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        foreach (array_reverse(array_keys($people)) as $i) {
            if ($newModes[$i] !== $oldModes[$i]) {
                $who = $i === 0 ? ($admin ? 'Registrant' : 'You') : 'Guest '.$i;
                array_unshift($changes, "{$who} attending: ".EventRegistration::ATTEND_MODES[$oldModes[$i]].' → '.EventRegistration::ATTEND_MODES[$newModes[$i]]);
            }
        }
        if ($changes === []) {
            return $registration;
        }

        $onlineBefore = $registration->onlineCount();
        DB::transaction(function () use ($registration, $updated, $people, $changes, $by, $admin, $event, $mode, $newModes) {
            $you = $updated[0];
            $registration->update([
                'attend_mode' => $event->isHybrid() ? $mode : $registration->attend_mode,
                'online_people' => $event->isHybrid() ? count(array_filter($newModes, fn ($m) => $m === EventRegistration::ATTEND_ONLINE)) : $registration->online_people,
                'name' => $you['name'],
                'phone' => $you['phone'],
                'gender' => $you['gender'],
                'meal' => $you['meal'],
                'people' => $updated,
                'veg' => count(array_filter($updated, fn ($p) => ($p['meal'] ?? null) === 'veg')),
                'non_veg' => count(array_filter($updated, fn ($p) => ($p['meal'] ?? null) === 'non_veg')),
                'edit_history' => [...(array) $registration->edit_history, [
                    'at' => now()->toIso8601String(),
                    'by' => $by?->getAuthIdentifier(),
                    'by_name' => $by->name ?? null,
                    'admin' => $admin,
                    'changes' => $changes,
                ]],
            ]);
            // An order not paid yet: its lines follow (e.g. the membership is made from them when paid).
            $order = $registration->order;
            if ($order && ! $order->isPaid()) {
                foreach ($order->lines as $line) {
                    $meta = (array) $line->meta;
                    if (($meta['registration'] ?? null) !== $registration->uid) {
                        continue;
                    }
                    foreach ($people as $i => $old) {
                        if (($meta['person'] ?? null) === ($old['name'] ?? null) && ($meta['role'] ?? null) === ($old['role'] ?? null)) {
                            $new = $updated[$i];
                            $meta = array_filter([
                                ...$meta,
                                'person' => $new['name'],
                                'gender' => EventRegistration::GENDERS[$new['gender']] ?? $new['gender'],
                                'meal' => $new['meal'] ? (EventRegistration::MEALS[$new['meal']] ?? $new['meal']) : null,
                                'attend' => $event->isHybrid() ? EventRegistration::ATTEND_MODES[$newModes[$i]] : ($meta['attend'] ?? null),
                                'relation' => EventRegistration::RELATIONS[$new['relation'] ?? ''] ?? null,
                                'phone' => $new['phone'],
                                'email' => $new['email'] ?? null,
                                'member_details' => isset($meta['member_details']) && ! empty($new['member_details']) ? $new['member_details'] : ($meta['member_details'] ?? null),
                            ], fn ($v) => $v !== null && $v !== '');
                            $line->forceFill(['meta' => $meta])->save();
                            break;
                        }
                    }
                }
            }
        });

        $fresh = $registration->fresh();
        // More of them joining online while confirmed: the link by email.
        if ($fresh->status === EventRegistration::STATUS_REGISTERED && $fresh->setRelation('event', $event)->onlineCount() > $onlineBefore) {
            EventNotifications::joinOnline($fresh);
        }

        return $fresh;
    }

    /** "+91 98000 00001" → {phone_code: "+91", phone: "98000 00001"}. */
    public static function splitPhone(?string $phone): array
    {
        $phone = trim((string) $phone);
        foreach (array_keys(EventRegistration::PHONE_CODES) as $code) {
            if (Str::startsWith($phone, $code.' ')) {
                return ['phone_code' => $code, 'phone' => trim(substr($phone, strlen($code)))];
            }
        }

        return ['phone_code' => '+91', 'phone' => $phone];
    }

    /** What changed, in words: "Guest 1 meal: Veg → Non-veg". */
    private static function differences(string $who, array $old, array $new): array
    {
        $labels = ['name' => 'name', 'relation' => 'relation', 'phone' => 'mobile', 'gender' => 'gender', 'meal' => 'meal', 'email' => 'email'];
        $show = fn (string $key, $value) => match ($key) {
            'relation' => EventRegistration::RELATIONS[$value] ?? $value,
            'gender' => EventRegistration::GENDERS[$value] ?? $value,
            'meal' => EventRegistration::MEALS[$value] ?? $value,
            default => $value,
        };
        $out = [];
        foreach ($labels as $key => $label) {
            $before = (string) ($old[$key] ?? '');
            $after = (string) ($new[$key] ?? '');
            if ($before !== $after) {
                $out[] = "{$who} {$label}: ".($before === '' ? '—' : $show($key, $before)).' → '.($after === '' ? '—' : $show($key, $after));
            }
        }
        $summary = fn (array $p) => (string) ($p['member_details']['summary'] ?? '');
        if ($summary($old) !== $summary($new)) {
            $out[] = "{$who} details: ".($summary($old) ?: '—').' → '.($summary($new) ?: '—');
        }

        return $out;
    }
}
