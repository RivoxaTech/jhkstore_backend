<?php

namespace Tests\Feature;

use App\Domain\Auth\Actions\SaveAdminUser;
use App\Domain\Auth\Models\Admin;
use App\Domain\Support\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
    }

    private function payload(array $over = []): array
    {
        return $over + ['username' => 'maria', 'name' => 'Maria Lopez', 'email' => 'maria@example.test', 'password' => 'Str0ng-passw0rd', 'role' => 'staff'];
    }

    public function test_only_a_super_admin_manages_admin_users(): void
    {
        foreach (['manager', 'staff'] as $role) {
            $this->actingAsAdmin($role);
            $this->getJson(self::ADMIN.'/users')->assertForbidden();
            $this->getJson(self::ADMIN.'/roles')->assertForbidden();
            $this->postJson(self::ADMIN.'/users', $this->payload())->assertForbidden();
        }
        $this->assertSame(0, Admin::query()->where('username', 'maria')->count());
    }

    public function test_the_roles_screen_gets_the_roles_with_their_permissions(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson(self::ADMIN.'/roles')->assertOk();
        $this->assertSame(['super_admin', 'manager', 'staff'], array_column($res->json('roles'), 'key'));
        $this->assertSame(['Super admin', 'Manager', 'Staff'], array_column($res->json('roles'), 'label'));
        $this->assertContains('orders.manage', $res->json('roles.2.permissions'));
        $this->assertNotContains('catalog.manage', $res->json('roles.2.permissions'));
        $this->assertCount(10, $res->json('permissions'));
    }

    public function test_a_super_admin_creates_lists_edits_and_deletes_admin_users(): void
    {
        $me = $this->actingAsAdmin();

        $created = $this->postJson(self::ADMIN.'/users', $this->payload())->assertCreated()
            ->assertJsonPath('data.username', 'maria')->assertJsonPath('data.role', 'staff')->assertJsonPath('data.role_label', 'Staff')
            ->assertJsonPath('data.is_active', true)->assertJsonPath('data.is_self', false)->assertJsonMissingPath('data.password');
        $id = $created->json('data.id');
        $this->assertTrue(Hash::check('Str0ng-passw0rd', Admin::find($id)->password));   // stored hashed
        $this->assertNotSame('Str0ng-passw0rd', Admin::find($id)->password);

        $list = $this->getJson(self::ADMIN.'/users')->assertOk();
        $this->assertSame(['maria', 'tester'], array_column($list->json('data'), 'username'));
        $this->assertTrue(collect($list->json('data'))->firstWhere('id', $me->id)['is_self']);

        $this->putJson(self::ADMIN."/users/$id", ['name' => 'Maria L.', 'role' => 'manager', 'email' => null])->assertOk()
            ->assertJsonPath('data.name', 'Maria L.')->assertJsonPath('data.role', 'manager')->assertJsonPath('data.email', null);
        $this->assertTrue(Admin::find($id)->hasRole('manager'));
        $this->assertFalse(Admin::find($id)->hasRole('staff'));   // one role per admin

        $this->deleteJson(self::ADMIN."/users/$id")->assertOk();
        $this->assertNull(Admin::find($id));
        $this->deleteJson(self::ADMIN."/users/$id")->assertNotFound();

        foreach (['admin.created', 'admin.updated', 'admin.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'actor_type' => 'admin']);
        }
        $this->assertDatabaseMissing('audit_logs', ['changes' => 'Str0ng-passw0rd']);
        $this->assertStringNotContainsString('Str0ng-passw0rd', AuditLog::query()->pluck('changes')->toJson());   // passwords never reach the audit log
    }

    public function test_the_new_admin_can_log_in_and_has_only_the_role_permissions(): void
    {
        $this->actingAsAdmin();
        $this->postJson(self::ADMIN.'/users', $this->payload(['role' => 'manager']))->assertCreated();
        $this->app['auth']->forgetGuards();

        $token = $this->postJson(self::ADMIN.'/auth/login', ['login' => 'maria', 'password' => 'Str0ng-passw0rd'])->assertOk()->json('token');
        $this->withToken($token)->getJson(self::ADMIN.'/products')->assertOk();
        $this->withToken($token)->putJson(self::ADMIN.'/settings', ['store_name' => 'Hacked'])->assertForbidden();
        $this->withToken($token)->getJson(self::ADMIN.'/users')->assertForbidden();
    }

    public function test_admin_user_input_is_validated(): void
    {
        $this->actingAsAdmin();
        $url = self::ADMIN.'/users';

        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['username', 'name', 'password', 'role']);
        $this->postJson($url, $this->payload(['username' => 'a b']))->assertStatus(422)->assertJsonValidationErrors('username');
        $this->postJson($url, $this->payload(['username' => 'ab']))->assertStatus(422)->assertJsonValidationErrors('username');
        $this->postJson($url, $this->payload(['username' => 'tester']))->assertStatus(422)->assertJsonValidationErrors('username');   // taken
        $this->postJson($url, $this->payload(['password' => 'short1']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson($url, $this->payload(['password' => 'onlyletterspassword']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson($url, $this->payload(['email' => 'nope']))->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson($url, $this->payload(['role' => 'owner']))->assertStatus(422)->assertJsonValidationErrors('role');

        $this->postJson($url, $this->payload())->assertCreated();
        $this->postJson($url, $this->payload(['username' => 'maria2']))->assertStatus(422)->assertJsonValidationErrors('email');   // e-mail taken

        $id = Admin::query()->where('username', 'maria')->value('id');
        $this->putJson("$url/$id", ['username' => 'renamed'])->assertStatus(422)->assertJsonValidationErrors('username');   // fixed
        $this->putJson("$url/$id", ['password' => 'weak'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson("$url/$id", ['role' => 'owner'])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_a_new_password_or_deactivation_signs_the_admin_out_of_api_tokens(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson(self::ADMIN.'/users', $this->payload())->json('data.id');
        $admin = Admin::find($id);
        $admin->createToken('script');
        $this->assertSame(1, $admin->tokens()->count());

        $this->putJson(self::ADMIN."/users/$id", ['password' => 'An0ther-passw0rd'])->assertOk();
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertTrue(Hash::check('An0ther-passw0rd', $admin->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.updated']);

        $admin->createToken('script');
        $this->putJson(self::ADMIN."/users/$id", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $admin->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->postJson(self::ADMIN.'/auth/login', ['login' => 'maria', 'password' => 'An0ther-passw0rd'])->assertStatus(422);   // inactive: no login
    }

    public function test_you_cannot_lock_yourself_out(): void
    {
        $me = $this->actingAsAdmin();
        $url = self::ADMIN."/users/{$me->id}";

        $this->putJson($url, ['is_active' => false])->assertStatus(422)->assertJsonValidationErrors('is_active');
        $this->putJson($url, ['role' => 'staff'])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->deleteJson($url)->assertStatus(422)->assertJsonValidationErrors('user');
        $this->putJson($url, ['name' => 'New Name', 'role' => 'super_admin'])->assertOk();   // other edits are fine

        $me = $me->fresh();
        $this->assertTrue($me->is_active);
        $this->assertTrue($me->hasRole('super_admin'));
    }

    public function test_another_super_admin_can_be_demoted_deactivated_and_deleted_while_one_remains(): void
    {
        $me = $this->actingAsAdmin();
        $other = $this->makeAdmin('super_admin', 'second');
        $url = self::ADMIN."/users/{$other->id}";

        $this->putJson($url, ['role' => 'manager'])->assertOk()->assertJsonPath('data.role', 'manager');
        $this->putJson($url, ['role' => 'super_admin', 'is_active' => false])->assertOk();
        $this->deleteJson($url)->assertOk();

        $this->assertTrue($me->fresh()->hasRole('super_admin'));
    }

    public function test_the_last_active_super_admin_cannot_be_removed_even_by_another_super_admin(): void
    {
        $actor = $this->makeAdmin('super_admin', 'actor');
        $victim = $this->makeAdmin('super_admin', 'victim');
        $this->makeAdmin('super_admin', 'sleeper')->forceFill(['is_active' => false])->save();   // an inactive super admin does not count
        $action = app(SaveAdminUser::class);

        // the actor steps down first: the victim is now the only active super admin
        $action->update($actor, ['role' => 'manager'], $victim);
        $this->assertFalse($actor->fresh()->hasRole('super_admin'));

        foreach ([fn () => $action->update($victim, ['role' => 'staff'], $actor), fn () => $action->update($victim, ['is_active' => false], $actor), fn () => $action->delete($victim, $actor)] as $try) {
            try {
                $try();
                $this->fail('The last super admin was removed.');
            } catch (ValidationException $e) {
                $this->assertSame('There must always be one active super admin.', $e->errors()['role'][0]);
            }
        }
        $this->assertTrue($victim->fresh()->hasRole('super_admin'));
        $this->assertTrue($victim->fresh()->is_active);
    }
}
