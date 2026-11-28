<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On an "In person and online" event each person chooses: how many of a
 * registration's people join online (the rest come in person and take
 * places). Adds a column only; existing registrations count as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_registrations') && ! Schema::hasColumn('event_registrations', 'online_people')) {
            Schema::table('event_registrations', function (Blueprint $table) {
                $table->unsignedSmallInteger('online_people')->default(0)->after('attend_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event_registrations', 'online_people')) {
            Schema::table('event_registrations', function (Blueprint $table) {
                $table->dropColumn('online_people');
            });
        }
    }
};
