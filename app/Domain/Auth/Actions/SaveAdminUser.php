<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, updates and deletes admin users (admin side). Rules that keep the panel usable:
 * you cannot deactivate, demote or delete yourself, and the last active super admin always stays.
 */
class SaveAdminUser
{
    public function create(array $data, Admin $by): Admin
    {
        return DB::transaction(function () use ($data, $by) {
            $admin = Admin::create([
                'username' => $data['username'], 'name' => $data['name'], 'email' => $data['email'] ?? null,
                'password' => $data['password'], 'is_active' => $data['is_active'] ?? true,
            ]);
            $admin->syncRoles($data['role']);
            Audit::log($by, 'admin.created', $admin, ['username' => $admin->username, 'role' => $data['role']]);

            return $admin;
        });
    }

    /** `password` (optional) sets a new password and signs the admin out of API tokens. */
    public function update(Admin $admin, array $data, Admin $by): Admin
    {
        $newRole = $data['role'] ?? null;
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $admin->is_active;
        if ($admin->is($by)) {
            if (! $active) {
                throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
            }
            if ($newRole !== null && ! $admin->hasRole($newRole)) {
                throw ValidationException::withMessages(['role' => 'You cannot change your own role. Ask another super admin.']);
            }
        }
        if ($admin->hasRole(AdminAccess::SUPER_ADMIN) && (($newRole !== null && $newRole !== AdminAccess::SUPER_ADMIN) || ! $active)) {
            $this->guardLastSuperAdmin($admin);
        }

        return DB::transaction(function () use ($admin, $data, $by, $newRole, $active) {
            $admin->fill(collect($data)->only(['name', 'email'])->all());
            $admin->is_active = $active;
            if (! empty($data['password'])) {
                $admin->password = $data['password'];
            }
            $admin->save();
            if ($newRole !== null) {
                $admin->syncRoles($newRole);
            }
            if (! $active || ! empty($data['password'])) {
                $admin->tokens()->delete();   // scripts using the old token must log in again
            }
            Audit::log($by, 'admin.updated', $admin, ['username' => $admin->username] + collect($data)->except(['password'])->all() + (! empty($data['password']) ? ['password_changed' => true] : []));

            return $admin;
        });
    }

    public function delete(Admin $admin, Admin $by): void
    {
        if ($admin->is($by)) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }
        if ($admin->hasRole(AdminAccess::SUPER_ADMIN)) {
            $this->guardLastSuperAdmin($admin);
        }

        DB::transaction(function () use ($admin, $by) {
            $admin->tokens()->delete();
            $admin->delete();
            Audit::log($by, 'admin.deleted', $admin, ['username' => $admin->username]);
        });
    }

    /** At least one other active super admin must remain. */
    private function guardLastSuperAdmin(Admin $admin): void
    {
        $others = Admin::query()->role(AdminAccess::SUPER_ADMIN, AdminAccess::GUARD)->where('is_active', true)->whereKeyNot($admin->id)->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['role' => 'There must always be one active super admin.']);
        }
    }
}
