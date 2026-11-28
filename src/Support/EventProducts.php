<?php

namespace Iquesters\Event\Support;

use Illuminate\Support\Facades\DB;
use Iquesters\Commerce\Models\Product;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;

/**
 * A paid event's tickets and what people can buy with them.
 *
 * - The event's own tickets, set in the event form (name, price, how many,
 *   admins only), are kept as commerce "ticket" products linked to the event
 *   (ref_type event). Admin-only tickets (crew, invited guests) are private.
 *   On sale until registration closes; a ticket removed from the event goes
 *   off sale (orders keep it).
 * - Combinations are made in commerce, not here: a bundle that contains one of
 *   the event's tickets (e.g. "Pass with membership" = the event's Pass
 *   + Membership 2027) is offered at registration next to the tickets.
 * - A donation is offered when the organisation has a donation product.
 *
 * paid_settings: tickets [{product_id, name, price, limit, includes, admin_only}].
 */
class EventProducts
{
    public const REF_TYPE = 'event';

    /** Who a ticket is for. */
    public const FOR = ['everyone', 'adults', 'children'];

    /** How its holder attends an "In person and online" event: both (default), in person only, online only. */
    public const ATTEND = ['both', 'in_person', 'online'];

    public const ATTEND_LABELS = ['both' => 'In person or online', 'in_person' => 'In person', 'online' => 'Online'];

    /**
     * Make or update the event's ticket products from the form's rows.
     *
     * @param  array<int, array{product_id?: mixed, name: string, price: mixed, limit?: mixed, admin_only?: mixed}>  $rows
     * @return array<int, array> the tickets as stored in paid_settings
     */
    public static function sync(Event $event, array $rows, ?int $by = null): array
    {
        return DB::transaction(function () use ($event, $rows, $by) {
            $previous = collect((array) (((array) $event->paid_settings)['tickets'] ?? []))->keyBy('product_id');
            $common = [
                'organisation_id' => $event->organisation_id, 'currency' => 'INR', 'kind' => Product::KIND_VIRTUAL, 'type' => 'ticket',
                'status' => Product::STATUS_ACTIVE, 'updated_by' => $by ?? 0,
                'sale_until' => $event->registration_closes_at ?? $event->starts_at,
                'validity' => Product::VALIDITY_FIXED,
                'valid_from' => $event->starts_at->toDateString(),
                'valid_until' => ($event->ends_at ?? $event->starts_at)->toDateString(),
            ];

            $tickets = [];
            foreach (array_values($rows) as $sort => $row) {
                $name = trim((string) $row['name']);
                $price = round((float) $row['price'], 2);
                $limit = ($row['limit'] ?? null) === null || $row['limit'] === '' ? null : max(0, (int) $row['limit']);
                $adminOnly = filter_var($row['admin_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
                // Who it's for: everyone, adults, or children up to the event's child age.
                $for = in_array($row['for'] ?? null, self::FOR, true) ? $row['for'] : 'everyone';
                // On an "In person and online" event: who may have it (both, in person, online).
                $attend = in_array($row['attend'] ?? null, self::ATTEND, true) ? $row['attend'] : 'both';
                // What comes with it (e.g. Lunch, T-shirt): shown to people, kept as the product's description.
                $includes = trim((string) ($row['includes'] ?? '')) ?: null;
                $old = ! empty($row['product_id']) ? $previous->get((int) $row['product_id']) : null;

                $productId = self::upsert($event, $old['product_id'] ?? null, $common + [
                    'name' => "{$event->title}: {$name}",
                    'price' => $price,
                    'visibility' => $adminOnly ? Product::VISIBILITY_PRIVATE : Product::VISIBILITY_PUBLIC,
                    'track_stock' => $limit !== null,
                    'stock_quantity' => $limit,
                    'description' => $includes ? "Includes: {$includes}" : null,
                ], $by);

                $tickets[] = ['product_id' => $productId, 'name' => $name, 'price' => $price, 'limit' => $limit, 'includes' => $includes, 'for' => $for, 'attend' => $attend, 'admin_only' => $adminOnly, 'sort' => $sort];
            }

            // Tickets taken off the event: off sale.
            $gone = $previous->keys()->map(fn ($id) => (int) $id)->diff(array_column($tickets, 'product_id'))->all();
            if ($gone) {
                Product::query()->whereIn('id', $gone)->where('ref_type', self::REF_TYPE)->where('ref_id', $event->id)->update(['status' => Product::STATUS_INACTIVE]);
            }

            return $tickets;
        });
    }

    /** All the event's tickets off sale (it isn't paid any more). */
    public static function stopSelling(Event $event): void
    {
        $ids = collect((array) (((array) $event->paid_settings)['tickets'] ?? []))->pluck('product_id')->filter()->all();
        if ($ids) {
            Product::query()->whereIn('id', $ids)->where('ref_type', self::REF_TYPE)->where('ref_id', $event->id)->update(['status' => Product::STATUS_INACTIVE]);
        }
    }

    /**
     * What a person can pick: the event's tickets (admin-only ones for admins),
     * then the commerce bundles that contain one of them (public, on sale).
     *
     * @return array<int, array{product: Product, name: string, admin_only: bool, membership_product_id: ?int, bundle: bool}>
     */
    public static function tickets(Event $event, bool $withAdminOnly = false): array
    {
        $rows = collect((array) (((array) $event->paid_settings)['tickets'] ?? []));
        $ids = $rows->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $products = Product::query()->where('organisation_id', $event->organisation_id)->whereIn('id', $ids)->get()->keyBy('id');

        $tickets = $rows
            ->map(fn ($t) => ['product' => $products->get((int) ($t['product_id'] ?? 0)), 'name' => $t['name'], 'includes' => $t['includes'] ?? null, 'for' => $t['for'] ?? 'everyone', 'attend' => $t['attend'] ?? 'both', 'admin_only' => (bool) ($t['admin_only'] ?? false), 'membership_product_id' => null, 'bundle' => false])
            ->filter(fn ($t) => $t['product'] && $t['product']->isActive() && ($withAdminOnly || ! $t['admin_only']))
            ->values();

        // Combinations made in commerce: bundles with one of these tickets inside.
        $bundles = Product::query()
            ->where('organisation_id', $event->organisation_id)
            ->where('type', 'bundle')
            ->where('status', Product::STATUS_ACTIVE)
            ->where('visibility', Product::VISIBILITY_PUBLIC)
            ->whereHas('bundleItems', fn ($q) => $q->whereIn('product_id', $ids))
            ->with('bundleItems.product')
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $bundle) => $bundle->isOnSale())
            ->map(fn (Product $bundle) => [
                'product' => $bundle,
                'name' => $bundle->name,
                // Inside: the event's tickets it holds (who it's for follows them).
                'ticket_ids' => $bundle->bundleItems->pluck('product_id')->map(fn ($id) => (int) $id)->intersect($ids)->values()->all(),
                'for' => 'adults',
                // In person or online as its event tickets may be had (filled in below).
                'attend' => 'both',
                // What's inside the bundle, e.g. "Guest pass + Membership 2027".
                'includes' => $bundle->bundleItems->pluck('product')->filter()->pluck('name')->implode(' + '),
                'admin_only' => false,
                // A membership inside: the person's own (their email), and not twice.
                'membership_product_id' => $bundle->bundleItems->pluck('product')->filter()->firstWhere('type', 'membership')?->id,
                'bundle' => true,
            ]);

        // A bundle may be had in person or online as the event tickets inside it may.
        $attendOf = $tickets->mapWithKeys(fn ($t) => [(int) $t['product']->id => $t['attend']]);
        $bundles = $bundles->map(function (array $b) use ($attendOf) {
            $modes = collect($b['ticket_ids'])->map(fn ($id) => $attendOf->get($id))->filter()->unique()->values()->all();
            $b['attend'] = count($modes) === 1 ? $modes[0] : 'both';

            return $b;
        });

        return $tickets->concat($bundles)->values()->all();
    }

