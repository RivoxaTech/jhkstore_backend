<?php

namespace App\Domain\Auth;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may do what in the admin panel: the permission list, the three roles and what each role gets.
 * The matrix lives here (not in the database) so every install has the same, reviewable rules; `sync()` (run by the
 * RolesSeeder) writes it to the spatie tables and resets the roles to it. `super_admin` has every permission.
 *
 * Routes ask for a permission with the `admin.can:<permission>` middleware; the admin panel hides what an admin cannot do.
 */
class AdminAccess
{
    public const GUARD = 'admin';

    public const SUPER_ADMIN = 'super_admin';

    /** permission => what it allows */
    public const PERMISSIONS = [
        'dashboard.view' => 'See the dashboard',
        'catalog.view' => 'See categories, products and tags',
        'catalog.manage' => 'Create, edit and delete categories, products, tags and product pictures',
        'banners.view' => 'See banners',
        'banners.manage' => 'Create, edit, reorder and delete banners',
        'orders.view' => 'See orders',
        'orders.manage' => 'Change order status and write order notes',
        'settings.view' => 'See the store settings',
        'settings.manage' => 'Change the store settings, logo, footer and SEO',
        'admins.manage' => 'Manage admin users and their roles',
    ];

    /** role => [label, description, permissions] (`super_admin` always gets all of them) */
    public const ROLES = [
        self::SUPER_ADMIN => ['Super admin', 'Everything, including store settings and admin users.', []],
        'manager' => ['Manager', 'Runs the shop: catalogue, banners and orders. Can see but not change the store settings; cannot manage admin users.', [
            'dashboard.view', 'catalog.view', 'catalog.manage', 'banners.view', 'banners.manage', 'orders.view', 'orders.manage', 'settings.view',
        ]],
        'staff' => ['Staff', 'Handles orders and looks things up. Cannot change the catalogue, banners or settings.', [
            'dashboard.view', 'catalog.view', 'banners.view', 'orders.view', 'orders.manage',
        ]],
    ];

    /** Creates the permissions and roles (idempotent) and resets every role to the matrix above. */
    public static function sync(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }
        foreach (self::ROLES as $name => [, , $permissions]) {
            Role::findOrCreate($name, self::GUARD)
                ->syncPermissions($name === self::SUPER_ADMIN ? array_keys(self::PERMISSIONS) : $permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<array{key:string,label:string,description:string,permissions:list<string>}> for the Users & Roles screen */
    public static function roles(): array
    {
        $out = [];
        foreach (self::ROLES as $key => [$label, $description, $permissions]) {
            $out[] = ['key' => $key, 'label' => $label, 'description' => $description, 'permissions' => $key === self::SUPER_ADMIN ? array_keys(self::PERMISSIONS) : $permissions];
        }

        return $out;
    }
}
