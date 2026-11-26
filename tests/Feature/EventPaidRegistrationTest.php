<?php

namespace Iquesters\Event\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventForms;
use Iquesters\Event\Support\EventPaidRegistrar;
use Iquesters\Event\Support\EventProducts;
use Iquesters\Event\Tests\TestCase;
use Iquesters\Commerce\Enums\Module as CommerceModule;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Organisation\Models\Organisation;

/**
 * Paid registration: tickets made on the event (commerce products), each person
 * picks one, one order per registration, statuses, admins.
 */
class EventPaidRegistrationTest extends TestCase
{
    /** The organisation's membership and donation: products in commerce (not made by the event). */
    private function commerce(Organisation $organisation): array
    {
        return [
            'membership' => Product::create(['organisation_id' => $organisation->id, 'name' => 'Membership 2027', 'type' => 'membership', 'price' => 300, 'currency' => 'INR',
                'validity' => Product::VALIDITY_FIXED, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']),
            'donation' => Product::create(['organisation_id' => $organisation->id, 'name' => 'Donation', 'type' => 'donation', 'price' => 0, 'currency' => 'INR', 'custom_amount' => true, 'min_amount' => 1]),
        ];
    }

    /**
     * The TTV meet. The event's own tickets: Guest pass ₹600, Child ₹0, Crew ₹0
     * (admins only). In commerce, the alumni's combination: the bundle "Alumnus
     * pass" ₹600 = the event's Guest pass (600) + Membership 2027 (0).
     */
    private function paidEvent(Organisation $organisation, $admin, array $extra = []): Event
    {
        $commerce = $this->commerce($organisation);
        // Order numbering is commerce's setting (not the event's).
        ConfProvider::from(CommerceModule::COMMERCE)->update(['order_reference_prefix' => 'TTV-MEET']);
        $this->actingAs($admin)->postJson('/events/manage', $extra + [
            'organisation_id' => $organisation->id,
            'title' => '4th Alumni Meet',
            'starts_at' => now()->addMonth()->setTime(10, 0)->format('Y-m-d\TH:i'),
            'status' => 'published',
            'visibility' => 'members',
            'venue' => 'School auditorium',
            'registration_open' => true,
            'is_paid' => true,
            'tickets' => [
                ['name' => 'Guest pass', 'price' => 600, 'includes' => 'Lunch, T-shirt', 'for' => 'adults'],
                ['name' => 'Child', 'price' => 0, 'for' => 'children'],
                ['name' => 'Crew', 'price' => 0, 'for' => 'everyone', 'admin_only' => true],
            ],
            'ask_donation' => true,
            // Registering is also joining (the alumni meet).
            'with_membership' => true,
        ])->assertCreated();
        $event = Event::query()->latest('id')->firstOrFail();

        $this->alumnusBundle($event, $commerce['membership']);

        return $event;
    }

    /** Made in commerce (not on the event): the event's Guest pass + the membership, ₹600. */
    private function alumnusBundle(Event $event, Product $membership, string $name = 'Alumnus pass'): Product
    {
        $pass = Product::query()->find((int) $this->ticket($event, 'Guest pass'));
        $bundle = Product::create(['organisation_id' => $event->organisation_id, 'name' => $name, 'type' => 'bundle', 'price' => $pass->price, 'currency' => 'INR']);
        $bundle->bundleItems()->create(['product_id' => $pass->id, 'quantity' => 1, 'price_in_bundle' => $pass->price, 'sort' => 0]);
        $bundle->bundleItems()->create(['product_id' => $membership->id, 'quantity' => 1, 'price_in_bundle' => 0, 'sort' => 1]);

        return $bundle;
    }

    /** What a person can pick, by name (the event's tickets and the bundles with them). */
    private function ticket(Event $event, string $name): string
    {
        return (string) collect(EventProducts::tickets($event->fresh(), true))->firstWhere('name', $name)['product']->id;
    }

    private function register(Event $event, $user, array $data = [])
    {
        return $this->actingAs($user)->postJson('/events/'.$event->uid.'/pay-register', $data + [
            'name' => 'Rahul Sen',
            'phone' => '9800000001',
            'email' => 'rahul@example.test',
            'gender' => 'male',
            'meal' => 'veg',
            // No "member" question: the person registering joins, so they get the pass with membership.
            'accept_terms' => true,
        ]);
    }

    public function test_tickets_made_on_the_event_are_commerce_products_and_bundles_come_from_commerce(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        $event = $this->paidEvent($organisation, $admin);

        // The event form has no membership, donation or order-number fields.
        $form = collect(EventForms::details()['fields']);
        $this->assertNotContains('donation_product_id', $form->pluck('id')->all());
        $this->assertNotContains('reference_prefix', $form->pluck('id')->all());
        $this->assertSame(['product_id', 'name', 'price', 'limit', 'includes', 'for', 'admin_only'], array_column($form->firstWhere('id', 'tickets')['fields'], 'id'));

        $tickets = collect($event->paid_settings['tickets'])->keyBy('name');
        $this->assertSame(['Guest pass', 'Child', 'Crew'], $tickets->keys()->all());
        $products = $tickets->map(fn ($t) => Product::query()->find($t['product_id']));
        $this->assertSame(['ticket', 'ticket', 'ticket'], $products->pluck('type')->values()->all());
        $this->assertSame(['600.00', '0.00', '0.00'], $products->pluck('price')->values()->all());
        $this->assertTrue($products['Crew']->isPrivate());
        $this->assertSame([EventProducts::REF_TYPE, $event->id], [$products['Guest pass']->ref_type, (int) $products['Guest pass']->ref_id]);
        // No membership or bundle was made by the event.
        $this->assertSame(0, Product::query()->where('ref_type', EventProducts::REF_TYPE)->whereIn('type', ['membership', 'bundle', 'donation'])->count());

        // People pick: the public tickets and commerce's bundle with the pass inside; admins also Crew.
        $this->assertSame(['Guest pass', 'Child', 'Alumnus pass'], array_column(EventProducts::tickets($event), 'name'));
        $this->assertSame(['Guest pass', 'Child', 'Crew', 'Alumnus pass'], array_column(EventProducts::tickets($event, true), 'name'));
        $this->assertNotNull(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($event))->first());

        // Edit: change a price, drop the child ticket, add Early bird with a limit.
        $rows = $tickets->except('Child')->map(fn ($t) => ['product_id' => $t['product_id'], 'name' => $t['name'], 'price' => $t['name'] === 'Guest pass' ? 650 : $t['price'], 'admin_only' => $t['admin_only']])->values()->all();
        $rows[] = ['name' => 'Early bird', 'price' => 500, 'limit' => 50];
        $this->actingAs($admin)->putJson('/events/manage/'.$event->uid, [
            'organisation_id' => $organisation->id, 'title' => '4th Alumni Meet', 'starts_at' => $event->starts_at->format('Y-m-d\TH:i'),
            'status' => 'published', 'visibility' => 'members', 'venue' => 'School auditorium', 'is_paid' => true, 'tickets' => $rows,
        ])->assertOk();

        $this->assertSame('650.00', Product::query()->find($tickets['Guest pass']['product_id'])->price);
        $this->assertSame(Product::STATUS_INACTIVE, Product::query()->find($tickets['Child']['product_id'])->status);
        $early = Product::query()->find(collect($event->fresh()->paid_settings['tickets'])->firstWhere('name', 'Early bird')['product_id']);
        $this->assertSame([true, 50], [$early->track_stock, $early->stock_quantity]);

        // The public page says only whether tickets are left: names and prices after signing in.
        $event->forceFill(['visibility' => Event::VISIBILITY_PUBLIC])->save();
        $this->get('/e/'.$event->uid)->assertOk()->assertSee('Tickets available')->assertDontSee('Early bird')->assertDontSee('₹650');
        // When every ticket is limited: few left, then sold out.
        Product::query()->whereIn('id', collect($event->fresh()->paid_settings['tickets'])->pluck('product_id'))->update(['track_stock' => true, 'stock_quantity' => 5, 'stock_reserved' => 0]);
        $this->get('/e/'.$event->uid)->assertOk()->assertSeeText('tickets left');
        Product::query()->whereIn('id', collect($event->fresh()->paid_settings['tickets'])->pluck('product_id'))->update(['stock_reserved' => 5]);
        $this->get('/e/'.$event->uid)->assertOk()->assertSee('Sold out');
    }

    public function test_a_donation_follows_the_donation_products_limits(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);
        $field = fn () => collect(json_decode(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($event))->value('schema'), true)['fields'])->firstWhere('id', 'donation');

