<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
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
        return response()->json(['roles' => AdminAccess::matrix()]);
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

        return response()->json(['roles' => AdminAccess::matrix()]);
    }
}
