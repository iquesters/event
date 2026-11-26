<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid registration (tickets, guests, meals; products and orders in commerce)
 * and banners per device. Only adds columns: live data stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'is_paid')) {
                $table->boolean('is_paid')->default(false)->after('registration_closes_at');
                // Prices, the membership period, free children's age, donation, approval mode, and the commerce product ids.
                $table->json('paid_settings')->nullable()->after('is_paid');
                // Order references: {prefix}-0001 (e.g. TTV-MEET-0042).
                $table->string('reference_prefix', 24)->nullable()->after('paid_settings');
                // Public paths on the public disk; a missing size falls back to the next one.
                $table->string('banner_desktop')->nullable()->after('reference_prefix');
                $table->string('banner_tablet')->nullable()->after('banner_desktop');
                $table->string('banner_mobile')->nullable()->after('banner_tablet');
            }
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('event_registrations', 'order_id')) {
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
                // Everyone in the registration: [{name, gender, age_group, meal, alumnus, email, phone, role}].
                $table->json('people')->nullable()->after('donation');
                // self | admin
                $table->string('source', 16)->default('self')->after('people');
                $table->timestamp('terms_accepted_at')->nullable()->after('source');
                $table->unsignedBigInteger('added_by')->nullable()->after('terms_accepted_at');

                $table->index('order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropColumn(['order_id', 'name', 'email', 'phone', 'gender', 'meal', 'adults', 'children', 'veg', 'non_veg', 'donation', 'people', 'source', 'terms_accepted_at', 'added_by']);
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['is_paid', 'paid_settings', 'reference_prefix', 'banner_desktop', 'banner_tablet', 'banner_mobile']);
        });
    }
};
