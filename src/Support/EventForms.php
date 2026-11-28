<?php

namespace Iquesters\Event\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Iquesters\Event\Contracts\MembershipDetails;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventFeedbackQuestion;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Organisation\Support\OrganisationPolicies;

/**
 * The event module's form schemas (user-interface form engine). The module owns
 * them: event:seed rebuilds them; an event's feedback form is rebuilt whenever
 * its questions change.
 */
class EventForms
{
    public const DETAILS = 'event-details';

    public const REGISTRATION = 'event-registration';

    public const CANCEL_REGISTRATION = 'event-registration-cancel';

    public const QUESTIONS = 'event-feedback-questions';

    public const QUESTIONS_PRESET = 'event-feedback-preset';


    public const ADMIN_CANCEL_REGISTRATION = 'event-registration-admin-cancel';

    /** The paid registration form of one event: event-paid-registration-{uid}. */
    public static function paidRegistrationSlug(Event $event): string
    {
        return 'event-paid-registration-'.strtolower($event->uid);
    }

    /** Admins add an attendee (crew, invited guests, people paying at the desk): event-add-attendee-{uid}. */
    public static function addAttendeeSlug(Event $event): string
    {
        return 'event-add-attendee-'.strtolower($event->uid);
    }

    /** The attendee feedback form of one event: event-feedback-{uid}. */
    public static function feedbackSlug(Event $event): string
    {
        return 'event-feedback-'.strtolower($event->uid);
    }

    public static function syncAll(): void
    {
        self::upsert(self::DETAILS, 'Event', self::details());
        self::upsert(self::REGISTRATION, 'Event registration', self::registration());
        self::upsert(self::CANCEL_REGISTRATION, 'Cancel event registration', self::cancelRegistration());
        self::upsert(self::QUESTIONS, 'Event feedback questions', self::questions());
        self::upsert(self::QUESTIONS_PRESET, 'Event feedback preset', self::questionsPreset());
        self::upsert(self::ADMIN_CANCEL_REGISTRATION, 'Cancel a registration (admin)', [
            'endpoint' => '/events/manage/registrations/{uid}/cancel',
            'method' => 'POST',
            'submitButtonLabel' => 'Cancel',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'disableSnackbar' => true,
            'fields' => [],
        ]);
        // Each paid event's own forms (their prices and links).
        if (Schema::hasTable('events')) {
            Event::query()->where('is_paid', true)->get()->each(fn (Event $event) => self::syncPaid($event));
        }
    }

