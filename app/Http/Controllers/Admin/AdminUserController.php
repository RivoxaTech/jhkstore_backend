<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Actions\SaveAdminUser;
use App\Domain\Auth\Models\Admin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAdminUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin users and roles (needs the `admins.manage` permission). */
class AdminUserController extends Controller
{
    /** The roles with what each may do, and the permission list: for the Users & Roles screen. */
    public function roles(): JsonResponse
    {
        return response()->json([
            'roles' => AdminAccess::roles(),
            'permissions' => collect(AdminAccess::PERMISSIONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    /** Every admin user (a store has a handful, so the list is not paginated). */
    public function index(Request $request): JsonResponse
    {
        $admins = Admin::query()->with('roles')->orderBy('username')->get();

        return response()->json(['data' => $admins->map(fn (Admin $a) => $this->present($a, $request->user()))]);
    }

    public function store(SaveAdminUserRequest $request, SaveAdminUser $save): JsonResponse
    {
        $admin = $save->create($request->validated(), $request->user());

        return response()->json(['data' => $this->present($admin->load('roles'), $request->user())], 201);
    }

    /** Send only what changes: name, email, role, is_active, password (a new password signs the admin out of API tokens). The username is fixed. */
    public function update(SaveAdminUserRequest $request, Admin $adminUser, SaveAdminUser $save): JsonResponse
    {
        $admin = $save->update($adminUser, $request->validated(), $request->user());

        return response()->json(['data' => $this->present($admin->load('roles'), $request->user())]);
    }

    /** You cannot delete yourself or the last active super admin (422). */
    public function destroy(Request $request, Admin $adminUser, SaveAdminUser $save): JsonResponse
    {
        $save->delete($adminUser, $request->user());

        return response()->json(['message' => 'Admin user deleted.']);
    }

    private function present(Admin $admin, Admin $viewer): array
    {
        $role = $admin->getRoleNames()->first();

        return [
            'id' => $admin->id,
            'username' => $admin->username,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $role,
            'role_label' => AdminAccess::ROLES[$role][0] ?? $role,
            'is_active' => $admin->is_active,
            'is_self' => $admin->is($viewer),
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
            'created_at' => $admin->created_at?->toIso8601String(),
        ];
    }
}
