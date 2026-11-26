<?php

namespace Iquesters\Event\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventForms;
use Iquesters\Event\Tests\TestCase;
use Iquesters\Organisation\Models\Organisation;

/** Paid registration: products from the event, one order per registration, guests, meals, statuses, admins. */
class EventPaidRegistrationTest extends TestCase
{
    /** The TTV meet as an org-admin saves it: pass ₹600, alumni ₹600 with the 2027 membership (₹300 alone). */
    private function paidEvent(Organisation $organisation, $admin, array $extra = []): Event
    {
        $this->actingAs($admin)->postJson('/events/manage', $extra + [
            'organisation_id' => $organisation->id,
            'title' => '4th Alumni Meet',
            'starts_at' => now()->addMonth()->setTime(10, 0)->format('Y-m-d\TH:i'),
            'status' => 'published',
            'visibility' => 'members',
            'registration_open' => true,
            'is_paid' => true,
            'pass_price' => 600,
            'bundle_price' => 600,
            'membership_price' => 300,
            'membership_from' => '2027-01-01',
            'membership_until' => '2027-12-31',
            'free_child_age' => 5,
            'reference_prefix' => 'ttv meet',
            'donation' => true,
            'approval' => 'together',
        ])->assertCreated();

        return Event::query()->latest('id')->firstOrFail();
    }

    private function register(Event $event, $user, array $data = [])
    {
        return $this->actingAs($user)->postJson('/events/'.$event->uid.'/pay-register', $data + [
            'name' => 'Rahul Sen',
            'phone' => '9800000001',
            'email' => 'rahul@example.test',
            'gender' => 'male',
            'meal' => 'veg',
            'alumnus' => true,
            'accept_terms' => true,
        ]);
    }