    /** May this ticket be had by someone attending this way (null: any way)? */
    public static function allows(array $ticket, ?string $mode): bool
    {
        return $mode === null || in_array($ticket['attend'] ?? 'both', ['both', $mode], true);
    }

    /**
     * The tickets a person may have, by age: the event's tickets for them
     * (everyone, or adults / children), without bundles.
     *
     * @return array<int, array> entries of tickets()
     */
    public static function ticketsFor(Event $event, string $ageGroup, bool $withAdminOnly = false, ?string $mode = null): array
    {
        $group = $ageGroup === 'child' ? 'children' : 'adults';

        return array_values(array_filter(self::tickets($event, $withAdminOnly), fn ($t) => ! $t['bundle']
            && in_array($t['for'] ?? 'everyone', ['everyone', $group], true)
            // In person and online: the tickets for how they attend.
            && self::allows($t, $mode)));
    }

    /**
     * The ticket a person gets:
     * - the one they chose, when it's for them;
     * - a member (adult): a bundle with one of their tickets and a membership,
     *   unless they already have that membership ($hasMembership);
     * - else their only ticket.
     *
     * @param  callable(int $membershipProductId): bool  $hasMembership
     * @return array an entry of tickets()
     *
     * @throws \DomainException with a message for the person
     */
    public static function choose(Event $event, string $ageGroup, bool $member, ?int $chosenId, bool $admin, callable $hasMembership, string $who, ?string $mode = null): array
    {
        // In person and online: only the tickets for how they attend.
        $mode = $event->isHybrid() ? $mode : null;
        $own = self::ticketsFor($event, $ageGroup, $admin, $mode);
        $ownIds = array_map(fn ($t) => (int) $t['product']->id, $own);
        $bundles = $ageGroup === 'child' ? [] : array_values(array_filter(self::tickets($event), fn ($t) => $t['bundle'] && self::allows($t, $mode) && array_intersect($t['ticket_ids'] ?? [], $ownIds) !== []));

        if ($chosenId) {
            foreach ([...$own, ...$bundles] as $t) {
                if ((int) $t['product']->id === $chosenId) {
                    return $t;
                }
            }
            $online = $mode === EventRegistration::ATTEND_ONLINE;
            throw new \DomainException(self::word("{$who}: that ticket isn't for ".($mode ? ($online ? 'someone joining online' : 'someone coming in person').' or ' : '').($ageGroup === 'child' ? 'a child' : 'an adult').'.'));
        }
        if ($member && self::sellsMembership($event)) {
            foreach ($bundles as $bundle) {
                if ($bundle['membership_product_id'] && ! $hasMembership((int) $bundle['membership_product_id'])) {
                    return $bundle;
                }
            }
        }
        if (count($own) === 1) {
            return $own[0];
        }
        throw new \DomainException(self::word($own === []
            ? "{$who}: this event has no ticket for ".($ageGroup === 'child' ? 'children' : 'adults').($mode ? ' '.($mode === EventRegistration::ATTEND_ONLINE ? 'joining online' : 'coming in person') : '').'.'
            : "{$who}: choose a ticket."));
    }