        // The donation product's minimum (₹1 here): no negative, no zero.
        $this->assertSame(1, (int) $field()['min']);
        $this->register($event, $member, ['donation' => -50])->assertStatus(422)->assertJsonValidationErrors('donation');
        $this->register($event, $member, ['donation' => 0])->assertStatus(422)->assertJsonValidationErrors('donation');

        // A minimum of ₹100 and a maximum of ₹5,000 set on the product: the form and the server follow them.
        Product::query()->where('type', 'donation')->where('organisation_id', $organisation->id)->update(['min_amount' => 100, 'max_amount' => 5000]);
        EventForms::syncPaid($event->fresh('organisation'));
        $this->assertSame([100, 5000], [(int) $field()['min'], (int) $field()['max']]);
        $this->assertStringContainsString('₹100 to ₹5,000', $field()['helperText']);
        $this->register($event, $member, ['donation' => 50])->assertStatus(422)->assertJsonPath('errors.donation.0', 'A donation is ₹100 to ₹5,000 (or leave it empty).');
        $this->register($event, $member, ['donation' => 6000])->assertStatus(422)->assertJsonValidationErrors('donation');
        $this->register($event, $member, ['donation' => 150])->assertOk();
    }

    public function test_asking_for_a_donation_makes_the_organisations_donation_product_once(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        $member = $this->memberOf($organisation);
        $base = ['organisation_id' => $organisation->id, 'title' => 'Picnic', 'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'), 'status' => 'published', 'visibility' => 'members', 'venue' => 'Park', 'registration_open' => true,
            'is_paid' => true, 'tickets' => [['name' => 'Pass', 'price' => 300, 'for' => 'everyone']]];

        // Not asked: no donation product made; a donation is refused.
        $this->actingAs($admin)->postJson('/events/manage', $base)->assertCreated();
        $quiet = Event::query()->latest('id')->firstOrFail();
        $this->assertSame(0, Product::query()->where('type', 'donation')->count());
        $this->actingAs($member)->postJson('/events/'.$quiet->uid.'/pay-register', ['name' => 'A', 'email' => 'a@example.test', 'phone' => '9800000001', 'gender' => 'male', 'meal' => 'veg', 'accept_terms' => true, 'donation' => 50])
            ->assertStatus(422)->assertJsonPath('message', 'This event takes no donations.');

        // Asked, and the organisation has none: one is made (the organisation's), and reused by the next event.
        $this->actingAs($admin)->postJson('/events/manage', array_merge($base, ['title' => 'Gala', 'ask_donation' => true]))->assertCreated();
        $this->actingAs($admin)->postJson('/events/manage', array_merge($base, ['title' => 'Quiz', 'ask_donation' => true]))->assertCreated();
        $donation = Product::query()->where('type', 'donation')->sole();
        $this->assertSame(['Donation', true, '1.00', 'organisation'], [$donation->name, $donation->custom_amount, $donation->min_amount, $donation->ref_type]);
        $gala = Event::query()->where('title', 'Gala')->firstOrFail();
        $this->assertTrue(collect(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($gala))->value('schema') ? json_decode(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($gala))->value('schema'), true)['fields'] : [])->contains('id', 'donation'));
        $this->assertFalse(collect(json_decode(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($quiet))->value('schema'), true)['fields'])->contains('id', 'donation'));

        // The organisation offers its donation with every paid order: the event that didn't ask offers it too.
        $donation->setMetaValue(Product::META_OFFER_WITH_EVERY_ORDER, '1');
        $donation->touch();
        $this->assertTrue(collect(json_decode(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($quiet))->value('schema'), true)['fields'])->contains('id', 'donation'));
        $this->actingAs($member)->postJson('/events/'.$quiet->uid.'/pay-register', ['name' => 'A', 'email' => 'a@example.test', 'phone' => '9800000001', 'gender' => 'male', 'meal' => 'veg', 'accept_terms' => true, 'donation' => 50])
            ->assertOk();
        $this->assertSame('350.00', EventRegistration::query()->where('event_id', $quiet->id)->sole()->order->total);
    }

    public function test_a_bundle_on_sale_later_joins_the_form_when_its_sale_starts(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);
        // The membership bundle starts selling in 2 days.
        Product::query()->where('name', 'Alumnus pass')->update(['sale_from' => now()->addDays(2)]);
        $guestFields = fn () => collect(collect(json_decode(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($event))->value('schema'), true)['fields'])->firstWhere('id', 'guests')['fields'])->pluck('id')->all();

        $this->actingAs($member)->get('/events/'.$event->uid)->assertOk();
        $this->assertNotContains('member', $guestFields());

        // Its sale has started: the next page view has the membership questions, with nothing saved meanwhile.
        $this->travel(3)->days();
        $this->actingAs($member)->get('/events/'.$event->uid)->assertOk();
        $this->assertContains('member', $guestFields());
    }

    public function test_a_paid_event_needs_tickets_with_names_and_prices(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        $base = ['organisation_id' => $organisation->id, 'title' => 'Meet', 'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'), 'status' => 'published', 'visibility' => 'members', 'venue' => 'Hall', 'is_paid' => true];

        $this->actingAs($admin)->postJson('/events/manage', $base)->assertStatus(422)->assertJsonValidationErrors('tickets');
        $this->actingAs($admin)->postJson('/events/manage', $base + ['tickets' => [['name' => 'Pass']]])->assertStatus(422)->assertJsonValidationErrors('tickets.0.price');

        $this->actingAs($admin)->postJson('/events/manage', $base + ['tickets' => [['name' => 'Pass', 'price' => 600], ['name' => 'pass', 'price' => 0]]])
            ->assertStatus(422)->assertJsonValidationErrors('tickets.1.name');

        // A free event needs none; places only when limited.
        $this->actingAs($admin)->postJson('/events/manage', array_merge($base, ['is_paid' => false, 'limit_places' => true]))->assertStatus(422)->assertJsonValidationErrors('capacity');
        $this->actingAs($admin)->postJson('/events/manage', array_merge($base, ['is_paid' => false, 'limit_places' => false, 'capacity' => 99]))->assertCreated();
        $this->assertNull(Event::query()->latest('id')->first()->capacity);
    }

    public function test_each_person_picks_a_ticket_and_one_order_holds_everyone(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);

        $this->register($event, $member, [
            'guests' => [
                // No ticket chosen: a member adult → the pass with membership, a child → Child, another adult → Guest pass.
                ['name' => 'Priya Sen', 'member' => true, 'gender' => 'female', 'age_group' => 'adult', 'meal' => 'non_veg', 'email' => 'priya@example.test', 'phone_code' => '+44', 'phone' => '7700 900123'],
                ['name' => 'Tutun', 'gender' => 'female', 'age_group' => 'child', 'meal' => 'veg'],
                ['name' => 'Amit', 'gender' => 'male', 'age_group' => 'adult', 'meal' => 'veg', 'phone' => '9800000003'],
            ],
            'donation' => 100,
        ])->assertOk()->assertJsonPath('success', true);

        $registration = EventRegistration::query()->with('order.lines')->sole();
        $order = $registration->order;
        $this->assertSame(EventRegistration::STATUS_AWAITING_PAYMENT, $registration->status);
        $this->assertSame([4, 3, 1, 3, 1], [1 + $registration->guests, $registration->adults, $registration->children, $registration->veg, $registration->non_veg]);
        $this->assertSame(['Alumnus pass', 'Alumnus pass', 'Child', 'Guest pass'], array_column($registration->people, 'ticket'));
        // Mobile numbers keep their country code (default +91).
        $this->assertSame(['+91 9800000001', '+44 7700 900123'], [$registration->people[0]['phone'], $registration->people[1]['phone']]);
        // The bundle expands in the order: the event's pass and the membership for each alumnus.
        $this->assertSame(2, $order->lines->where('product_type', 'membership')->count());
        $this->assertSame('TTV-MEET-0001', $order->reference);
        // 2 × Alumnus ₹600 (each with a membership), Child ₹0, Guest ₹600, donation ₹100.
        $this->assertSame('1900.00', $order->total);
        $memberships = $order->lines->where('product_type', 'membership');
        $this->assertSame(['Rahul Sen', 'Priya Sen'], $memberships->map(fn ($l) => $l->meta['person'])->values()->all());
        $this->assertSame('priya@example.test', $memberships->last()->meta['email']);
        // The member form asks no "are you a member?" of the registrant (they join); guests are asked.
        $fields = collect(json_decode(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($event))->value('schema'), true)['fields']);
        $this->assertFalse($fields->contains('id', 'member'));
        $this->assertTrue(collect($fields->firstWhere('id', 'guests')['fields'])->contains('id', 'member'));
        // No Ticket list (no choice); the form says what they get and its price.
        $this->assertFalse($fields->contains('id', 'ticket'));
        $this->assertStringContainsString('Your ticket: Alumnus pass · ₹600', $fields->firstWhere('id', 'you')['description']);

        // Without "Registration includes membership": a plain ticketed event, even with the bundle there.
        $plain = $event->paid_settings;
        $plain['with_membership'] = false;
        $event->forceFill(['paid_settings' => $plain])->save();
        EventForms::syncPaid($event->fresh('organisation'));
        $fields = collect(json_decode(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($event))->value('schema'), true)['fields']);
        $this->assertFalse(collect($fields->firstWhere('id', 'guests')['fields'])->contains('id', 'member'));
        $this->assertStringContainsString('Your ticket: Guest pass · ₹600', $fields->firstWhere('id', 'you')['description']);
        $other = $this->memberOf($organisation);
        $this->actingAs($other)->postJson('/events/'.$event->uid.'/pay-register', ['name' => 'B', 'email' => 'b@example.test', 'phone' => '9800000002', 'gender' => 'male', 'meal' => 'veg', 'accept_terms' => true])->assertOk();
        $this->assertSame(0, EventRegistration::query()->where('user_id', $other->id)->sole()->order->lines->where('product_type', 'membership')->count());
        // Nothing about approving memberships: community's setting.
        $this->assertArrayNotHasKey('approval', $memberships->first()->meta);

        // Registering again goes to the same registration.
        $this->register($event, $member)->assertOk()->assertJsonPath('message', "You're already registered.");
        $this->assertSame(1, EventRegistration::query()->where('user_id', $member->id)->count());
    }

    public function test_the_form_shows_the_total_before_the_terms_and_adult_guests_give_a_mobile(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);

        // As filled in: you (with membership), a member guest, a child, an unnamed adult, ₹50.
        $this->actingAs($member)->postJson('/events/'.$event->uid.'/pay-quote', [
            'name' => 'Rahul Sen',
            'guests' => [
                ['name' => 'Priya', 'member' => true, 'age_group' => 'adult', 'email' => 'priya@example.test'],
                ['name' => 'Tutun', 'age_group' => 'child', 'member' => true],
                ['age_group' => 'adult'],
            ],
            'donation' => 50,
        ])->assertOk()
            ->assertJsonPath('data.lines.0.ticket', 'Alumnus pass')
            ->assertJsonPath('data.lines.1.ticket', 'Alumnus pass')
            // A child is never a member: the children's ticket.
            ->assertJsonPath('data.lines.2.ticket', 'Child')
            ->assertJsonPath('data.lines.3.who', 'Guest 3')
            ->assertJsonPath('data.lines.3.ticket', 'Guest pass')
            ->assertJsonPath('data.donation', 50)
            ->assertJsonPath('data.total', 1850);

        // The form: the total sits just before the terms; guests are cards, the question is plain.
        $fields = collect(json_decode(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($event))->value('schema'), true)['fields']);
        $ids = $fields->pluck('id')->all();
        $this->assertSame(array_search('summary', $ids) + 1, array_search('agree', $ids));
        $guests = $fields->firstWhere('id', 'guests');
        $this->assertSame('Guest', $guests['rowLabel']);
        $guestFields = collect($guests['fields']);
        $this->assertSame('Joining as a member?', $guestFields->firstWhere('id', 'member')['label']);
        $this->assertTrue($guestFields->firstWhere('id', 'phone')['required']);

        // An adult guest without a mobile is refused; a child needs none.
        $this->register($event, $member, ['guests' => [['name' => 'Amit', 'gender' => 'male', 'age_group' => 'adult', 'meal' => 'veg']]])
            ->assertStatus(422)->assertJsonValidationErrors('guests.0.phone');
        // A member is an adult: "Child" goes away after Yes, and the server refuses a child member.
        $age = collect($guestFields->firstWhere('id', 'age_group')['options'])->firstWhere('value', 'child');
        $this->assertSame(['field' => 'member', 'equals' => false], $age['showWhen']);
        $this->register($event, $member, ['guests' => [['name' => 'Tutun', 'member' => true, 'gender' => 'female', 'age_group' => 'child', 'meal' => 'veg', 'email' => 'tutun@example.test']]])
            ->assertStatus(422)->assertJsonValidationErrors('guests.0.age_group');
        $this->register($event, $member, ['guests' => [['name' => 'Tutun', 'gender' => 'female', 'age_group' => 'child', 'meal' => 'veg']]])->assertOk();
    }

    public function test_the_membership_modules_details_reach_it_for_the_registrant_and_guests(): void
    {
        // A membership module (as community binds it): asks the year, keeps it with the membership.
        $this->app->instance(\Iquesters\Event\Contracts\MembershipDetails::class, new class implements \Iquesters\Event\Contracts\MembershipDetails
        {
            public function memberLabel(Event $event): string
            {
                return 'Alumnus';
            }

            public function fields(Event $event): array
            {
                return [['id' => 'school_year', 'label' => 'Year of passing', 'type' => 'number', 'required' => true]];
            }

            public function validate(Event $event, array $values): array
            {
                return empty($values['school_year']) ? [[], ['school_year' => 'Enter the year.']] : [['year' => (int) $values['school_year']], []];
            }

            public function prefill(Event $event, $user): array
            {
                return [];
            }
        });
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);

        // Missing: refused, on the right field.
        $this->register($event, $member)->assertStatus(422)->assertJsonValidationErrors('school_year');
        // Given (you and a member guest): accepted, and passed on with each membership.
        $this->register($event, $member, ['school_year' => 2020, 'guests' => [
            ['name' => 'Priya', 'member' => true, 'gender' => 'female', 'age_group' => 'adult', 'meal' => 'veg', 'email' => 'priya@example.test', 'phone' => '9800000004', 'school_year' => 2005],
        ]])->assertOk();
        $lines = EventRegistration::query()->sole()->order->lines->where('product_type', '!=', 'membership')->values();
        $this->assertSame([['year' => 2020], ['year' => 2005]], [$lines[0]->meta['member_details'], $lines[1]->meta['member_details']]);
    }

    public function test_a_ticket_with_membership_needs_an_email_and_not_a_membership_already_paid(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        $member = $this->memberOf($organisation);
        $event = $this->paidEvent($organisation, $admin);

        // A member guest needs an email (their membership is theirs).
        $this->register($event, $member, ['guests' => [['name' => 'Priya', 'member' => true, 'gender' => 'female', 'age_group' => 'adult', 'meal' => 'veg']]])
            ->assertStatus(422)->assertJsonValidationErrors('guests.0.email');
        // Admin-only tickets aren't for members.
        $this->register($event, $member, ['ticket' => $this->ticket($event, 'Crew')])->assertStatus(422)->assertJsonValidationErrors('ticket');

        $this->register($event, $member)->assertOk();
        app(OrderService::class)->markPaid(EventRegistration::query()->sole()->order, 'Verified', 1);

        // Another event with the same membership in its own bundle: Rahul has it already.
        $second = Event::create(['organisation_id' => $organisation->id, 'title' => 'Picnic', 'starts_at' => now()->addMonths(2), 'status' => Event::STATUS_PUBLISHED, 'visibility' => Event::VISIBILITY_MEMBERS, 'registration_open' => true, 'is_paid' => true]);
        $second->forceFill(['paid_settings' => ['with_membership' => true, 'tickets' => EventProducts::sync($second, [['name' => 'Guest pass', 'price' => 400, 'for' => 'adults']])]])->save();
        $membership = Product::query()->where('type', 'membership')->where('organisation_id', $organisation->id)->firstOrFail();
        $this->alumnusBundle($second, $membership, 'Picnic with membership');
        EventForms::syncPaid($second->fresh('organisation'));

        // Choosing the membership again is refused…
        $this->register($second, $member, ['ticket' => $this->ticket($second, 'Picnic with membership')])
            ->assertStatus(422)->assertJsonPath('message', 'Rahul Sen already has this membership: choose a ticket without it.');
        // …and left to the form, a member who already has it gets the plain pass.
        $this->register($second, $member)->assertOk();
        $order = EventRegistration::query()->where('event_id', $second->id)->sole()->order;
        $this->assertSame(['400.00', 0], [$order->total, $order->lines->where('product_type', 'membership')->count()]);
    }

    public function test_the_registration_follows_its_order(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $this->register($event, $this->memberOf($organisation))->assertOk();
        $registration = EventRegistration::query()->sole();
        $orders = app(OrderService::class);

        $orders->markUnderReview($registration->order, 'UTR 1');
        $this->assertSame(EventRegistration::STATUS_UNDER_REVIEW, $registration->fresh()->status);
        $orders->reject($registration->order->fresh(), 'Not found');
        $this->assertSame(EventRegistration::STATUS_AWAITING_PAYMENT, $registration->fresh()->status);
        $orders->markPaid($registration->order->fresh(), 'Verified', 1);
        $this->assertSame(EventRegistration::STATUS_REGISTERED, $registration->fresh()->status);
    }

    public function test_cancelling_an_unpaid_registration_cancels_its_order(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);
        $this->register($event, $member)->assertOk();

        $this->actingAs($member)->deleteJson('/events/'.$event->uid.'/register')->assertOk();

        $registration = EventRegistration::query()->sole();
        $this->assertSame(EventRegistration::STATUS_CANCELLED, $registration->status);
        $this->assertSame(Order::STATUS_CANCELLED, $registration->order->status);

        // They can register again (the same row is used).
        $this->register($event, $member)->assertOk();
        $this->assertSame(EventRegistration::STATUS_AWAITING_PAYMENT, $registration->fresh()->status);
    }

    public function test_terms_places_and_who_may_register(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'), ['limit_places' => true, 'capacity' => 2]);
        $member = $this->memberOf($organisation);
        $guest = fn ($name) => ['name' => $name, 'gender' => 'male', 'age_group' => 'adult', 'meal' => 'veg', 'phone' => '9800000009'];

        $this->register($event, $member, ['accept_terms' => false])->assertStatus(422)->assertJsonValidationErrors('accept_terms');
        $this->register($event, $member, ['guests' => [$guest('A'), $guest('B')]])->assertStatus(422)->assertJsonPath('message', 'Only 2 places are left.');
        $this->register($event, $member, ['ticket' => '999999'])->assertStatus(422)->assertJsonValidationErrors('ticket');
        $this->register($event, $member, ['phone_code' => '+999'])->assertStatus(422)->assertJsonValidationErrors('phone_code');
        $this->register($event, $member, ['phone' => '+91 98000'])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->register($event, $this->memberOf($this->makeOrganisation('Other school')))->assertNotFound();
        // The free registration path refuses a paid event.
        $this->actingAs($member)->postJson('/events/'.$event->uid.'/register', ['guests' => 0])->assertStatus(422);
    }

    public function test_an_admin_adds_crew_for_free_and_a_desk_attendee(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        $event = $this->paidEvent($organisation, $admin);
        $person = ['name' => 'Sound crew', 'phone' => '9800000009', 'gender' => 'male', 'meal' => 'non_veg'];

        $this->actingAs($admin)->postJson('/events/manage/'.$event->uid.'/attendees', $person + ['ticket' => $this->ticket($event, 'Crew')])->assertOk();
        $crew = EventRegistration::query()->sole();
        $this->assertSame([null, EventRegistration::STATUS_REGISTERED, 'admin', '0.00'], [$crew->user_id, $crew->status, $crew->source, $crew->order->total]);

        $this->actingAs($admin)->postJson('/events/manage/'.$event->uid.'/attendees', ['name' => 'Desk guest', 'ticket' => $this->ticket($event, 'Guest pass')] + $person)->assertOk();
        $desk = EventRegistration::query()->latest('id')->first();
        $this->assertSame([EventRegistration::STATUS_AWAITING_PAYMENT, '600.00'], [$desk->status, $desk->order->total]);

        // The page: the generic Attendees table, given this event's rows.
        $this->actingAs($admin)->get('/events/manage/'.$event->uid.'/attendees')->assertOk()
            ->assertSee('data-data-url="/events/manage/'.$event->uid.'/attendees/data"', false)->assertSee('lab-table', false);
        $this->assertSame(\Iquesters\Event\Support\EventTables::ATTENDEE_ROW, \Iquesters\UserInterface\Models\TableSchema::query()->where('slug', 'event-attendees')->sole()->schema['row_component']);
        // Its rows: newest first, a coloured status badge, the order; cancel only while not paid.
        $rows = collect($this->actingAs($admin)->getJson('/events/manage/'.$event->uid.'/attendees/data')->assertOk()->json('response_schema.data'));
        $this->assertSame(['Desk guest', 'Sound crew'], $rows->pluck('name')->all());
        $this->assertSame(['warning', 'Awaiting payment', 'TTV-MEET-0002', '1', 'Added by admin'], [$rows[0]['status_tone'], $rows[0]['status_label'], $rows[0]['reference'], $rows[0]['can_cancel'], $rows[0]['added_by_admin']]);
        $this->assertSame('success', $rows[1]['status_tone']);
        // Search, one record, the side panel.
        $this->assertSame(['Sound crew'], collect($this->actingAs($admin)->getJson('/events/manage/'.$event->uid.'/attendees/data?search=sound')->json('response_schema.data'))->pluck('name')->all());
        $this->actingAs($admin)->getJson('/events/manage/'.$event->uid.'/attendees/data/'.$desk->uid)->assertOk()->assertJsonPath('response_schema.data.name', 'Desk guest');
        $this->actingAs($admin)->get('/events/manage/registrations/'.$desk->uid.'/summary')->assertOk()->assertSee('Desk guest')->assertSee('Guest pass');
        // Not for members.
        $this->actingAs($this->memberOf($organisation))->getJson('/events/manage/'.$event->uid.'/attendees/data')->assertForbidden();
        // The organisation's own terms (a page of its own): the attendees page still opens
        // (it rebuilt the form with the organisation loaded without its uid: an error).
        $organisation->setMetaValue('terms_text', 'Our terms.');
        $this->actingAs($admin)->get('/events/manage/'.$event->uid.'/attendees')->assertOk();
        $csv = $this->actingAs($admin)->get('/events/manage/'.$event->uid.'/attendees.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Crew', $csv);
        $this->actingAs($admin)->get('/events/manage/registrations/'.$desk->uid)->assertOk()->assertSee('Desk guest')->assertSee('Guest pass');
        $this->actingAs($admin)->postJson('/events/manage/registrations/'.$desk->uid.'/cancel')->assertOk();
        $this->assertSame(EventRegistration::STATUS_CANCELLED, $desk->fresh()->status);

        // Members can't add attendees.
        $this->actingAs($this->memberOf($organisation))->postJson('/events/manage/'.$event->uid.'/attendees', $person)->assertForbidden();
    }

    public function test_the_event_page_shows_the_tickets_the_form_then_the_status(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);

        $this->actingAs($member)->get('/events/'.$event->uid)->assertOk()
            // What comes with a ticket; the bundle isn't a second ₹600 line but a note on its ticket.
            ->assertSee('Includes Lunch, T-shirt')->assertSee('Membership 2027 included when you join')
            ->assertDontSee('Includes 4th Alumni Meet: Guest pass + Membership 2027')
            ->assertSee('₹600')->assertSee('Child')->assertDontSee('Crew')
            ->assertSee(EventForms::paidRegistrationSlug($event));
        $this->assertCount(2, EventPaidRegistrar::prices($event->fresh('organisation')));
        // A bundle dearer than its ticket: its price on the ticket's line (the public page too).
        Product::query()->where('type', 'bundle')->where('organisation_id', $organisation->id)->update(['price' => 900]);
        $event->forceFill(['visibility' => Event::VISIBILITY_PUBLIC])->save();
        $this->actingAs($member)->get('/events/'.$event->uid)->assertOk()->assertSee('With Membership 2027: ₹900');
        // Not on the public page (prices after signing in).
        auth()->logout();
        $this->get('/e/'.$event->uid)->assertOk()->assertDontSee('₹900')->assertDontSee('With Membership 2027');
        $this->register($event, $member)->assertOk();
        $this->actingAs($member)->get('/events/'.$event->uid)->assertOk()->assertSee('waiting for your payment')->assertSee('TTV-MEET-0001');
    }
}
