<?php

namespace App\Domain\Auth;

use App\Domain\Auth\Models\Admin;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may do what in the admin panel. There are exactly two accounts and two roles:
 *
 *  - `developer`  builds and runs the site. Gets everything EXCEPT orders: never an orders screen, order API, order number, total or
 *                 customer detail. Customers (personal data) are off by default and can be switched on. Owns the Access Control screen.
 *  - `admin`      the client. What the admin may see is decided by the developer, one switch per permission (Access Control screen).
 *
 * The permission list lives here (reviewable, the same on every install); which role holds which permission lives in the spatie tables, so
 * the developer can change the admin's access without a deployment. `sync()` creates what is missing and never resets a role that exists.
 * The hard rules below are enforced on every write AND on every check, so a database edit cannot bypass them:
 *   - the developer can never hold `orders.*`          - `access.manage` and `settings.theme` belong to the developer only
 *
 * Routes ask for a permission with the `admin.can:<permission>` middleware; the admin panel hides what an admin cannot do.
 */
class AdminAccess
{
    public const GUARD = 'admin';

    public const DEVELOPER = 'developer';

    public const ADMIN = 'admin';

    public const ROLES = [self::DEVELOPER, self::ADMIN];

    /** The Access Control screen. Developer only, never switchable for the admin. */
    public const ACCESS_MANAGE = 'access.manage';

    /** group => [label, [permission => label]] (the order is the order of the Access Control screen) */
    public const GROUPS = [
        'dashboard' => ['Dashboard', [
            'dashboard.view' => 'See the dashboard',
        ]],
        'products' => ['Products', [
            'products.view' => 'See products and tags',
            'products.manage' => 'Create, edit and delete products, tags and product pictures',
        ]],
        'categories' => ['Categories', [
            'categories.view' => 'See categories and sub-categories',
            'categories.manage' => 'Create, edit and delete categories and sub-categories',
        ]],
        'orders' => ['Orders', [
            'orders.view' => 'See orders',
            'orders.manage' => 'Change order status and write order notes',
            'orders.export' => 'Download the orders list as CSV',
        ]],
        'customers' => ['Customers', [
            'customers.view' => 'See the customers list',
            'customers.export' => 'Download the customers list as CSV',
        ]],
        'settings' => ['Settings', [
            'settings.general' => 'Settings: General (contact details; the store name needs the advanced permission)',
            'settings.branding' => 'Settings: Branding (logo and favicon)',
            'settings.banners' => 'Settings: Banners (create, edit, reorder, delete)',
            'settings.footer' => 'Settings: Footer & Social',
            'settings.currency_tax' => 'Settings: Currency & Tax',
            'settings.orders_delivery' => 'Settings: Orders & Delivery',
            'settings.seo' => 'Settings: SEO',
            'settings.theme' => 'Settings: Theme (colours, fonts, corners; developer only)',
            'settings.store' => 'Settings: Store (open or closed, notices)',
            'settings.delivery' => 'Settings: Delivery (method, fees, zones)',
            'settings.payments' => 'Settings: Payments (methods, bank accounts)',
            'settings.notifications' => 'Settings: Notifications (which e-mails and push messages are sent)',
            'settings.website' => 'Settings: Website (rebuild the website, see when it was last rebuilt; the hook address and secret need the advanced permission)',
            'settings.advanced' => 'Settings: technical fields in every tab (store name, currency, tax, order numbers, mail sender, tracking link, ...)',
        ]],
        'discounts' => ['Discounts', [
            'discounts.view' => 'See discounts',
            'discounts.manage' => 'Create, edit, activate and delete discounts',
        ]],
        'coupons' => ['Coupons', [
            'coupons.view' => 'See coupons and where they were used',
            'coupons.manage' => 'Create, edit, activate and delete coupons',
        ]],
        'wholesale' => ['Wholesale', [
            'wholesale.view' => 'See wholesale requests (personal data)',
            'wholesale.manage' => 'Change the status of wholesale requests, write notes, download the CSV',
        ]],
        'developer' => ['Developer', [
            self::ACCESS_MANAGE => 'Access Control: decide what the admin may see',
        ]],
    ];

    /** Never given to the admin, whatever the developer switches: the look of the storefront belongs to the developer. */
    public const DEVELOPER_ONLY = [self::ACCESS_MANAGE, 'settings.theme'];

