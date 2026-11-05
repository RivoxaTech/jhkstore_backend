<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Access Control (developer only): one switch per permission for the `admin` role, and the developer's own customers-list access. */
class AccessControlController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['roles' => $this->matrix()]);
    }

    /**
     * Save a role's permissions: PUT access-control/{role} { permissions: ["products.view", ...] } (the full list of switches that are on).
     * The developer can never hold orders.* and the admin can never hold access.manage: asking for them answers 422.
     */
    public function update(Request $request, string $role): JsonResponse
    {
        abort_unless(in_array($role, AdminAccess::ROLES, true), 404);
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(AdminAccess::all())],
        ]);

        $changes = AdminAccess::setRolePermissions($role, $data['permissions']);
        Audit::log($request->user(), 'access.updated', Role::findByName($role, AdminAccess::GUARD), ['role' => $role] + $changes);

        return response()->json(['roles' => $this->matrix()]);
    }
    /**
     * The matrix with, on every permission, what it covers and a tag: `client` (the admin may have it by default) or `advanced` ("Developer / advanced").
     * Settings permissions list their settings (key, label, tier) from the definitions, so the screen can never drift from what the API enforces.
     */
    private function matrix(): array
    {
        $fields = [];   // tab => [[key, label, tier]]
        foreach (Settings::DEFINITIONS as $key => $def) {
            $fields[SettingController::tabOf($key)][] = ['key' => $key, 'label' => $def[3], 'tier' => Settings::access($key)];
        }
        $advancedFields = collect($fields)->flatMap(fn ($list, $tab) => collect($list)->where('tier', Settings::ADVANCED)->map(fn ($f) => $f + ['tab' => SettingController::TABS[$tab][0]]))->values()->all();

        $describe = function (string $permission) use ($fields, $advancedFields): array {
            if ($permission === AdminAccess::SETTINGS_ADVANCED) {
                return ['tier' => Settings::ADVANCED, 'covers' => ['Every technical field marked "Developer", in the Settings tabs the admin may open', 'Technical details of the notification status (queue, failed jobs)'], 'fields' => $advancedFields];
            }
            if (str_starts_with($permission, 'settings.')) {
                $tab = substr($permission, 9);
                $list = $fields[$tab] ?? [];
                $panel = SettingController::PANELS[$tab] ?? null;
                $covers = array_values(array_filter([
                    SettingController::TABS[$tab][0].' tab',
                    $panel ? ['banners' => 'The banner manager', 'delivery' => 'The delivery zones table', 'notifications' => 'The events table (customer e-mail, owner e-mail, push), test e-mail and notification status'][$tab] : null,
                    $tab === 'theme' ? 'Never available to the admin' : null,
                ]));
                $clientPanel = $panel === Settings::CLIENT;
                $tier = ! $clientPanel && collect($list)->where('tier', Settings::CLIENT)->isEmpty() ? Settings::ADVANCED : Settings::CLIENT;

                return ['tier' => $tier, 'covers' => $covers, 'fields' => $list];
            }

            return ['tier' => $permission === AdminAccess::ACCESS_MANAGE ? Settings::ADVANCED : Settings::CLIENT, 'covers' => AdminAccess::COVERS[$permission] ?? [], 'fields' => []];
        };

        $roles = AdminAccess::matrix();
        foreach ($roles as &$role) {
            foreach ($role['groups'] as &$group) {
                foreach ($group['permissions'] as &$p) {
                    $p += $describe($p['key']);
                }
            }
        }

        return $roles;
    }
}