    public function test_saving_a_paid_event_makes_its_products(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));

        $this->assertSame('TTV-MEET', $event->reference_prefix);
        $products = collect($event->paid_settings['products'])->map(fn ($id) => Product::query()->find($id));
        $this->assertSame(['pass', 'child', 'crew', 'membership', 'bundle', 'donation'], $products->keys()->all());
        $this->assertSame(['600.00', '0.00', '0.00', '300.00', '600.00'], $products->except('donation')->map(fn ($p) => $p->price)->values()->all());
        $this->assertTrue($products['crew']->isPrivate());
        $this->assertSame(['2027-01-01', '2027-12-31'], [$products['membership']->valid_from->toDateString(), $products['membership']->valid_until->toDateString()]);
        $this->assertSame([['600.00'], ['0.00']], $products['bundle']->bundleItems()->orderBy('sort')->get()->map(fn ($i) => [$i->price_in_bundle])->all());
        $this->assertNotNull(DB::table('form_schemas')->where('slug', EventForms::paidRegistrationSlug($event))->first());

        // A second event in the same period reuses the organisation's membership.
        $second = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'), ['title' => 'Winter picnic']);
        $this->assertSame($event->productId('membership'), $second->productId('membership'));
    }

    public function test_a_member_registers_with_guests_and_one_order_holds_everyone(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);

        $this->register($event, $member, [
            'guests' => [
                ['name' => 'Priya Sen', 'gender' => 'female', 'age_group' => 'adult', 'meal' => 'non_veg', 'alumnus' => true, 'email' => 'priya@example.test'],
                ['name' => 'Tutun', 'gender' => 'female', 'age_group' => 'child', 'meal' => 'veg'],
                ['name' => 'Amit', 'gender' => 'male', 'age_group' => 'adult', 'meal' => 'veg'],
            ],
            'donation' => 100,
        ])->assertOk()->assertJsonPath('success', true);

        $registration = EventRegistration::query()->with('order.lines')->sole();
        $order = $registration->order;
        $this->assertSame(EventRegistration::STATUS_AWAITING_PAYMENT, $registration->status);
        $this->assertSame([4, 3, 1, 3, 1], [1 + $registration->guests, $registration->adults, $registration->children, $registration->veg, $registration->non_veg]);
        $this->assertSame('TTV-MEET-0001', $order->reference);
        // 2 alumni × ₹600 (pass + membership each), a child ₹0, a guest ₹600, a donation ₹100.
        $this->assertSame('1900.00', $order->total);
        $this->assertSame(2, $order->lines->where('product_type', 'membership')->count());
        $this->assertSame(['Rahul Sen', 'Priya Sen'], $order->lines->where('product_type', 'membership')->map(fn ($l) => $l->meta['person'])->values()->all());
        $this->assertSame('priya@example.test', $order->lines->where('product_type', 'membership')->last()->meta['email']);
        $this->assertSame('together', $order->lines->where('product_type', 'membership')->first()->meta['approval']);

        // Registering again goes to the same registration.
        $this->register($event, $member)->assertOk()->assertJsonPath('message', "You're already registered.");
        $this->assertSame(1, EventRegistration::query()->count());
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

    public function test_someone_who_paid_the_membership_gets_the_plain_pass_next_time(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->memberOf($organisation, 'admin');
        $member = $this->memberOf($organisation);
        $first = $this->paidEvent($organisation, $admin);
        $this->register($first, $member)->assertOk();
        app(OrderService::class)->markPaid(EventRegistration::query()->sole()->order, 'Verified', 1);

        $second = $this->paidEvent($organisation, $admin, ['title' => 'Winter picnic', 'pass_price' => 400, 'bundle_price' => 700]);
        $this->register($second, $member)->assertOk();

        $order = EventRegistration::query()->where('event_id', $second->id)->sole()->order;
        $this->assertSame('400.00', $order->total);
        $this->assertSame(0, $order->lines->where('product_type', 'membership')->count());
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
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'), ['capacity' => 2]);
        $member = $this->memberOf($organisation);

        $this->register($event, $member, ['accept_terms' => false])->assertStatus(422)->assertJsonValidationErrors('accept_terms');
        $this->register($event, $member, ['guests' => [
            ['name' => 'A', 'gender' => 'male', 'age_group' => 'adult', 'meal' => 'veg'],
            ['name' => 'B', 'gender' => 'male', 'age_group' => 'adult', 'meal' => 'veg'],
        ]])->assertStatus(422)->assertJsonPath('message', 'Only 2 places are left.');
        // An alumnus guest needs an email.
        $this->register($event, $member, ['guests' => [['name' => 'A', 'gender' => 'male', 'age_group' => 'adult', 'meal' => 'veg', 'alumnus' => true]]])
            ->assertStatus(422)->assertJsonValidationErrors('guests.0.email');

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

        $this->actingAs($admin)->postJson('/events/manage/'.$event->uid.'/attendees', $person + ['crew' => true])->assertOk();
        $crew = EventRegistration::query()->sole();
        $this->assertSame([null, EventRegistration::STATUS_REGISTERED, 'admin', '0.00'], [$crew->user_id, $crew->status, $crew->source, $crew->order->total]);

        $this->actingAs($admin)->postJson('/events/manage/'.$event->uid.'/attendees', ['name' => 'Desk guest'] + $person)->assertOk();
        $desk = EventRegistration::query()->latest('id')->first();
        $this->assertSame([EventRegistration::STATUS_AWAITING_PAYMENT, '600.00'], [$desk->status, $desk->order->total]);

        $this->actingAs($admin)->get('/events/manage/'.$event->uid.'/attendees')->assertOk()->assertSee('Sound crew')->assertSee('TTV-MEET-0002');
        $this->actingAs($admin)->get('/events/manage/'.$event->uid.'/attendees.csv')->assertOk();
        $this->actingAs($admin)->get('/events/manage/registrations/'.$desk->uid)->assertOk()->assertSee('Desk guest');
        $this->actingAs($admin)->postJson('/events/manage/registrations/'.$desk->uid.'/cancel')->assertOk();
        $this->assertSame(EventRegistration::STATUS_CANCELLED, $desk->fresh()->status);

        // Members can't add attendees.
        $this->actingAs($this->memberOf($organisation))->postJson('/events/manage/'.$event->uid.'/attendees', $person)->assertForbidden();
    }

    public function test_the_event_page_shows_the_form_then_the_status(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->paidEvent($organisation, $this->memberOf($organisation, 'admin'));
        $member = $this->memberOf($organisation);

        $this->actingAs($member)->get('/events/'.$event->uid)->assertOk()->assertSee('Alumnus (pass with membership)')->assertSee(EventForms::paidRegistrationSlug($event));
        $this->register($event, $member)->assertOk();
        $this->actingAs($member)->get('/events/'.$event->uid)->assertOk()->assertSee('waiting for your payment')->assertSee('TTV-MEET-0001');
    }
}