    /** The technical settings (Settings::ADVANCED_*): a field with the tier `advanced` also needs this. On for the developer, off for the admin until the developer switches it on. */
    public const SETTINGS_ADVANCED = 'settings.advanced';

    /** What the admin starts with: the dashboard, orders, the customers list, discounts and coupons and the store / delivery / payment settings. */
    public const ADMIN_DEFAULTS = [
        'dashboard.view', 'orders.view', 'orders.manage', 'customers.view', 'customers.export',
        'settings.general', 'settings.store', 'settings.delivery', 'settings.payments', 'settings.notifications', 'settings.website',
        'orders.export', 'wholesale.view', 'wholesale.manage',
        'discounts.view', 'discounts.manage', 'coupons.view', 'coupons.manage',
    ];

    /** What each non-settings permission covers, in plain words (the Access Control screen lists it under the switch). The Settings permissions are described from the settings themselves. */
    public const COVERS = [
        'dashboard.view' => ['The Dashboard screen. Each section also needs its own permission: orders, products, wholesale, customers'],
        'products.view' => ['Products and Tags screens (read only)', 'Low stock and product counts on the dashboard', 'The search box in the header'],
        'products.manage' => ['Create, edit, delete products, options, stock, bulk prices, tags', 'Product pictures and videos, sold out and featured switches'],
        'categories.view' => ['Categories and sub-categories screen (read only)'],
        'categories.manage' => ['Create, edit, reorder, delete categories and sub-categories'],
        'orders.view' => ['Orders list and order detail, invoice and packing slip', 'Payment proof pictures, the sidebar badge, WhatsApp button', 'Order figures on the dashboard, order e-mails to the owner, order push notifications'],
        'orders.manage' => ['Change status, reject, notes', 'Verify or reject a payment proof, set the delivery fee'],
        'orders.export' => ['Download the orders list as CSV'],
        'customers.view' => ['Customers list and abandoned carts'],
        'customers.export' => ['CSV download of customers and abandoned carts'],
        'discounts.view' => ['Discounts screen (read only)'],
        'discounts.manage' => ['Create, edit, switch on or off, delete discounts'],
        'coupons.view' => ['Coupons screen, where a coupon was used'],
        'coupons.manage' => ['Create, edit, switch on or off, delete coupons'],
        'wholesale.view' => ['Wholesale requests screen (personal data), sidebar badge, dashboard figure, push notification'],
        'wholesale.manage' => ['Change status, notes and CSV download of wholesale requests'],
        self::ACCESS_MANAGE => ['The Access Control screen (this one)'],
    ];

    /** Personal data the developer does not get by default but can be given (the customers list and the wholesale requests). */
    public const DEVELOPER_OPTIONAL = ['customers.view', 'customers.export', 'wholesale.view', 'wholesale.manage'];

    /** Permissions that are always on for the developer and cannot be switched off. */
    public const DEVELOPER_FIXED_ON = [self::ACCESS_MANAGE, self::SETTINGS_ADVANCED];

    /** @return list<string> every permission */
    public static function all(): array
    {
        return array_merge(...array_map(fn ($g) => array_keys($g[1]), array_values(self::GROUPS)));
    }

    /** @return array<string,string> permission => label */
    public static function labels(): array
    {
        return array_merge(...array_map(fn ($g) => $g[1], array_values(self::GROUPS)));
    }

    /** Permissions a role can never hold. */
    public static function forbidden(string $role): array
    {
        return $role === self::DEVELOPER
            ? array_values(array_filter(self::all(), fn ($p) => str_starts_with($p, 'orders.')))
            : self::DEVELOPER_ONLY;
    }

    /** Permissions that may be switched on and off for a role on the Access Control screen. */
    public static function togglable(string $role): array
    {
        $allowed = array_values(array_diff(self::all(), self::forbidden($role), $role === self::DEVELOPER ? self::DEVELOPER_FIXED_ON : []));

        // the developer's own access is fixed, except the personal data (customers list, wholesale requests), which can be switched on
        return $role === self::DEVELOPER ? self::DEVELOPER_OPTIONAL : $allowed;
    }

    /** What the developer starts with: everything except orders and personal data (customers, wholesale requests). */
    public static function developerDefaults(): array
    {
        return array_values(array_diff(self::all(), self::forbidden(self::DEVELOPER), self::DEVELOPER_OPTIONAL));
    }

