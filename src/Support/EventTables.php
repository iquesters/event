<?php

namespace Iquesters\Event\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The module's generic tables (user-interface table schemas). The Attendees
 * list is one table for every event: the attendees page gives it that event's
 * rows (data-data-url = /events/manage/{event}/attendees/data).
 */
class EventTables
{
    public const ATTENDEES = 'event-attendees';

    public const ATTENDEE_ROW = 'event.attendee';

    public static function attendees(): array
    {
        return [
            'entity' => 'event_registrations',
            // Replaced per event by the attendees page.
            'data_url' => '/events/manage/attendees/data',
            // Each row as a card: name, a coloured status badge, the order, people and meals.
            'row_component' => self::ATTENDEE_ROW,
            'record' => [
                ['html' => '/events/manage/registrations/{uid}/summary', 'title' => 'Registration'],
                ['form' => EventForms::ADMIN_CANCEL_REGISTRATION, 'mode' => 'edit', 'title' => 'Cancel this registration', 'when' => ['can_cancel' => '1']],
            ],
            'dt-options' => [
                'columns' => [
                    ['data' => 'name', 'title' => 'Name', 'visible' => true],
                    ['data' => 'reference', 'title' => 'Order', 'visible' => true],
                    ['data' => 'people_label', 'title' => 'People', 'visible' => true],
                    ['data' => 'meals', 'title' => 'Meals', 'visible' => true],
                    ['data' => 'total_label', 'title' => 'Total', 'visible' => true],
                    ['data' => 'status_label', 'title' => 'Status', 'visible' => true],
                    ['data' => 'registered_at', 'title' => 'When', 'visible' => true],
                ],
                'options' => ['pageLength' => 25, 'order' => [], 'responsive' => true],
            ],
            'default_view_mode' => 'inbox',
        ];
    }

    /** The Attendees table schema (made or brought up to date), as user-interface's TableSchema. */
    public static function ensureAttendees()
    {
        $model = 'Iquesters\UserInterface\Models\TableSchema';
        if (! class_exists($model) || ! Schema::hasTable('table_schemas')) {
            return null;
        }
        $schema = json_encode(self::attendees(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row = DB::table('table_schemas')->where('slug', self::ATTENDEES)->first();
        if (! $row) {
            DB::table('table_schemas')->insert([
                'uid' => (string) Str::ulid(),
                'slug' => self::ATTENDEES,
                'name' => 'Attendees',
                'description' => 'Who registered for the event',
                'schema' => $schema,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif (json_decode((string) $row->schema, true) != self::attendees()) {
            DB::table('table_schemas')->where('id', $row->id)->update(['schema' => $schema, 'updated_at' => now()]);
        }

        return $model::query()->where('slug', self::ATTENDEES)->first();
    }
}
