<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events: where (in person or online, the Maps link), the page's look (cover
 * image, colour) and paid registration (tickets as commerce products, guests,
 * meals, donation, terms; the order in commerce). Only adds columns, each only
 * when missing: live data stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $missing = fn (string $table, string $column) => ! Schema::hasColumn($table, $column);
        $hadLocation = ! $missing('events', 'location_type');

        Schema::table('events', function (Blueprint $table) use ($missing) {
            // in_person | online
            if ($missing('events', 'location_type')) {
                $table->string('location_type', 16)->default('in_person')->after('description');
            }
            // The public page: one cover image (a path on the public disk; without one a cover is
            // drawn) and its colour (a hex colour, e.g. #4f46e5).
            if ($missing('events', 'cover_image')) {
                $table->string('cover_image')->nullable()->after('description');
            }
            if ($missing('events', 'theme')) {
                $table->string('theme', 24)->default('#4f46e5')->after('cover_image');
            }
            // The place's Google Maps link: the public page shows the map only when it is set.
            if ($missing('events', 'maps_url')) {
                $table->string('maps_url', 500)->nullable()->after('address');
            }
            if ($missing('events', 'is_paid')) {
                $table->boolean('is_paid')->default(false)->after('registration_closes_at');
            }
            // The tickets it sells (commerce product ids, see EventProducts), child age, donation, membership.
            if ($missing('events', 'paid_settings')) {
                $table->json('paid_settings')->nullable()->after('is_paid');
            }
        });

        // Existing events: online when they have a link and no venue, else in person.
        if (! $hadLocation) {
            DB::table('events')
                ->whereNotNull('online_url')->where('online_url', '!=', '')
                ->where(fn ($q) => $q->whereNull('venue')->orWhere('venue', ''))
                ->update(['location_type' => 'online']);
        }

        if ($missing('event_registrations', 'order_id')) {
            Schema::table('event_registrations', function (Blueprint $table) {
                // Admins add people without an account (crew, invited guests).
                $table->unsignedBigInteger('user_id')->nullable()->change();
                // registered | awaiting_payment | payment_under_review | cancelled (was 16 characters).
                $table->string('status', 24)->default('registered')->change();
                // The commerce order of a paid registration.
                $table->unsignedBigInteger('order_id')->nullable()->after('user_id');
                // The registrant as entered (prefilled from the account).
                $table->string('name')->nullable()->after('order_id');
                $table->string('email')->nullable()->after('name');
                $table->string('phone', 32)->nullable()->after('email');
                $table->string('gender', 16)->nullable()->after('phone');
                $table->string('meal', 16)->nullable()->after('gender');
                // Head counts for the venue and the caterer (the registrant included).
                $table->unsignedSmallInteger('adults')->default(1)->after('guests');
                $table->unsignedSmallInteger('children')->default(0)->after('adults');
                $table->unsignedSmallInteger('veg')->default(0)->after('children');
                $table->unsignedSmallInteger('non_veg')->default(0)->after('veg');
                $table->decimal('donation', 12, 2)->default(0)->after('non_veg');
                // Everyone in the registration: [{name, gender, age_group, meal, member, email, phone, role, ticket, member_details}].
                $table->json('people')->nullable()->after('donation');
                // self | admin
                $table->string('source', 16)->default('self')->after('people');
                $table->timestamp('terms_accepted_at')->nullable()->after('source');
                $table->unsignedBigInteger('added_by')->nullable()->after('terms_accepted_at');

                $table->index('order_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event_registrations', 'order_id')) {
            Schema::table('event_registrations', function (Blueprint $table) {
                $table->dropIndex(['order_id']);
                $table->dropColumn(['order_id', 'name', 'email', 'phone', 'gender', 'meal', 'adults', 'children', 'veg', 'non_veg', 'donation', 'people', 'source', 'terms_accepted_at', 'added_by']);
            });
        }
        $columns = array_values(array_filter(['location_type', 'cover_image', 'theme', 'maps_url', 'is_paid', 'paid_settings'], fn ($c) => Schema::hasColumn('events', $c)));
        if ($columns !== []) {
            Schema::table('events', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