    /** Creates the permissions and the two roles. A role that already exists keeps its permissions (the developer may have changed them). */
    public static function sync(bool $reset = false): void
    {
        $added = [];
        foreach (self::all() as $name) {
            if (Permission::findOrCreate($name, self::GUARD)->wasRecentlyCreated) {
                $added[] = $name;
            }
        }
        foreach ([self::DEVELOPER => self::developerDefaults(), self::ADMIN => self::ADMIN_DEFAULTS] as $name => $defaults) {
            $role = Role::findOrCreate($name, self::GUARD);
            if ($reset || $role->wasRecentlyCreated) {
                $role->syncPermissions($defaults);
            } elseif ($new = array_values(array_intersect($added, $defaults))) {
                $role->givePermissionTo($new);   // a permission added by an update starts with its default; what the developer switched stays
            }
            // the hard rules hold even if a row was edited by hand
            $role->revokePermissionTo(array_values(array_intersect(self::forbidden($name), $role->permissions->pluck('name')->all())));
        }
        Role::findByName(self::DEVELOPER, self::GUARD)->givePermissionTo(self::DEVELOPER_FIXED_ON);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The Access Control screen saves a role here. `$permissions` is the full list the role should have; fixed ones are added back.
     *
     * @param  list<string>  $permissions
     * @return array{granted: list<string>, revoked: list<string>}
     *
     * @throws ValidationException when it asks for something the role may never have
     */
    public static function setRolePermissions(string $role, array $permissions): array
    {
        abort_unless(in_array($role, self::ROLES, true), 404);

        $unknown = array_diff($permissions, self::all());
        $forbidden = array_intersect($permissions, self::forbidden($role));
        if ($unknown || $forbidden) {
            throw ValidationException::withMessages(['permissions' => [$forbidden
                ? 'The '.$role.' role can never have: '.implode(', ', $forbidden).'.'
                : 'Unknown permission: '.implode(', ', $unknown).'.']]);
        }

        $roleModel = Role::findByName($role, self::GUARD);
        $before = $roleModel->permissions->pluck('name')->all();

        // only the switchable permissions change; everything else stays as it is
        $fixed = array_values(array_diff($before, self::togglable($role)));
        $after = array_values(array_unique(array_merge($fixed, array_intersect($permissions, self::togglable($role)))));
        if ($role === self::DEVELOPER) {
            $after = array_values(array_unique(array_merge($after, self::DEVELOPER_FIXED_ON)));
        }

        $roleModel->syncPermissions($after);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['granted' => array_values(array_diff($after, $before)), 'revoked' => array_values(array_diff($before, $after))];
    }

    /** Does the admin hold the permission? The hard rules are applied here too, whatever the database says. */
    public static function allows(Admin $admin, string $permission): bool
    {
        if (! in_array($permission, self::all(), true)) {
            return false;
        }
        $isDeveloper = $admin->hasRole(self::DEVELOPER);
        if (in_array($permission, self::forbidden($isDeveloper ? self::DEVELOPER : self::ADMIN), true)) {
            return false;
        }

        return $admin->hasPermissionTo($permission, self::GUARD);
    }

    /** @return list<string> the permissions the admin effectively has (what the panel uses to hide screens, tabs and buttons) */
    public static function effective(Admin $admin): array
    {
        return array_values(array_filter(self::all(), fn ($p) => self::allows($admin, $p)));
    }

    /** The screen's data: per role, every group with its permissions, whether each is on and whether it can be switched. */
    public static function matrix(): array
    {
        $out = [];
        foreach (self::ROLES as $role) {
            $has = Role::findByName($role, self::GUARD)->permissions->pluck('name')->all();
            $togglable = self::togglable($role);
            $groups = [];
            foreach (self::GROUPS as $key => [$label, $permissions]) {
                $rows = [];
                foreach ($permissions as $permission => $description) {
                    if (in_array($permission, self::forbidden($role), true)) {
                        continue;
                    }
                    $rows[] = ['key' => $permission, 'label' => $description, 'enabled' => in_array($permission, $has, true), 'locked' => ! in_array($permission, $togglable, true)];
                }
                if ($rows) {
                    $groups[] = ['key' => $key, 'label' => $label, 'permissions' => $rows];
                }
            }
            $out[] = ['role' => $role, 'label' => ucfirst($role), 'groups' => $groups];
        }

        return $out;
    }
}
