<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Events of an organisation, and the people who registered for them. */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->string('title');
            // The public page's address: /e/{slug}.
            $table->string('slug', 120)->unique();
            $table->string('type', 60)->nullable();
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('venue')->nullable();
            $table->text('address')->nullable();
            $table->string('online_url')->nullable();
            // members | public
            $table->string('visibility', 16)->default('members');
            // draft | published | cancelled (past events are worked out from the dates)
            $table->string('status', 16)->default('draft');
            $table->boolean('registration_open')->default(true);
            // Null: no limit.
            $table->unsignedInteger('capacity')->nullable();
            $table->dateTime('registration_closes_at')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->index(['organisation_id', 'status', 'starts_at']);
            $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('user_id');
            // registered | cancelled
            $table->string('status', 16)->default('registered');
            // People coming with them (counted against the capacity).
            $table->unsignedTinyInteger('guests')->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['event_id', 'status']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
