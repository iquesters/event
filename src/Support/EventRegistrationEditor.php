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

        $fields = [
            ['id' => 'you', 'type' => 'section', 'label' => $admin ? 'The person registering' : 'Your details', 'description' => 'Correct names, mobile numbers, meals and details. Tickets, adult or child, membership and the number of guests stay as they are.'],
            ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
            ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'readonly' => true, 'helperText' => 'The email of the account (a membership is theirs).'],
            ['id' => 'phone_code', 'label' => 'Country code', 'type' => 'select', 'required' => true, 'default' => '+91', 'options' => $codes],
            ['id' => 'phone', 'label' => 'Mobile (WhatsApp)', 'type' => 'tel', 'required' => true, 'maxLength' => 20, 'placeholder' => 'e.g. 98000 00000'],
            ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => $pairs(EventRegistration::GENDERS)],
            ['id' => 'meal', 'label' => 'Meal', 'type' => 'radio', 'required' => true, 'options' => $pairs(EventRegistration::MEALS)],
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
                    ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => $pairs(EventRegistration::GENDERS)],
                    ['id' => 'meal', 'label' => 'Meal', 'type' => 'select', 'required' => true, 'options' => $pairs(EventRegistration::MEALS)],
                    ...array_map(fn ($f) => $f + ['showWhen' => $guestMember], $memberFields),
                    // A member's email can't change (the membership is theirs); others' can.
                    ['id' => 'member_email', 'label' => 'Email', 'type' => 'email', 'readonly' => true, 'showWhen' => $guestMember],
                    ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'maxLength' => 255, 'showWhen' => ['field' => 'member', 'equals' => false]],
                    ['id' => 'phone_code', 'label' => 'Country code', 'type' => 'select', 'default' => '+91', 'options' => $codes, 'showWhen' => ['field' => 'age_group', 'equals' => 'adult']],
                    ['id' => 'phone', 'label' => 'Mobile', 'type' => 'tel', 'required' => true, 'maxLength' => 20, 'placeholder' => 'e.g. 98000 00000', 'showWhen' => ['field' => 'age_group', 'equals' => 'adult']],
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
            'email' => $you['email'] ?? $registration->email ?? $registration->user?->email ?? '',
            'member' => (bool) ($you['member'] ?? false),
            'guests' => array_map(fn (array $p) => $values($p) + [
                'age_group' => ($p['age_group'] ?? 'adult') === 'child' ? 'child' : 'adult',
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
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'phone_code' => ['nullable', Rule::in($codes)],
            'phone' => ['required', ...$phone],
            'gender' => ['required', Rule::in(array_keys(EventRegistration::GENDERS))],
            'meal' => ['required', Rule::in(array_keys(EventRegistration::MEALS))],
            'guests.*.name' => ['required', 'string', 'max:255'],
            'guests.*.gender' => ['required', Rule::in(array_keys(EventRegistration::GENDERS))],
            'guests.*.meal' => ['required', Rule::in(array_keys(EventRegistration::MEALS))],
            'guests.*.email' => ['nullable', 'email', 'max:255'],
            'guests.*.phone_code' => ['nullable', Rule::in($codes)],
            'guests.*.phone' => ['nullable', ...$phone],
        ], [
            'phone.regex' => 'Enter the mobile number (digits and spaces), without the country code.',
            'guests.*.phone.regex' => 'Enter the mobile number (digits and spaces), without the country code.',
        ])->validate();

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
            $new['meal'] = (string) $p['meal'];
            $number = trim((string) ($p['phone'] ?? ''));
            if ($adult && $i > 0 && $number === '' && ! $admin) {
                $errors[$at.'phone'] = 'Enter this guest\'s mobile number.';
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
        if ($changes === []) {
            return $registration;
        }

        DB::transaction(function () use ($registration, $updated, $people, $changes, $by, $admin) {
            $you = $updated[0];
            $registration->update([
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
                                'meal' => EventRegistration::MEALS[$new['meal']] ?? $new['meal'],
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

        return $registration->fresh();
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
        $labels = ['name' => 'name', 'phone' => 'mobile', 'gender' => 'gender', 'meal' => 'meal', 'email' => 'email'];
        $show = fn (string $key, $value) => match ($key) {
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
