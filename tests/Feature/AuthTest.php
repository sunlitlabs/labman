<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = [])
    {
        return $this->post('/register', array_merge([
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'long enough pw',
            'password_confirmation' => 'long enough pw',
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin(['email' => 'root@example.com']);
        RateLimiter::clear('x');
    }

    // ------------------------------------------------------- registration modes

    public function test_registration_off_hides_and_blocks_registration(): void
    {
        Settings::set(['registration_mode' => 'off']);

        $this->get('/register')->assertNotFound();
        $this->register()->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->get('/login')->assertOk()->assertDontSee('Create an account');
    }

    public function test_open_registration_creates_an_active_user_and_signs_in(): void
    {
        Settings::set(['registration_mode' => 'open']);

        $this->get('/login')->assertSee('Create an account');
        $this->register()->assertRedirect('/');

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('user', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_can_never_create_an_admin(): void
    {
        Settings::set(['registration_mode' => 'open']);

        $this->register(['role' => 'admin', 'status' => 'active'])->assertRedirect('/');

        $this->assertSame('user', User::where('email', 'new@example.com')->value('role'));
    }

    public function test_approval_mode_creates_a_pending_account_that_cannot_sign_in(): void
    {
        Settings::set(['registration_mode' => 'approval']);

        $this->register()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame('pending', User::where('email', 'new@example.com')->value('status'));

        $this->post('/login', ['email' => 'new@example.com', 'password' => 'long enough pw'])
            ->assertSessionHasErrors(['email' => 'Your account is waiting for an administrator to approve it.']);
        $this->assertGuest();
    }

    public function test_an_admin_approving_the_account_lets_the_user_sign_in(): void
    {
        Settings::set(['registration_mode' => 'approval']);
        $this->register();
        $pending = User::where('email', 'new@example.com')->firstOrFail();

        $this->actingAs($this->admin())->post("/users/{$pending->id}/approve")->assertRedirect(route('users.index'));
        $this->assertSame('active', $pending->fresh()->status);

        auth()->logout();
        $this->post('/login', ['email' => 'new@example.com', 'password' => 'long enough pw'])->assertRedirect();
        $this->assertAuthenticatedAs($pending);
    }

    public function test_registration_validates_input(): void
    {
        Settings::set(['registration_mode' => 'open']);

        $this->register(['password_confirmation' => 'nope'])->assertSessionHasErrors('password');
        $this->register(['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->register(['email' => 'root@example.com'])->assertSessionHasErrors('email');
        $this->register(['email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    // ------------------------------------------------------------------ sign-in

    public function test_sign_in_and_out(): void
    {
        $user = $this->user(['email' => 'person@example.com', 'password' => 'a good password']);

        $this->post('/login', ['email' => 'PERSON@example.com', 'password' => 'a good password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_credentials_are_rejected(): void
    {
        $this->user(['email' => 'person@example.com', 'password' => 'a good password']);

        $this->post('/login', ['email' => 'person@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_disabled_user_cannot_sign_in(): void
    {
        User::factory()->disabled()->create(['email' => 'gone@example.com', 'password' => 'a good password']);

        $this->post('/login', ['email' => 'gone@example.com', 'password' => 'a good password'])
            ->assertSessionHasErrors(['email' => 'This account has been disabled.']);
        $this->assertGuest();
    }

    public function test_a_user_disabled_while_signed_in_is_logged_out_on_the_next_request(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/services')->assertOk();

        $user->status = 'disabled';
        $user->save();

        $this->get('/services')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_disabled_user_gets_401_from_the_json_endpoint(): void
    {
        $user = $this->user();
        $user->status = 'disabled';
        $user->save();

        $this->actingAs($user)->getJson('/status')->assertUnauthorized();
    }

    public function test_login_is_throttled_per_email_and_ip(): void
    {
        $this->user(['email' => 'victim@example.com', 'password' => 'a good password']);
        $this->user(['email' => 'other@example.com', 'password' => 'another good one']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'victim@example.com', 'password' => 'guess'.$i])->assertSessionHasErrors('email');
        }

        // The correct password no longer works while throttled...
        $this->post('/login', ['email' => 'victim@example.com', 'password' => 'a good password'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
        $this->assertGuest();

        // ...but a different email from the same IP is a different bucket.
        $this->post('/login', ['email' => 'other@example.com', 'password' => 'another good one'])->assertRedirect();
        $this->assertAuthenticated();
    }

    // --------------------------------------------------------- user administration

    public function test_admin_creates_users_and_resets_passwords(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Made By Admin', 'email' => 'made@example.com', 'password' => 'initial password',
            'password_confirmation' => 'initial password', 'role' => 'user', 'status' => 'active',
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'made@example.com')->firstOrFail();

        $this->actingAs($admin)->put("/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role' => 'user', 'status' => 'active',
            'password' => 'brand new password', 'password_confirmation' => 'brand new password',
        ])->assertRedirect(route('users.index'));

        auth()->logout();
        $this->post('/login', ['email' => 'made@example.com', 'password' => 'initial password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'made@example.com', 'password' => 'brand new password'])->assertRedirect();
    }

    public function test_an_admin_cannot_demote_or_disable_themselves(): void
    {
        $admin = $this->admin(['email' => 'me@example.com']);

        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'user', 'status' => 'active',
        ])->assertSessionHasErrors('role');

        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'admin', 'status' => 'disabled',
        ])->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_another_admin_can_be_demoted_and_disabled_and_loses_access_at_once(): void
    {
        $actor = $this->admin();
        $other = $this->admin(['email' => 'other-admin@example.com']);

        $this->actingAs($other)->get('/settings')->assertOk();

        $this->actingAs($actor)->put("/users/{$other->id}", [
            'name' => $other->name, 'email' => $other->email, 'role' => 'user', 'status' => 'disabled',
        ])->assertRedirect(route('users.index'));

        $this->assertSame('user', $other->fresh()->role);
        $this->assertSame('disabled', $other->fresh()->status);

        $this->actingAs($other->fresh())->get('/settings')->assertRedirect(route('login'));
    }

    public function test_role_and_status_are_not_mass_assignable(): void
    {
        $user = new User(['name' => 'x', 'email' => 'x@example.com', 'password' => 'secret123', 'role' => 'admin', 'status' => 'disabled']);

        $this->assertNull($user->role);
    }
}
