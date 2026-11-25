<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Feedback and ratings: an event's questions, and each attendee's answers. */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Attendees may give feedback once the event has started.
            $table->boolean('feedback_enabled')->default(false)->after('registration_closes_at');
            // Attendees may choose to hide their name in the results.
            $table->boolean('feedback_anonymous')->default(false)->after('feedback_enabled');
        });

        Schema::create('event_feedback_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            // rating (1-5) | yes_no | text
            $table->string('type', 16)->default('rating');
            $table->string('title');
            $table->string('description')->nullable();
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'sort']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('event_feedback_responses', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('event_id');
            // Kept for "one response each", also when anonymous (then hidden in the results).
            $table->unsignedBigInteger('user_id');
            $table->boolean('anonymous')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('event_feedback_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_feedback_response_id');
            $table->unsignedBigInteger('event_feedback_question_id');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('yes')->nullable();
            $table->text('text')->nullable();

            $table->unique(['event_feedback_response_id', 'event_feedback_question_id'], 'event_feedback_answers_unique');
            $table->foreign('event_feedback_response_id')->references('id')->on('event_feedback_responses')->cascadeOnDelete();
            $table->foreign('event_feedback_question_id')->references('id')->on('event_feedback_questions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_feedback_answers');
        Schema::dropIfExists('event_feedback_responses');
        Schema::dropIfExists('event_feedback_questions');
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['feedback_enabled', 'feedback_anonymous']);
        });
    }
};
