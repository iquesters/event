<?php

namespace Iquesters\Event\Tests\Feature;

use Iquesters\Event\Database\Seeders\EventSeeder;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Models\EventRegistration;
use Iquesters\Event\Support\EventRegistrar;
use Iquesters\Event\Tests\TestCase;
use Iquesters\Foundation\Models\Module;

class EventTest extends TestCase
{
    public function test_an_organisation_admin_creates_and_publishes_an_event(): void
    {
        $organisation = $this->makeOrganisation();
        $this->actingAs($this->memberOf($organisation, 'admin'));

        // The event-details form's organisation options, then its JSON save.
        $this->getJson('/events/manage/options/organisations')->assertOk()->assertJsonPath('data.1.label', 'TTVians');
        $this->postJson('/events/manage', [
            'organisation_id' => $organisation->id,
            'title' => 'Annual Reunion 2026',
            'type' => 'Reunion',
            'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'venue' => 'School auditorium',
            'visibility' => 'members',
            'status' => 'published',
            'registration_open' => '1',
            'capacity' => 100,
        ])->assertCreated();

        $event = Event::firstOrFail();
        $this->assertSame('annual-reunion-2026', $event->slug);
        $this->assertSame(Event::STATUS_PUBLISHED, $event->status);
        // The Manage events table's rows (data_url).
        $this->getJson('/events/manage/data')->assertOk()->assertJsonPath('response_schema.data.0.title', 'Annual Reunion 2026');
    }

    public function test_admins_manage_only_their_organisations_events(): void
    {
        $mine = $this->makeOrganisation('Alpha');
        $other = $this->makeOrganisation('Beta');
        $theirs = $this->makeEvent($other, ['title' => 'Beta Gala']);
        $this->actingAs($this->memberOf($mine, 'admin'));

        $this->getJson('/events/manage/data')->assertOk()->assertJsonCount(0, 'response_schema.data');
        $this->get("/events/manage/{$theirs->uid}/edit")->assertNotFound();
        $this->postJson('/events/manage', [
            'organisation_id' => $other->id, 'title' => 'Sneaky', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'visibility' => 'members', 'status' => 'draft',
        ])->assertStatus(422)->assertJsonValidationErrors('organisation_id');

        $this->actingAs($this->memberOf($mine))->get('/events/manage')->assertForbidden();
    }

    public function test_members_see_published_events_and_register(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->makeEvent($organisation, ['capacity' => 10]);
        $this->makeEvent($organisation, ['title' => 'Secret Draft', 'status' => Event::STATUS_DRAFT]);
        $member = $this->memberOf($organisation);
        $this->actingAs($member);

        $this->get('/events')->assertOk()->assertSee('Annual Reunion')->assertDontSee('Secret Draft');
        $this->get("/events/{$event->uid}")->assertOk()->assertSee('10 of 10 places left');

        // The registration form (form schema) posts JSON.
        $this->postJson("/events/{$event->uid}/register", ['guests' => 2, 'note' => 'Veg'])->assertOk()->assertJsonPath('message', "You're registered.");
        $this->assertSame(7, $event->fresh()->seatsLeft());
        $this->postJson("/events/{$event->uid}/register")->assertStatus(422)->assertJsonPath('message', "You're already registered.");

        $this->deleteJson("/events/{$event->uid}/register")->assertOk();
        $this->assertSame(10, $event->fresh()->seatsLeft());

        // Registering again after cancelling reuses the registration.
        $this->postJson("/events/{$event->uid}/register")->assertOk();
        $this->assertSame(1, EventRegistration::count());
    }

    public function test_others_cannot_see_members_only_events(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->makeEvent($organisation);

        $this->actingAs($this->makeUser('user'))->get("/events/{$event->uid}")->assertNotFound();
        $this->get('/e/'.$event->slug)->assertNotFound();
    }

    public function test_capacity_deadline_and_cancellation_close_registration(): void
    {
        $organisation = $this->makeOrganisation();
        $registrar = app(EventRegistrar::class);

        $full = $this->makeEvent($organisation, ['title' => 'Small', 'capacity' => 2]);
        $registrar->register($this->memberOf($organisation), $full, 1);
        $this->expectExceptionMessageForRegistration(fn () => $registrar->register($this->memberOf($organisation), $full), 'This event is full.');

        $late = $this->makeEvent($organisation, ['title' => 'Late', 'registration_closes_at' => now()->subDay()]);
        $this->expectExceptionMessageForRegistration(fn () => $registrar->register($this->memberOf($organisation), $late), 'Registration closed on');

        $cancelled = $this->makeEvent($organisation, ['title' => 'Off', 'status' => Event::STATUS_CANCELLED]);
        $this->expectExceptionMessageForRegistration(fn () => $registrar->register($this->memberOf($organisation), $cancelled), 'This event was cancelled.');

        $small = $this->makeEvent($organisation, ['title' => 'Two left', 'capacity' => 2]);
        $this->expectExceptionMessageForRegistration(fn () => $registrar->register($this->memberOf($organisation), $small, 2), 'Only 2 places are left.');
    }

    public function test_public_events_have_a_page_and_anyone_signed_in_may_register(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->makeEvent($organisation, ['visibility' => Event::VISIBILITY_PUBLIC, 'description' => 'Open to all']);

        $this->get('/e/'.$event->slug)->assertOk()->assertSee('Annual Reunion')->assertSee('Open to all');

        $outsider = $this->makeUser('user');
        $this->actingAs($outsider)->postJson("/events/{$event->uid}/register")->assertOk();
    }

    public function test_attendees_and_csv_export(): void
    {
        $organisation = $this->makeOrganisation();
        $event = $this->makeEvent($organisation);
        $member = $this->memberOf($organisation);
        app(EventRegistrar::class)->register($member, $event, 1, 'Veg');

        $this->actingAs($this->memberOf($organisation, 'admin'));
        $this->get("/events/manage/{$event->uid}/attendees")->assertOk()->assertSee($member->name)->assertSeeInOrder(['People', '2']);

        $csv = $this->get("/events/manage/{$event->uid}/attendees.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString($member->email, $csv);
        $this->assertStringContainsString('Veg', $csv);
    }

    public function test_the_seeder_gives_each_role_its_part(): void
    {
        $this->seed(EventSeeder::class);

        $module = Module::where('name', 'event')->firstOrFail();
        $this->assertEqualsCanonicalizing(['super-admin', 'admin', 'user', 'member'], $module->getAssignedRoles()->pluck('name')->all());
        $this->assertTrue($this->makeUser('user')->can(EventSeeder::USE_PERMISSION));
        $this->assertFalse($this->makeUser('user')->can(EventSeeder::MANAGE_PERMISSION));
        $this->assertTrue($this->makeUser('admin')->can(EventSeeder::MANAGE_PERMISSION));
    }

    private function expectExceptionMessageForRegistration(callable $register, string $message): void
    {
        try {
            $register();
            $this->fail("Expected: {$message}");
        } catch (\DomainException $e) {
            $this->assertStringStartsWith($message, $e->getMessage());
        }
    }
}
