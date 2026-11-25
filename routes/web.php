<?php

use Illuminate\Support\Facades\Route;
use Iquesters\Event\Http\Controllers\EventController;
use Iquesters\Event\Http\Controllers\EventFeedbackController;
use Iquesters\Event\Http\Controllers\EventFeedbackManageController;
use Iquesters\Event\Http\Controllers\EventManageController;
use Iquesters\Event\Http\Controllers\PublicEventController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware('web')->group(function () use ($ulid) {
    // A public event's page (no sign-in).
    Route::get('e/{slug}', [PublicEventController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('events.public');

    Route::middleware('auth')->group(function () use ($ulid) {
        // Super-admins and organisation admins; the controller limits them to their organisations.
        Route::middleware('ensure.event.manager')->prefix('events/manage')->name('events.manage.')->group(function () use ($ulid) {
            Route::get('/', [EventManageController::class, 'index'])->name('index');
            // The generic table's rows (data_url) and records; the record's links section.
            Route::get('/data', [EventManageController::class, 'data'])->name('data');
            Route::get('/data/{uid}', [EventManageController::class, 'record'])->where('uid', $ulid)->name('record');
            Route::get('/{uid}/links', [EventManageController::class, 'links'])->where('uid', $ulid)->name('links');
            // Options of the event form's selects.
            Route::get('/options/organisations', [EventManageController::class, 'organisationOptions'])->name('options.organisations');
            Route::get('/options/types', [EventManageController::class, 'typeOptions'])->name('options.types');
            // The event-details form posts here.
            Route::post('/', [EventManageController::class, 'store'])->name('store');
            Route::get('/{uid}/edit', [EventManageController::class, 'edit'])->where('uid', $ulid)->name('edit');
            Route::put('/{uid}', [EventManageController::class, 'update'])->where('uid', $ulid)->name('update');
            Route::get('/{uid}/attendees', [EventManageController::class, 'attendees'])->where('uid', $ulid)->name('attendees');
            Route::get('/{uid}/attendees.csv', [EventManageController::class, 'export'])->where('uid', $ulid)->name('export');

            // Feedback: the questions, the results (and CSV), the QR poster.
            Route::get('/{uid}/feedback', [EventFeedbackManageController::class, 'results'])->where('uid', $ulid)->name('feedback.results');
            Route::get('/{uid}/feedback.csv', [EventFeedbackManageController::class, 'export'])->where('uid', $ulid)->name('feedback.export');
            Route::get('/{uid}/feedback/questions', [EventFeedbackManageController::class, 'questions'])->where('uid', $ulid)->name('feedback.questions');
            Route::put('/{uid}/feedback/questions', [EventFeedbackManageController::class, 'saveQuestions'])->where('uid', $ulid)->name('feedback.questions.save');
            Route::put('/{uid}/feedback/preset', [EventFeedbackManageController::class, 'preset'])->where('uid', $ulid)->name('feedback.preset');
            Route::get('/{uid}/feedback/poster', [EventFeedbackManageController::class, 'poster'])->where('uid', $ulid)->name('feedback.poster');
        });

        // Members: their organisations' events (checked in the controller).
        Route::prefix('events')->name('events.')->group(function () use ($ulid) {
            Route::get('/', [EventController::class, 'index'])->name('index');
            Route::get('/{uid}', [EventController::class, 'show'])->where('uid', $ulid)->name('show');
            Route::post('/{uid}/register', [EventController::class, 'register'])->where('uid', $ulid)->name('register');
            Route::delete('/{uid}/register', [EventController::class, 'cancel'])->where('uid', $ulid)->name('cancel');
            // Feedback (the QR poster links here).
            Route::get('/{uid}/feedback', [EventFeedbackController::class, 'show'])->where('uid', $ulid)->name('feedback.show');
            Route::post('/{uid}/feedback', [EventFeedbackController::class, 'store'])->where('uid', $ulid)->name('feedback.store');
        });
    });
});
