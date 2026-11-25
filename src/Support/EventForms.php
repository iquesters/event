<?php

namespace Iquesters\Event\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventFeedbackQuestion;

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
