<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a registration attends an "In person and online" event: in_person or
 * online (null: the event's own way, for in-person-only or online-only events
 * and registrations made before). Adds a column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_registrations') && ! Schema::hasColumn('event_registrations', 'attend_mode')) {
            Schema::table('event_registrations', function (Blueprint $table) {
                $table->string('attend_mode', 16)->nullable()->after('meal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event_registrations', 'attend_mode')) {
            Schema::table('event_registrations', function (Blueprint $table) {
                $table->dropColumn('attend_mode');
            });
        }
    }
};
