<?php

use Illuminate\Support\Facades\Route;
use Iquesters\Event\Http\Controllers\EventController;
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
            Route::get('/create', [EventManageController::class, 'create'])->name('create');
            Route::post('/', [EventManageController::class, 'store'])->name('store');
            Route::get('/{uid}/edit', [EventManageController::class, 'edit'])->where('uid', $ulid)->name('edit');
            Route::put('/{uid}', [EventManageController::class, 'update'])->where('uid', $ulid)->name('update');
            Route::get('/{uid}/attendees', [EventManageController::class, 'attendees'])->where('uid', $ulid)->name('attendees');
            Route::get('/{uid}/attendees.csv', [EventManageController::class, 'export'])->where('uid', $ulid)->name('export');
        });

        // Members: their organisations' events (checked in the controller).
        Route::prefix('events')->name('events.')->group(function () use ($ulid) {
            Route::get('/', [EventController::class, 'index'])->name('index');
            Route::get('/{uid}', [EventController::class, 'show'])->where('uid', $ulid)->name('show');
            Route::post('/{uid}/register', [EventController::class, 'register'])->where('uid', $ulid)->name('register');
            Route::delete('/{uid}/register', [EventController::class, 'cancel'])->where('uid', $ulid)->name('cancel');
        });
    });
});
