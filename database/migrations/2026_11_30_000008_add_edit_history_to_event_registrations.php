<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections to a registration's details (name, mobile, meal, a member's
 * batch…): who changed what and when. Only adds a column: live data stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event_registrations', 'edit_history')) {
            Schema::table('event_registrations', function (Blueprint $table) {
                // [{at, by, by_name, admin, changes: ["Guest 1 meal: Veg → Non-veg", …]}]
                $table->json('edit_history')->nullable()->after('people');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event_registrations', 'edit_history')) {
            Schema::table('event_registrations', fn (Blueprint $table) => $table->dropColumn('edit_history'));
        }
    }
};
