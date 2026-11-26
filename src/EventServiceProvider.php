<?php

namespace Iquesters\Event;

use Illuminate\Support\Facades\Event as Events;
use Iquesters\Commerce\Events\OrderStatusChanged;
use Iquesters\Event\Support\EventPaidRegistrar;
use Iquesters\Foundation\System\Providers\BaseServiceProvider;

class EventServiceProvider extends BaseServiceProvider
{
    // Routes, views, migrations, the seeder command and the conf are
    // auto-detected by BaseServiceProvider.

    public function boot(): void
    {
        parent::boot();

        // A paid registration follows its order: under review, confirmed (paid), back to awaiting payment (rejected), cancelled.
        if (class_exists(OrderStatusChanged::class)) {
            Events::listen(OrderStatusChanged::class, fn (OrderStatusChanged $e) => EventPaidRegistrar::syncFromOrder($e->order));
        }
    }
}
