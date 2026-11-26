<?php

namespace Iquesters\Event\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Iquesters\Commerce\Models\Product;
use Iquesters\Event\Models\Event;

/**
 * The commerce products of a paid event, made from its settings and linked to
 * it (ref_type event): the pass, the free child pass, the private crew / invited
 * guest pass, the membership for the period set on the event, the bundle "pass
 * with membership", and the donation. Saving the event updates them (orders
 * already made keep their prices). Admins can change them further in commerce;
 * the next save of the event sets the event's prices again.
 *
 * The membership product is the organisation's for that period: a second paid
 * event in the same period reuses it.
 */
class EventProducts
{
    public const REF_TYPE = 'event';

    public const MEMBERSHIP_REF_TYPE = 'organisation_membership';

    /** Make or update the products; their ids go into paid_settings.products. */
    public static function sync(Event $event, ?int $by = null): void
    {
        if (! $event->is_paid) {
            self::stopSelling($event);

            return;
        }

        DB::transaction(function () use ($event, $by) {
            $title = $event->title;
            $age = (int) $event->paid('free_child_age');
            $saleUntil = $event->registration_closes_at ?? $event->starts_at;
            $eventDates = ['validity' => Product::VALIDITY_FIXED, 'valid_from' => $event->starts_at->toDateString(), 'valid_until' => ($event->ends_at ?? $event->starts_at)->toDateString()];
            $common = ['organisation_id' => $event->organisation_id, 'currency' => 'INR', 'kind' => Product::KIND_VIRTUAL, 'status' => Product::STATUS_ACTIVE, 'updated_by' => $by ?? 0];
            $ids = (array) (((array) $event->paid_settings)['products'] ?? []);

            $passPrice = (float) $event->paid('pass_price');
            $ids['pass'] = self::upsert($event, $ids['pass'] ?? null, $common + $eventDates + [
                'name' => "{$title}: Pass", 'type' => 'ticket', 'price' => $passPrice, 'visibility' => Product::VISIBILITY_PUBLIC,
                'sale_until' => $saleUntil, 'description' => 'Entry and meal for one person over '.$age.'.',
            ], $by);
            $ids['child'] = self::upsert($event, $ids['child'] ?? null, $common + $eventDates + [
                'name' => "{$title}: Child {$age} or under", 'type' => 'ticket', 'price' => 0, 'visibility' => Product::VISIBILITY_PUBLIC,
                'sale_until' => $saleUntil, 'description' => "Free for children up to and including {$age}.",
            ], $by);
            $ids['crew'] = self::upsert($event, $ids['crew'] ?? null, $common + $eventDates + [
                'name' => "{$title}: Crew / invited guest", 'type' => 'ticket', 'price' => 0, 'visibility' => Product::VISIBILITY_PRIVATE,
                'sale_until' => null, 'description' => 'Added by admins only.',
            ], $by);

            // Membership for the period, with the alumnus bundle.
            $from = $event->paid('membership_from');
            $until = $event->paid('membership_until');
            if ($from && $until) {
                $ids['membership'] = self::membership($event, $from, $until, (float) $event->paid('membership_price'), $common, $by);
                $bundlePrice = (float) $event->paid('bundle_price');
                $passInBundle = min($passPrice, $bundlePrice);
                $ids['bundle'] = self::upsert($event, $ids['bundle'] ?? null, $common + [
                    'name' => "{$title}: Pass with membership", 'type' => 'bundle', 'price' => $bundlePrice, 'visibility' => Product::VISIBILITY_PUBLIC,
                    'validity' => Product::VALIDITY_NONE, 'sale_until' => $saleUntil,
                    'description' => 'For alumni: the pass and the membership till '.Carbon::parse($until)->format('j M Y').'.',
                ], $by);
                $bundle = Product::query()->find($ids['bundle']);
                $bundle->bundleItems()->delete();
                $bundle->bundleItems()->create(['product_id' => $ids['pass'], 'quantity' => 1, 'price_in_bundle' => $passInBundle, 'sort' => 0]);
                $bundle->bundleItems()->create(['product_id' => $ids['membership'], 'quantity' => 1, 'price_in_bundle' => round($bundlePrice - $passInBundle, 2), 'sort' => 1]);
            } else {
                foreach (['bundle'] as $role) {
                    if (! empty($ids[$role])) {
                        Product::query()->whereKey($ids[$role])->update(['status' => Product::STATUS_INACTIVE]);
                    }
                }
                unset($ids['membership'], $ids['bundle']);
            }

            if ($event->paid('donation')) {
                $ids['donation'] = self::upsert($event, $ids['donation'] ?? null, $common + [
                    'name' => "{$title}: Donation", 'type' => 'donation', 'price' => 0, 'visibility' => Product::VISIBILITY_PUBLIC,
                    'validity' => Product::VALIDITY_NONE, 'custom_amount' => true, 'min_amount' => 1, 'sale_until' => $saleUntil,
                ], $by, keep: ['min_amount', 'max_amount']);
            } elseif (! empty($ids['donation'])) {
                Product::query()->whereKey($ids['donation'])->update(['status' => Product::STATUS_INACTIVE]);
                unset($ids['donation']);
            }

            $settings = (array) $event->paid_settings;
            $settings['products'] = $ids;
            $event->forceFill(['paid_settings' => $settings])->saveQuietly();
        });
    }

