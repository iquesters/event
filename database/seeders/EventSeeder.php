<?php

namespace Iquesters\Event\Database\Seeders;

use Iquesters\Foundation\Database\Seeders\BaseSeeder;
use Iquesters\Foundation\Models\Module;
use Spatie\Permission\Models\Role;

class EventSeeder extends BaseSeeder
{
    /** Sees "Manage events" (data still limited to the organisations they manage). */
    public const MANAGE_PERMISSION = 'manage-events';

    /** Sees "Events". */
    public const USE_PERMISSION = 'use-events';

    /** Who sees the Events module (organisation admins use the "admin" role). */
    public const MODULE_ROLES = ['super-admin', 'admin', 'user', 'member'];

    protected string $moduleName = 'event';

    protected string $description = 'event module: organisations publish events and members register';

    protected array $metas = [
        'module_icon' => 'fas fa-calendar-days',
        'module_sidebar_menu' => [
            [
                'key' => 'events',
                'icon' => 'fas fa-calendar-check',
                'label' => 'Events',
                'route' => 'events.index',
                'permission' => self::USE_PERMISSION,
                'children' => [
                    ['key' => 'upcoming', 'icon' => 'fas fa-calendar-day', 'label' => 'Upcoming', 'route' => 'events.index'],
                    ['key' => 'past', 'icon' => 'fas fa-clock-rotate-left', 'label' => 'Past', 'route' => 'events.index', 'params' => ['when' => 'past']],
                ],
            ],
            [
                'key' => 'manage',
                'icon' => 'fas fa-calendar-plus',
                'label' => 'Manage events',
                'route' => 'events.manage.index',
                'permission' => self::MANAGE_PERMISSION,
            ],
        ],
    ];

    protected array $permissions = [
        self::MANAGE_PERMISSION,
        self::USE_PERMISSION,
    ];

    protected function seedCustom(): void
    {
        $roles = collect(self::MODULE_ROLES)->mapWithKeys(fn (string $name) => [$name => Role::findOrCreate($name, 'web')]);

        $roles['admin']->givePermissionTo([self::MANAGE_PERMISSION, self::USE_PERMISSION]);
        $roles['user']->givePermissionTo(self::USE_PERMISSION);
        $roles['member']->givePermissionTo(self::USE_PERMISSION);

        Module::where('name', $this->moduleName)->first()?->assignRoles($roles->pluck('id')->all());
    }
}