    /**
     * The donation a registration offers: the organisation's donation when
     * the event asks for one, else the one it offers with every paid order
     * (commerce setting), else none.
     */
    public static function donationOffered(Event $event): ?Product
    {
        return $event->paid('ask_donation')
            ? self::donation($event)
            : Product::donationForEveryOrder((int) $event->organisation_id);
    }

    /**
     * Is registering also joining the organisation? The event says so
     * ("Registration includes membership") and sells a membership: a bundle
     * (made in commerce) with one of its tickets and a membership.
     */
    public static function sellsMembership(Event $event): bool
    {
        if (! $event->paid('with_membership')) {
            return false;
        }
        foreach (self::tickets($event) as $t) {
            if ($t['bundle'] && ! empty($t['membership_product_id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the person registering (an adult) gets, for the form to show:
     * the bundle with the membership when registering includes it, else their
     * only adult ticket. Null when they must choose.
     */
    public static function registrantTicket(Event $event): ?array
    {
        if (self::sellsMembership($event)) {
            foreach (self::tickets($event) as $t) {
                if ($t['bundle'] && ! empty($t['membership_product_id'])) {
                    return $t;
                }
            }
        }
        $own = self::ticketsFor($event, 'adult');

        return count($own) === 1 ? $own[0] : null;
    }

    /** The organisation's donation product (made in commerce), if it has one on sale. */
    public static function donation(Event $event): ?Product
    {
        return Product::query()
            ->where('organisation_id', $event->organisation_id)
            ->where('type', 'donation')
            ->where('status', Product::STATUS_ACTIVE)
            ->where('visibility', Product::VISIBILITY_PUBLIC)
            ->orderBy('id')
            ->get()
            ->first(fn (Product $p) => $p->isOnSale());
    }

    /**
     * The organisation's donation product, made when it has none: "Donation",
     * the giver chooses the amount (at least 1). It belongs to the
     * organisation (not the event), so later events and checkouts reuse it;
     * its rules are edited in commerce.
     */
    public static function ensureDonation(Event $event, ?int $by = null): Product
    {
        $existing = Product::query()->where('organisation_id', $event->organisation_id)->where('type', 'donation')
            ->where('status', Product::STATUS_ACTIVE)->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        return Product::create([
            'organisation_id' => $event->organisation_id,
            'name' => 'Donation',
            'type' => 'donation',
            'kind' => Product::KIND_VIRTUAL,
            'visibility' => Product::VISIBILITY_PUBLIC,
            'status' => Product::STATUS_ACTIVE,
            'price' => 1,
            'currency' => 'INR',
            'custom_amount' => true,
            'min_amount' => 1,
            'validity' => Product::VALIDITY_NONE,
            'description' => 'Any amount, given with a payment.',
            'ref_type' => 'organisation',
            'ref_id' => $event->organisation_id,
            'created_by' => $by ?? 0,
            'updated_by' => $by ?? 0,
        ]);
    }

    private static function upsert(Event $event, $id, array $values, ?int $by): int
    {
        $product = $id ? Product::query()->whereKey((int) $id)->where('organisation_id', $event->organisation_id)->first() : null;
        if ($product) {
            $product->update($values);

            return (int) $product->id;
        }

        return (int) Product::create($values + ['ref_type' => self::REF_TYPE, 'ref_id' => $event->id, 'created_by' => $by ?? 0])->id;
    }

    /** A message in the app's word for "ticket". */
    private static function word(string $text): string
    {
        return EventWords::t($text);
    }
}