    /** No longer paid: its products are off sale (orders keep them). */
    public static function stopSelling(Event $event): void
    {
        $ids = array_values((array) (((array) $event->paid_settings)['products'] ?? []));
        $membership = $event->productId('membership');
        $ids = array_filter($ids, fn ($id) => (int) $id !== $membership); // the organisation's, not the event's
        if ($ids) {
            Product::query()->whereIn('id', $ids)->update(['status' => Product::STATUS_INACTIVE]);
        }
    }

    /** The products the event sells, by role. @return array<string, Product> */
    public static function products(Event $event): array
    {
        $ids = array_filter((array) (((array) $event->paid_settings)['products'] ?? []));
        $products = Product::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $products->get((int) $id))->filter()->all();
    }

    /**
     * @param  string[]  $keep  fields an admin may have changed in commerce that the event doesn't set again
     */
    private static function upsert(Event $event, ?int $id, array $values, ?int $by, array $keep = []): int
    {
        $product = $id ? Product::query()->whereKey($id)->where('organisation_id', $event->organisation_id)->first() : null;
        if ($product) {
            $product->update(array_diff_key($values, array_flip($keep)));

            return (int) $product->id;
        }

        return (int) Product::create($values + ['ref_type' => self::REF_TYPE, 'ref_id' => $event->id, 'created_by' => $by ?? 0])->id;
    }

    /** The organisation's membership product for the period (shared by its events). */
    private static function membership(Event $event, string $from, string $until, float $price, array $common, ?int $by): int
    {
        $from = Carbon::parse($from)->toDateString();
        $until = Carbon::parse($until)->toDateString();
        $values = $common + [
            'name' => ($event->organisation?->name ?? 'Membership').' membership '.Carbon::parse($from)->format('Y').(Carbon::parse($until)->format('Y') !== Carbon::parse($from)->format('Y') ? '–'.Carbon::parse($until)->format('y') : ''),
            'type' => 'membership', 'price' => $price, 'visibility' => Product::VISIBILITY_PUBLIC,
            'validity' => Product::VALIDITY_FIXED, 'valid_from' => $from, 'valid_until' => $until,
            'description' => 'Membership from '.Carbon::parse($from)->format('j M Y').' to '.Carbon::parse($until)->format('j M Y').'.',
        ];

        $product = Product::query()
            ->where('organisation_id', $event->organisation_id)
            ->where('type', 'membership')
            ->where('ref_type', self::MEMBERSHIP_REF_TYPE)
            ->whereDate('valid_from', $from)
            ->whereDate('valid_until', $until)
            ->first();
        if ($product) {
            $product->update(['price' => $price, 'status' => Product::STATUS_ACTIVE, 'updated_by' => $by ?? 0]);

            return (int) $product->id;
        }

        return (int) Product::create($values + ['ref_type' => self::MEMBERSHIP_REF_TYPE, 'ref_id' => $event->organisation_id, 'created_by' => $by ?? 0])->id;
    }
}
