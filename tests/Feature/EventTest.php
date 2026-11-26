<?php

namespace Iquesters\Event\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_in_person_needs_a_venue_and_online_a_link(): void
    {
        $organisation = $this->makeOrganisation();
        $this->actingAs($this->memberOf($organisation, 'admin'));
        $base = ['organisation_id' => $organisation->id, 'title' => 'Talk', 'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'), 'visibility' => 'members', 'status' => 'draft'];

        $this->postJson('/events/manage', $base + ['location_type' => 'in_person'])->assertStatus(422)->assertJsonValidationErrors('venue');
        $this->postJson('/events/manage', $base + ['location_type' => 'online'])->assertStatus(422)->assertJsonValidationErrors('online_url');

        // Online: the venue and address typed earlier are dropped.
        $this->postJson('/events/manage', $base + ['location_type' => 'online', 'online_url' => 'https://meet.example.com/x', 'venue' => 'Hall', 'address' => 'Somewhere'])->assertCreated();
        $event = Event::query()->latest('id')->firstOrFail();
        $this->assertSame(['online', null, null, 'https://meet.example.com/x'], [$event->location_type, $event->venue, $event->address, $event->online_url]);
        $this->assertTrue($event->isOnline());

        // In person: the link is dropped.
        $this->putJson('/events/manage/'.$event->uid, $base + ['location_type' => 'in_person', 'venue' => 'Hall', 'online_url' => 'https://meet.example.com/x'])->assertOk();
        $this->assertSame(['in_person', 'Hall', null], [$event->fresh()->location_type, $event->fresh()->venue, $event->fresh()->online_url]);
    }

    public function test_the_page_look_cover_and_colour(): void
    {
        Storage::fake('public');
        $organisation = $this->makeOrganisation();
        $this->actingAs($this->memberOf($organisation, 'admin'));
        $base = ['organisation_id' => $organisation->id, 'title' => 'Gala', 'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'), 'visibility' => 'public', 'status' => 'published', 'venue' => 'Hall'];

        // A cover image is sent as multipart (the form engine does this when a file is chosen).
        $this->post('/events/manage', $base + ['theme' => '#E11D48', 'maps_url' => 'https://www.google.com/maps/place/Hall/@22.57,88.36,17z', 'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 800)], ['Accept' => 'application/json'])->assertCreated();
        $event = Event::query()->latest('id')->firstOrFail();
        // Any colour (kept as #rrggbb).
        $this->assertSame('#e11d48', $event->theme);
        Storage::disk('public')->assertExists($event->cover_image);
        $this->get('/e/'.$event->uid)->assertOk()->assertSee($event->coverUrl(), false)->assertSee('--theme-from: #e11d48', false)
            // The Google Maps link: the map there, and "Open in Maps".
            ->assertSee('https://maps.google.com/maps?q=22.57%2C88.36&amp;z=16&amp;output=embed', false)->assertSee('Open in Maps')
            // The app's tab icon, and Share instead of Sign in.
            ->assertSee('rel="icon"', false)->assertSee('id="share-event"', false)->assertDontSee('>Sign in<', false);

        // A short share link is followed to its place (once, remembered): the map pins that place, not the typed address.
        \Illuminate\Support\Facades\Http::fake(['maps.app.goo.gl/*' => \Illuminate\Support\Facades\Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Tribeni+Tissues+Vidyapith/@23.0034738,88.4069033,17z/data=!3m1!4b1!4m6!3m5!8m2!3d23.0034738!4d88.4069033'])]);
        $event->forceFill(['maps_url' => 'https://maps.app.goo.gl/UFSwP3ciwRVW2k4v8'])->save();
        $this->assertSame('https://maps.google.com/maps?q=23.0034738%2C88.4069033&z=16&output=embed', $event->fresh()->mapEmbedUrl());
        $event->fresh()->mapEmbedUrl();
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertSame(['coords' => '22.5,88.3'], Event::mapsPlace('https://maps.google.com/?q=22.5,88.3'));
        $this->assertSame(['name' => 'School Hall'], Event::mapsPlace('https://www.google.com/maps/search/?api=1&query=School+Hall'));
        $event->forceFill(['maps_url' => 'https://www.google.com/maps/place/Hall/@22.57,88.36,17z'])->save();

        // Remove it: the drawn cover again.
        $old = $event->cover_image;
        $this->putJson('/events/manage/'.$event->uid, $base + ['remove_cover' => true])->assertOk();
        $this->assertNull($event->fresh()->cover_image);
        Storage::disk('public')->assertMissing($old);
        $this->putJson('/events/manage/'.$event->uid, $base + ['theme' => 'neon'])->assertStatus(422)->assertJsonValidationErrors('theme');
        // Online: the map link is dropped.
        $this->putJson('/events/manage/'.$event->uid, array_merge($base, ['location_type' => 'online', 'online_url' => 'https://meet.example.com/x', 'maps_url' => 'https://maps.app.goo.gl/x']))->assertOk();
        $this->assertNull($event->fresh()->maps_url);
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
        $this->get('/e/'.$event->uid)->assertNotFound();
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

        // The page lives at its uid; an old address by name redirects there.
        $this->get('/e/'.$event->uid)->assertOk()->assertSee('Annual Reunion')->assertSee('Open to all')
            ->assertSee('Hosted by')->assertSee('Register')
            // No cover image: one is drawn in the theme's colours.
            ->assertSee('linear-gradient(135deg, #4f46e5, #7b1dd0)', false)
            // No Google Maps link given: no map, no "Open in Maps".
            ->assertDontSee('Open in Maps')->assertDontSee('output=embed', false);
        $this->get('/e/'.$event->slug)->assertRedirect('/e/'.$event->uid)->assertStatus(301);

        // "Register" leads to the form after signing in or up (?register=1 survives the sign-in)…
        $this->get('/e/'.$event->uid)->assertSee(route('events.show', ['uid' => $event->uid, 'register' => 1]), false);
        // …and the visitor's organisation is remembered (e.g. the membership page then has it chosen).
        $this->assertSame($organisation->uid, session(\Iquesters\Organisation\Support\OrganisationContext::CAME_FROM_KEY));

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
        $this->get("/events/manage/{$event->uid}/attendees")->assertOk()->assertSeeInOrder(['People', '2']);
        // The names: the Attendees table's rows.
        $this->getJson("/events/manage/{$event->uid}/attendees/data")->assertOk()->assertJsonPath('response_schema.data.0.name', $member->name);

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