    public static function details(): array
    {
        // The paid fields show only when "Registration is paid" is ticked.
        $paid = ['field' => 'is_paid', 'equals' => true];
        $statuses = [['value' => 'draft', 'label' => 'Draft (only admins see it)'], ['value' => 'published', 'label' => 'Published'], ['value' => 'cancelled', 'label' => 'Cancelled']];

        return [
            'defaultFieldSize' => 'auto',
            'submitButtonLabel' => 'Save',
            'modes' => [
                'create' => ['endpoint' => '/events/manage', 'method' => 'POST'],
                'edit' => ['endpoint' => '/events/manage/{uid}', 'method' => 'PUT', 'allowDelete' => false],
                // Events are cancelled (status), not deleted: registrations point at them.
                'view' => ['allowDelete' => false],
            ],
            'fields' => [
                ['id' => 'basics', 'type' => 'section', 'label' => 'Event', 'description' => 'The basics people see first.'],
                ['id' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'maxLength' => 255],
                ['id' => 'organisation_id', 'label' => 'Organisation', 'type' => 'select', 'required' => true, 'optionsFrom' => ['url' => '/events/manage/options/organisations']],
                ['id' => 'type', 'label' => 'Type', 'type' => 'select', 'optionsFrom' => ['url' => '/events/manage/options/types']],
                ['id' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => $statuses],
                ['id' => 'visibility', 'label' => 'Who can see it', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'members', 'label' => 'Members'],
                    ['value' => 'public', 'label' => 'Everyone (public page)'],
                ]],
                ['id' => 'when', 'type' => 'section', 'label' => 'When and where', 'description' => 'Date and time, and where: in person or online.'],
                ['id' => 'starts_at', 'label' => 'Starts', 'type' => 'datetime-local', 'required' => true],
                ['id' => 'ends_at', 'label' => 'Ends', 'type' => 'datetime-local'],
                ['id' => 'location_type', 'label' => 'Where', 'type' => 'radio', 'required' => true, 'default' => 'in_person', 'options' => [
                    ['value' => 'in_person', 'label' => 'In person'],
                    ['value' => 'online', 'label' => 'Online'],
                    ['value' => 'hybrid', 'label' => 'In person and online (each person chooses)'],
                ]],
                // In person: the venue and address; online: the link; both: all of them.
                ['id' => 'venue', 'showWhen' => ['field' => 'location_type', 'equals' => ['in_person', 'hybrid']], 'label' => 'Venue', 'type' => 'text', 'maxLength' => 255, 'placeholder' => 'e.g. School auditorium'],
                ['id' => 'address', 'showWhen' => ['field' => 'location_type', 'equals' => ['in_person', 'hybrid']], 'label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'maxLength' => 1000],
                ['id' => 'maps_url', 'showWhen' => ['field' => 'location_type', 'equals' => ['in_person', 'hybrid']], 'label' => 'Google Maps link', 'type' => 'url', 'maxLength' => 500, 'placeholder' => 'https://maps.app.goo.gl/…', 'helperText' => 'Optional: in Google Maps, Share → Copy link. The event page then shows the map and "Open in Maps".'],
                ['id' => 'online_url', 'showWhen' => ['field' => 'location_type', 'equals' => ['online', 'hybrid']], 'label' => 'Online link', 'type' => 'url', 'maxLength' => 255, 'placeholder' => 'https://', 'helperText' => 'Sent only to people joining online once they are registered (and shown to them on the event page); never on the public page.'],
                ['id' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 5, 'maxLength' => 10000],
                ['id' => 'look', 'type' => 'section', 'label' => 'Page look', 'description' => 'How the event page looks to people.'],
                ['id' => 'cover_image', 'label' => 'Cover image', 'type' => 'file', 'accept' => 'image/jpeg,image/png,image/webp', 'helperText' => 'Square works best (e.g. 1000 × 1000). JPG, PNG or WEBP, up to 5 MB. Without one, a cover is made from the title in the colour below.'],
                ['id' => 'remove_cover', 'showWhen' => ['field' => 'has_cover', 'equals' => true], 'label' => 'Remove the current cover image', 'type' => 'checkbox'],
                ['id' => 'theme', 'label' => 'Colour', 'type' => 'color', 'default' => Event::DEFAULT_COLOR, 'helperText' => 'Any colour: the page, its cover and buttons use it.'],
                ['id' => 'registration', 'type' => 'section', 'label' => 'Registration', 'description' => 'Who can come, and until when.'],
                ['id' => 'registration_open', 'label' => 'Registration open', 'type' => 'checkbox'],
                ['id' => 'limit_places', 'label' => 'Limit the number of places', 'type' => 'checkbox'],
                ['id' => 'capacity', 'showWhen' => ['field' => 'limit_places', 'equals' => true], 'label' => 'How many places', 'type' => 'number', 'min' => 1, 'helperText' => 'Everyone counts: the person registering and each guest. In person and online: only those coming in person.'],
                ['id' => 'registration_closes_at', 'label' => 'Register by', 'type' => 'datetime-local'],
                ['id' => 'paid', 'type' => 'section', 'label' => 'Tickets', 'description' => 'A free event needs nothing here. For a paid one, tick the box and add each ticket with its price.'],
                ['id' => 'is_paid', 'label' => 'Registration is paid', 'type' => 'checkbox'],
                [
                    'id' => 'tickets',
                    'showWhen' => $paid,
                    'label' => 'Tickets',
                    'type' => 'repeater',
                    'addLabel' => 'Add ticket',
                    'helperText' => 'Each person registering picks one, e.g. Pass ₹600, Child ₹0, Crew ₹0 (admins only). Combinations (e.g. the pass with a membership) are bundles made in Commerce → Products: they are offered here automatically.',
                    'fields' => [
                        ['id' => 'product_id', 'type' => 'hidden'],
                        ['id' => 'name', 'label' => 'Ticket name', 'type' => 'text', 'required' => true, 'maxLength' => 120, 'placeholder' => 'e.g. Pass'],
                        ['id' => 'price', 'label' => 'Price, ₹', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.01', 'placeholder' => '0 for free'],
                        ['id' => 'limit', 'label' => 'How many can be sold', 'type' => 'number', 'min' => 1, 'placeholder' => 'No limit'],
                        ['id' => 'includes', 'label' => "What's included", 'type' => 'text', 'maxLength' => 255, 'placeholder' => 'e.g. Lunch, T-shirt'],
                        ['id' => 'for', 'label' => "Who it's for", 'type' => 'select', 'required' => true, 'options' => [
                            ['value' => 'adults', 'label' => 'Adults'],
                            ['value' => 'children', 'label' => 'Children (up to the age below)'],
                            ['value' => 'everyone', 'label' => 'Everyone'],
                        ]],
                        // Only for "In person and online" events: tickets for one way of attending (e.g. a cheaper online pass).
                        ['id' => 'attend', 'label' => 'For (in person and online events)', 'type' => 'select', 'default' => 'both', 'options' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(EventProducts::ATTEND_LABELS), EventProducts::ATTEND_LABELS)],
                        ['id' => 'admin_only', 'label' => 'Only admins can give it (crew, invited guests)', 'type' => 'checkbox'],
                    ],
                ],
                ['id' => 'with_membership', 'showWhen' => $paid, 'label' => 'Registration includes membership of the organisation', 'type' => 'checkbox', 'helperText' => "People registering join: their ticket includes the membership (a bundle in Commerce with a ticket and a membership), and they're asked the membership details (e.g. what they completed at the school and when). Guests may join too."],
                ['id' => 'ask_donation', 'showWhen' => $paid, 'label' => 'Ask for a donation', 'type' => 'checkbox', 'helperText' => "An optional amount, paid together with the tickets. It uses the organisation's donation product in Commerce; saving makes one if there is none."],
                ['id' => 'child_age', 'showWhen' => $paid, 'label' => 'Children are up to age (included)', 'type' => 'number', 'min' => 0, 'max' => 17, 'default' => 5, 'helperText' => 'Someone registering a child picks "Child": they get the ticket for children (e.g. free).'],
                ['id' => 'feedback', 'type' => 'section', 'label' => 'Feedback and ratings', 'description' => 'Ask attendees how it went, once the event starts.'],
                ['id' => 'feedback_enabled', 'label' => 'Ask attendees for feedback', 'type' => 'checkbox', 'helperText' => 'Opens when the event starts; questions start from the event type\'s set.'],
                ['id' => 'feedback_anonymous', 'label' => 'Attendees may stay anonymous', 'type' => 'checkbox'],
            ],
        ];
    }

    public static function registration(): array
    {
        return [
            'endpoint' => '/events/{uid}/register',
            'method' => 'POST',
            'submitButtonLabel' => 'Register',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'defaultFieldSize' => 12,
            'disableSnackbar' => true,
            'fields' => [
                ['id' => 'guests', 'label' => 'Guests coming with you', 'type' => 'number', 'min' => 0, 'max' => 10],
                ['id' => 'note', 'label' => 'Note', 'type' => 'text', 'maxLength' => 500, 'placeholder' => 'e.g. dietary needs'],
            ],
        ];
    }

    /** A paid event's registration and add-attendee forms (rebuilt when the event is saved). */
    public static function syncPaid(Event $event): void
    {
        if (! $event->is_paid) {
            return;
        }
        self::upsert(self::paidRegistrationSlug($event), 'Registration: '.Str::limit($event->title, 80), self::paidRegistration($event, false));
        self::upsert(self::addAttendeeSlug($event), 'Add attendee: '.Str::limit($event->title, 80), self::paidRegistration($event, true));
        // Correcting a registration's details: the registrant's form and the admins'.
        self::upsert(EventRegistrationEditor::slug($event), 'Edit registration: '.Str::limit($event->title, 80), EventRegistrationEditor::form($event, false));
        self::upsert(EventRegistrationEditor::adminSlug($event), 'Edit registration (admin): '.Str::limit($event->title, 80), EventRegistrationEditor::form($event, true));
    }

    public static function paidRegistration(Event $event, bool $admin): array
    {
        $organisation = $event->organisation?->name ?? 'the organisation';
        $pairs = fn (array $map) => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys($map), $map);
        $genders = $pairs(EventRegistration::GENDERS);
        $meals = $pairs(EventRegistration::MEALS);
        $codes = $pairs(EventRegistration::PHONE_CODES);
        $age = (int) $event->paid('child_age');
        $ages = [['value' => 'adult', 'label' => 'Adult (over '.$age.')'], ['value' => 'child', 'label' => 'Child ('.$age.' or under)']];

        // Membership details (e.g. community's alumni: what they completed at the school).
        $details = app()->bound(MembershipDetails::class) ? app(MembershipDetails::class) : null;
        $memberLabel = $details ? $details->memberLabel($event) : 'Member';
        // Only an event that sells a membership asks about it: the person registering joins
        // (their details asked directly); a guest may be one too (asked per guest).
        $sellsMembership = EventProducts::sellsMembership($event);
        $memberFields = $details && $sellsMembership ? $details->fields($event) : [];
        $member = ['field' => 'member', 'equals' => true];
        $withMember = fn (array $fields) => array_map(fn ($f) => $f + ['showWhen' => $member], $fields);

        // A ticket to choose only when there is a choice (else it's picked from age and membership).
        $hybrid = $event->isHybrid();
        $label = fn ($t) => $t['name'].' · '.((float) $t['product']->price > 0 ? '₹'.rtrim(rtrim(number_format((float) $t['product']->price, 2), '0'), '.') : 'Free')
            // In person and online: a ticket for one way only says so.
            .($hybrid && ($t['attend'] ?? 'both') !== 'both' ? ' · '.strtolower(EventProducts::ATTEND_LABELS[$t['attend']]) : '')
            .(! empty($t['admin_only']) ? ' · admins only' : '');
        // A Ticket list only when a person really has a choice (e.g. Early bird or Standard); admins always.
        // In person and online: a choice within the tickets for one way of attending.
        $choice = $admin;
        foreach ($hybrid ? array_keys(EventRegistration::ATTEND_MODES) : [null] as $mode) {
            $choice = $choice || count(EventProducts::ticketsFor($event, 'adult', $admin, $mode)) > 1 || count(EventProducts::ticketsFor($event, 'child', $admin, $mode)) > 1;
        }
        // Meals are for those coming in person: none online; "In person and online": only after In person.
        $inPerson = ['field' => 'attend_mode', 'equals' => EventRegistration::ATTEND_IN_PERSON];
        $mealFor = fn (array $f) => $event->isOnline() ? [] : [$hybrid ? $f + ['showWhen' => $inPerson] : $f];
        $mine = $admin ? null : EventProducts::registrantTicket($event);
        $mineText = $mine
            ? 'Your ticket: '.$label($mine).($mine['bundle'] && $sellsMembership ? ' (the membership is included; if you already paid it, you get the plain ticket)' : '').'.'
            : null;
        $ticketOptions = array_map(fn ($t) => ['value' => (string) $t['product']->id, 'label' => $label($t)], EventProducts::tickets($event, $admin));
        $ticket = ['id' => 'ticket', 'label' => 'Ticket', 'type' => 'select', 'options' => [['value' => '', 'label' => 'Chosen for me'], ...$ticketOptions], 'helperText' => 'Leave "Chosen for me": by age, and with membership for a '.strtolower($memberLabel).'.'];

        $fields = [
            // In person and online: how they attend, first (it decides the meals and the tickets).
            ...($hybrid ? [
                ['id' => 'attending', 'type' => 'section', 'label' => 'How '.($admin ? 'they' : 'you').' will attend', 'description' => 'Guests attend the same way. Online: the link comes by email once registered.'],
                ['id' => 'attend_mode', 'label' => 'Attending', 'type' => 'radio', 'required' => true, 'options' => $pairs(EventRegistration::ATTEND_MODES)],
            ] : []),
            ['id' => 'you', 'type' => 'section', 'label' => $admin ? 'Attendee' : 'You', 'description' => $admin ? 'The person registering (an account with this email is linked).' : trim('Your details for the organisers. '.($mineText ?? ''))],
            ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
            ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => ! $admin, 'maxLength' => 255],
            ['id' => 'phone_code', 'label' => 'Country code', 'type' => 'select', 'required' => true, 'default' => '+91', 'options' => $codes],
            ['id' => 'phone', 'label' => 'Mobile (WhatsApp)', 'type' => 'tel', 'required' => true, 'maxLength' => 20, 'placeholder' => 'e.g. 98000 00000'],
            ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => $genders],
            ...$mealFor(['id' => 'meal', 'label' => 'Meal', 'type' => 'radio', 'required' => true, 'options' => $meals]),
            ...($admin && $sellsMembership
                // Admins add anyone (crew, guests): they say whether the person is a member.
                ? [['id' => 'member', 'label' => 'Joining as a member', 'type' => 'checkbox'], ...$withMember($memberFields)]
                // The person registering joins: their details directly.
                : $memberFields),
            ...($choice ? [$ticket] : []),
            ['id' => 'people', 'type' => 'section', 'label' => 'Guests', 'description' => 'Anyone coming with you.'],
            [
                'id' => 'guests',
                'type' => 'repeater',
                // Each guest as a card: "Guest 1", "Guest 2"…
                'rowLabel' => 'Guest',
                'addLabel' => 'Add guest',
                'fields' => [
                    ...($sellsMembership ? [['id' => 'member', 'label' => 'Joining as a member?', 'type' => 'checkbox', 'showWhen' => ['field' => 'age_group', 'equals' => 'adult'], 'helperText' => 'Yes: they also become a member of '.$organisation.'; their ticket includes the membership.']] : []),
                    ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
                    ['id' => 'relation', 'label' => $admin ? 'Relation to the attendee' : 'Relation to you', 'type' => 'select', 'required' => true, 'options' => $pairs(EventRegistration::RELATIONS)],
                    // A member is an adult: "Child" only while "Joining as a member?" is No.
                    ['id' => 'age_group', 'label' => 'Adult or child', 'type' => 'select', 'required' => true, 'default' => 'adult', 'options' => $sellsMembership
                        ? [$ages[0], $ages[1] + ['showWhen' => ['field' => 'member', 'equals' => false]]]
                        : $ages],
                    ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => $genders],
                    // In person and online: shown after "In person" (the form's field, outside the card).
                    ...$mealFor(['id' => 'meal', 'label' => 'Meal', 'type' => 'select', 'required' => true, 'options' => $meals]),
                    ...$withMember($memberFields),
                    // Optional; needed for a guest joining as a member (the membership is theirs).
                    ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'maxLength' => 255] + ($sellsMembership ? ['requiredWhen' => $member] : []),
                    // An adult's mobile (a child's is yours): optional; needed for a member.
                    ['id' => 'phone_code', 'label' => 'Country code', 'type' => 'select', 'default' => '+91', 'options' => $codes, 'showWhen' => ['field' => 'age_group', 'equals' => 'adult']],
                    ['id' => 'phone', 'label' => 'Mobile', 'type' => 'tel', 'maxLength' => 20, 'placeholder' => 'e.g. 98000 00000', 'showWhen' => ['field' => 'age_group', 'equals' => 'adult']]
                        + ($sellsMembership && ! $admin ? ['requiredWhen' => $member] : []),
                    ...($choice ? [array_diff_key($ticket, ['helperText' => 1])] : []),
                ],
            ],
        ];
        // A donation: when the event asks for one (the organisation's donation product, in commerce).
        if ($donation = EventProducts::donationOffered($event)) {
            $fields[] = ['id' => 'giving', 'type' => 'section', 'label' => 'Donation', 'description' => 'Optional: any amount for '.$organisation.'.'];
            // The donation product's limits (its minimum, else ₹1; its maximum if set).
            $limits = $donation->amountLimits();
            $fields[] = ['id' => 'donation', 'label' => 'Donation, ₹', 'type' => 'number', 'min' => $limits['min'], 'step' => '1', 'placeholder' => 'No donation', 'helperText' => $donation->amountLimitsText().'; leave empty for none.']
                + ($limits['max'] !== null ? ['max' => $limits['max']] : []);
        }
        if ($admin) {
            $fields[] = ['id' => 'note', 'label' => 'Note', 'type' => 'text', 'maxLength' => 500];
        } else {
            $links = $event->organisation && class_exists(OrganisationPolicies::class) ? OrganisationPolicies::links($event->organisation) : [];
            // The total, worked out as the form is filled in (events/show.blade.php fills it).
            $fields[] = ['id' => 'summary', 'type' => 'section', 'label' => 'Total', 'description' => 'What you pay for everyone above.'];
            $fields[] = ['id' => 'agree', 'type' => 'section', 'label' => 'Agreement'];
            $fields[] = ['id' => 'accept_terms', 'label' => 'I agree to the Terms and the Privacy policy', 'type' => 'checkbox', 'required' => true, 'links' => $links];
        }

        return [
            'endpoint' => $admin ? '/events/manage/{uid}/attendees' : '/events/{uid}/pay-register',
            'method' => 'POST',
            'submitButtonLabel' => $admin ? 'Add attendee' : 'Continue to payment',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'defaultFieldSize' => 'auto',
            // The pages show the message themselves (flash).
            'disableSnackbar' => true,
            'fields' => $fields,
        ];
    }

    public static function cancelRegistration(): array
    {
        return [
            'endpoint' => '/events/{uid}/register',
            'method' => 'DELETE',
            'submitButtonLabel' => 'Cancel my registration',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'disableSnackbar' => true,
            'fields' => [],
        ];
    }

    public static function questions(): array
    {
        $types = [
            ['value' => EventFeedbackQuestion::TYPE_RATING, 'label' => 'Rating (1–5 stars)'],
            ['value' => EventFeedbackQuestion::TYPE_YES_NO, 'label' => 'Yes / no'],
            ['value' => EventFeedbackQuestion::TYPE_TEXT, 'label' => 'Text'],
        ];

        return [
            'endpoint' => '/events/manage/{uid}/feedback/questions',
            'method' => 'PUT',
            'submitButtonLabel' => 'Save questions',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'defaultFieldSize' => 12,
            'fields' => [
                [
                    'id' => 'questions',
                    'label' => 'Questions (in the order attendees see them)',
                    'type' => 'repeater',
                    'addLabel' => 'Add question',
                    'fields' => [
                        ['id' => 'title', 'label' => 'Question', 'type' => 'text', 'required' => true, 'maxLength' => 255],
                        ['id' => 'type', 'label' => 'Answer', 'type' => 'select', 'required' => true, 'options' => $types],
                        ['id' => 'required', 'label' => 'Required', 'type' => 'checkbox'],
                    ],
                ],
            ],
        ];
    }

    public static function questionsPreset(): array
    {
        return [
            'endpoint' => '/events/manage/{uid}/feedback/preset',
            'method' => 'PUT',
            'submitButtonLabel' => "Use the event type's question set",
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'fields' => [],
        ];
    }

    /** The attendee feedback form of an event, from its questions (rebuilt when they change). */
    public static function syncFeedback(Event $event): void
    {
        $fields = [];
        foreach ($event->feedbackQuestions()->get() as $question) {
            $field = ['id' => 'q'.$question->id, 'label' => $question->title, 'required' => $question->required];
            $fields[] = $field + match ($question->type) {
                EventFeedbackQuestion::TYPE_RATING => ['type' => 'radio', 'options' => array_map(
                    fn ($n) => ['value' => (string) $n, 'label' => str_repeat('★', $n).str_repeat('☆', 5 - $n)],
                    range(5, 1)
                )],
                EventFeedbackQuestion::TYPE_YES_NO => ['type' => 'radio', 'options' => [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']]],
                default => ['type' => 'textarea', 'rows' => 3, 'maxLength' => 2000],
            };
        }
        if ($event->feedback_anonymous) {
            $fields[] = ['id' => 'anonymous', 'label' => "Don't show my name with my answers", 'type' => 'checkbox'];
        }

        self::upsert(self::feedbackSlug($event), 'Feedback: '.Str::limit($event->title, 80), [
            'endpoint' => '/events/{uid}/feedback',
            'method' => 'POST',
            'submitButtonLabel' => 'Send feedback',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'defaultFieldSize' => 12,
            'disableSnackbar' => true,
            'fields' => $fields,
        ]);
    }

    private static function upsert(string $slug, string $name, array $schema): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['uid', 'created_at', 'schema', 'name']);
        // Unchanged: nothing to write (forms are checked each time an event page is shown).
        if ($existing && $existing->schema === $json && $existing->name === $name) {
            return;
        }
        DB::table('form_schemas')->updateOrInsert(['slug' => $slug], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => $name,
            'description' => "Event module form ({$slug}); rebuilt by the module",
            'schema' => $json,
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }
}
