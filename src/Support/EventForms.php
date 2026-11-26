<?php

namespace Iquesters\Event\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public const BANNERS = 'event-banners';

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
        self::upsert(self::BANNERS, 'Event banners', self::banners());
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
                ['id' => 'when', 'type' => 'section', 'label' => 'When and where', 'description' => 'Date, time and the venue or online link.'],
                ['id' => 'starts_at', 'label' => 'Starts', 'type' => 'datetime-local', 'required' => true],
                ['id' => 'ends_at', 'label' => 'Ends', 'type' => 'datetime-local'],
                ['id' => 'venue', 'label' => 'Venue', 'type' => 'text', 'maxLength' => 255, 'placeholder' => 'e.g. School auditorium'],
                ['id' => 'online_url', 'label' => 'Online link', 'type' => 'url', 'maxLength' => 255, 'placeholder' => 'https://'],
                ['id' => 'address', 'label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'maxLength' => 1000],
                ['id' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 5, 'maxLength' => 10000],
                ['id' => 'registration', 'type' => 'section', 'label' => 'Registration', 'description' => 'Who can come, and until when.'],
                ['id' => 'registration_open', 'label' => 'Registration open', 'type' => 'checkbox'],
                ['id' => 'capacity', 'label' => 'Places', 'type' => 'number', 'min' => 1, 'helperText' => 'Leave empty for unlimited places.'],
                ['id' => 'registration_closes_at', 'label' => 'Register by', 'type' => 'datetime-local'],
                ['id' => 'paid', 'type' => 'section', 'label' => 'Paid registration', 'description' => 'Tickets, guests and meals; people pay by UPI (or at the desk). Saving makes the products in Commerce (pass, child, crew, membership, the pass with membership, donation); edit them there for more.'],
                ['id' => 'is_paid', 'label' => 'Registration is paid', 'type' => 'checkbox'],
                ['id' => 'pass_price', 'label' => 'Pass (per person over the free age), ₹', 'type' => 'number', 'min' => 0, 'step' => '0.01'],
                ['id' => 'bundle_price', 'label' => 'Pass with membership (alumni), ₹', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'helperText' => 'E.g. 600: the pass plus the membership, for each alumnus.'],
                ['id' => 'membership_price', 'label' => 'Membership on its own, ₹', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'helperText' => 'Its price after the event (or bought alone).'],
                ['id' => 'membership_from', 'label' => 'Membership from', 'type' => 'date'],
                ['id' => 'membership_until', 'label' => 'Membership until', 'type' => 'date'],
                ['id' => 'free_child_age', 'label' => 'Children free up to (age, included)', 'type' => 'number', 'min' => 0, 'max' => 17],
                ['id' => 'reference_prefix', 'label' => 'Order reference prefix', 'type' => 'text', 'maxLength' => 24, 'placeholder' => 'e.g. TTV-MEET'],
                ['id' => 'donation', 'label' => 'Ask for a donation (optional amount)', 'type' => 'checkbox'],
                ['id' => 'approval', 'label' => 'Memberships of alumni who pay', 'type' => 'select', 'options' => [
                    ['value' => 'together', 'label' => 'Approved when the payment is verified'],
                    ['value' => 'separate', 'label' => 'Go to Applications for a separate decision'],
                ]],
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

    /** Banners per device (multipart; {uid} = the event). */
    public static function banners(): array
    {
        $file = fn (string $id, string $label, string $help) => ['id' => $id, 'label' => $label, 'type' => 'file', 'accept' => 'image/jpeg,image/png,image/webp', 'helperText' => $help];

        return [
            'endpoint' => '/events/manage/{uid}/banners',
            'method' => 'POST',
            'submitButtonLabel' => 'Save banners',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'defaultFieldSize' => 12,
            'fields' => [
                $file('banner_desktop', 'Desktop banner', 'Wide, e.g. 1920 × 600. JPG, PNG or WEBP, up to 4 MB.'),
                $file('banner_tablet', 'Tablet banner', 'E.g. 1024 × 500. Empty: the desktop one.'),
                $file('banner_mobile', 'Mobile banner', 'E.g. 800 × 800. Empty: the tablet or desktop one.'),
                ['id' => 'remove', 'label' => 'Remove all banners', 'type' => 'checkbox'],
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
    }

    public static function paidRegistration(Event $event, bool $admin): array
    {
        $age = (int) $event->paid('free_child_age');
        $organisation = $event->organisation?->name ?? 'the school';
        $genders = array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(EventRegistration::GENDERS), EventRegistration::GENDERS);
        $meals = array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(EventRegistration::MEALS), EventRegistration::MEALS);

        $fields = [
            ['id' => 'you', 'type' => 'section', 'label' => $admin ? 'Attendee' : 'You', 'description' => $admin ? 'The person registering (an account with this email is linked).' : 'Your details for the organisers.'],
            ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
            ['id' => 'phone', 'label' => 'Mobile (WhatsApp)', 'type' => 'tel', 'required' => true, 'maxLength' => 32],
            ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => ! $admin, 'maxLength' => 255],
            ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => $genders],
            ['id' => 'meal', 'label' => 'Meal', 'type' => 'radio', 'required' => true, 'options' => $meals],
            ['id' => 'alumnus', 'label' => ($admin ? 'An alumnus' : 'I am an alumnus').' of '.$organisation.' (the pass with membership)', 'type' => 'checkbox'],
            ['id' => 'people', 'type' => 'section', 'label' => 'Guests', 'description' => "Everyone coming with you. Children up to and including {$age} are free. A guest who is also an alumnus gets their own membership: add their email."],
            [
                'id' => 'guests',
                'label' => 'Guests',
                'type' => 'repeater',
                'addLabel' => 'Add guest',
                'fields' => [
                    ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
                    ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => $genders],
                    ['id' => 'age_group', 'label' => 'Age', 'type' => 'select', 'required' => true, 'options' => [
                        ['value' => 'adult', 'label' => 'Over '.$age],
                        ['value' => 'child', 'label' => $age.' or under (free)'],
                    ]],
                    ['id' => 'meal', 'label' => 'Meal', 'type' => 'select', 'required' => true, 'options' => $meals],
                    ['id' => 'alumnus', 'label' => 'Also an alumnus', 'type' => 'checkbox'],
                    ['id' => 'email', 'label' => 'Email (alumni)', 'type' => 'email', 'maxLength' => 255],
                    ['id' => 'phone', 'label' => 'Mobile (alumni)', 'type' => 'tel', 'maxLength' => 32],
                ],
            ],
        ];
        if ($event->paid('donation')) {
            $fields[] = ['id' => 'giving', 'type' => 'section', 'label' => 'Donation', 'description' => 'Optional: any amount for '.$organisation.'.'];
            $fields[] = ['id' => 'donation', 'label' => 'Donation, ₹', 'type' => 'number', 'min' => 0, 'step' => '1'];
        }
        if ($admin) {
            $fields[] = ['id' => 'free', 'type' => 'section', 'label' => 'Free entry', 'description' => 'Crew (sound, light…) and invited guests pay nothing.'];
            $fields[] = ['id' => 'crew', 'label' => 'Crew / invited guest: free for everyone in this entry', 'type' => 'checkbox'];
            $fields[] = ['id' => 'note', 'label' => 'Note', 'type' => 'text', 'maxLength' => 500];
        } else {
            $links = $event->organisation && class_exists(OrganisationPolicies::class) ? OrganisationPolicies::links($event->organisation) : [];
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
            'disableSnackbar' => ! $admin,
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

        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['uid', 'created_at']);
        DB::table('form_schemas')->updateOrInsert(['slug' => $slug], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => $name,
            'description' => "Event module form ({$slug}); rebuilt by the module",
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }
}
