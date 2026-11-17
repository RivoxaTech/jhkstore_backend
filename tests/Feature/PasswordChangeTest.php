<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The signed-in admin (developer or client) changes their own password from the profile page. */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/auth/password';

    private function body(array $over = []): array
    {
        return array_merge(['current_password' => 'secret-pass', 'password' => 'a-new-secret-1', 'password_confirmation' => 'a-new-secret-1'], $over);
    }

    public function test_the_password_is_changed_and_the_new_one_signs_in(): void
    {
        $admin = $this->actingAsAdmin();

        $this->putJson(self::URL, $this->body())->assertOk()->assertJsonPath('message', 'Your password was changed.');

        $this->assertTrue(Hash::check('a-new-secret-1', $admin->fresh()->password));
        $this->assertFalse(Hash::check('secret-pass', $admin->fresh()->password));
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/admin/auth/login', ['login' => 'tester', 'password' => 'a-new-secret-1'])->assertOk();
    }

    public function test_the_developer_can_change_theirs_too(): void
    {
        $dev = $this->actingAsAdmin('developer');
        $this->putJson(self::URL, $this->body())->assertOk();
        $this->assertTrue(Hash::check('a-new-secret-1', $dev->fresh()->password));
    }

    public function test_a_wrong_current_password_a_short_one_a_mismatch_and_the_same_password_are_refused(): void
    {
        $admin = $this->actingAsAdmin();

        $this->putJson(self::URL, $this->body(['current_password' => 'not-it']))->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->putJson(self::URL, $this->body(['password' => 'short', 'password_confirmation' => 'short']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson(self::URL, $this->body(['password_confirmation' => 'something-else-1']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson(self::URL, $this->body(['password' => 'secret-pass', 'password_confirmation' => 'secret-pass']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson(self::URL, $this->body(['password' => 'tester', 'password_confirmation' => 'tester']))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('secret-pass', $admin->fresh()->password), 'nothing changed');
    }

    public function test_it_needs_a_login_and_the_password_never_reaches_the_audit_log(): void
    {
        $this->putJson(self::URL, $this->body())->assertUnauthorized();

        $admin = $this->actingAsAdmin();
        $this->putJson(self::URL, $this->body())->assertOk();
        $row = DB::table('audit_logs')->where('action', 'auth.password_changed')->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, (int) $row->actor_id);
        $this->assertStringNotContainsString('a-new-secret-1', json_encode($row));
    }

    public function test_the_other_tokens_of_the_account_are_revoked(): void
    {
        $admin = Admin::create(['username' => 'ownerx', 'name' => 'Owner X', 'password' => 'secret-pass', 'is_active' => true]);
        $other = $admin->createToken('script')->plainTextToken;
        $mine = $admin->createToken('mine')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($mine)->putJson(self::URL, $this->body())->assertOk();

        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $admin->id)->count());
        $this->assertNotNull($other);
    }
}
