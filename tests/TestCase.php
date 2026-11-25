<?php

namespace Iquesters\Event\Tests;

use Diglactic\Breadcrumbs\Breadcrumbs;
use Diglactic\Breadcrumbs\ServiceProvider as BreadcrumbsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Iquesters\Event\EventServiceProvider;
use Iquesters\Event\Models\Event;
use Iquesters\Event\Tests\Fixtures\User;
use Iquesters\Foundation\FoundationServiceProvider;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\OrganisationServiceProvider;
use Iquesters\Organisation\Support\OrganisationRoles;
use Iquesters\UserInterface\UserInterfaceServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Settings are cached before the test's transaction starts: start clean.
        Cache::flush();
        ConfProvider::forget();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            SanctumServiceProvider::class,
            BreadcrumbsServiceProvider::class,
            FoundationServiceProvider::class,
            UserInterfaceServiceProvider::class,
            OrganisationServiceProvider::class,
            EventServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return ['Breadcrumbs' => Breadcrumbs::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('permission.cache.store', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function makeUser(?string $role = null): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(6),
            'status' => 'active',
            'email' => Str::random(10).'@example.test',
            'password' => bcrypt(Str::random(20)),
        ]);

        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
    }

    protected function makeOrganisation(string $name = 'TTVians'): Organisation
    {
        return Organisation::create([
            'uid' => (string) Str::ulid(),
            'name' => $name,
            'description' => "{$name} organisation",
            'status' => 'active',
        ]);
    }

    /** A user of the given role who belongs to the organisation (an "admin" member is its admin). */
    protected function memberOf(Organisation $organisation, string $role = 'user'): User
    {
        $user = $this->makeUser($role);
        // An "admin" member is org-admin there (per-organisation role); anyone else a member.
        OrganisationRoles::join($user, $organisation, $role === 'admin' ? 'org-admin' : 'member');

        return $user;
    }

    protected function makeEvent(Organisation $organisation, array $attributes = []): Event
    {
        return Event::create($attributes + [
            'organisation_id' => $organisation->id,
            'title' => 'Annual Reunion',
            'starts_at' => now()->addWeek()->setTime(18, 0),
            'status' => Event::STATUS_PUBLISHED,
            'visibility' => Event::VISIBILITY_MEMBERS,
            'registration_open' => true,
        ]);
    }
}
