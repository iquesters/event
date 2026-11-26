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

        // The Attendees list's rows (resources/views/records/attendee/summary): only with
        // iquesters/user-interface, which renders them.
        $registry = 'Iquesters\UserInterface\Support\RecordComponents';
        if (class_exists($registry)) {
            $this->app->make($registry)->register(\Iquesters\Event\Support\EventTables::ATTENDEE_ROW, 'event::records.attendee.summary', 'summary', 'Attendee (name, status badge, order, people)');
        }

        // A paid registration follows its order: under review, confirmed (paid), back to awaiting payment (rejected), cancelled.
        if (class_exists(OrderStatusChanged::class)) {
            Events::listen(OrderStatusChanged::class, fn (OrderStatusChanged $e) => EventPaidRegistrar::syncFromOrder($e->order));
        }

        // A product of the organisation changes (e.g. a donation or a bundle made after the event):
        // its paid events' registration forms are rebuilt, so they offer it.
        if (class_exists(\Iquesters\Commerce\Models\Product::class)) {
            $resync = function ($product) {
                \Iquesters\Event\Models\Event::query()
                    ->with('organisation')
                    ->where('organisation_id', $product->organisation_id)
                    ->where('is_paid', true)
                    ->where(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>=', now()->startOfDay())->orWhere('ends_at', '>=', now()))
                    ->get()
                    ->each(fn ($event) => \Iquesters\Event\Support\EventForms::syncPaid($event));
            };
            \Iquesters\Commerce\Models\Product::saved($resync);
            \Iquesters\Commerce\Models\Product::deleted($resync);
            // A bundle's items are saved after the bundle (e.g. a ticket + a membership): rebuild then too.
            if (class_exists(\Iquesters\Commerce\Models\ProductBundleItem::class)) {
                $byItem = fn ($item) => ($bundle = \Iquesters\Commerce\Models\Product::query()->find($item->bundle_id)) ? $resync($bundle) : null;
                \Iquesters\Commerce\Models\ProductBundleItem::saved($byItem);
                \Iquesters\Commerce\Models\ProductBundleItem::deleted($byItem);
            }
        }
    }
}
