<?php

namespace Tests\Feature;

use App\Domain\UserAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Passwords from a password manager: symbols, spaces, unicode and long values must all survive intact. */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function passwords(): array
    {
        return [
            'symbols' => ['p@$$w0rd!"\'<>&%#;:/\\|{}[]()*^~`+=?,.'],
            'leading and trailing spaces' => ['  spaced out & symbols!  '],
            'unicode' => ['pässwörd-ключ-密码-🔑!'],
            'a generated one' => ['k9#Lm$2vQ!xR7@zT&4nB%8wY^3cD*6hF'],
            'a long passphrase' => [str_repeat('correct horse battery staple! ', 6)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('passwords')]
    public function test_first_run_setup_stores_the_password_exactly_as_typed(string $password): void
    {
        $this->post('/setup', [
            'name' => 'First Admin', 'email' => 'admin@example.com', 'password' => $password, 'password_confirmation' => $password,
            'title' => 'Lab', 'registration_mode' => 'approval',
            'visibility' => ['services' => 'users', 'servers' => 'users', 'settings' => 'admins', 'users' => 'admins'],
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check(trim($password).'x', $user->password));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('passwords')]
    public function test_registering_and_signing_in_with_symbols(string $password): void
    {
        $this->admin();
        \App\Support\Settings::set(['registration_mode' => 'open']);

        $this->post('/register', ['name' => 'Pat', 'email' => 'pat@example.com', 'password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasNoErrors();

        $this->post('/logout');
        $this->post('/login', ['email' => 'pat@example.com', 'password' => $password])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticated();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('passwords')]
    public function test_an_admin_can_create_and_reset_users_with_symbols(string $password): void
    {
        $user = UserAdmin::save(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => $password, 'role' => 'user', 'status' => 'active'], null, $this->admin());
        $this->assertTrue(Hash::check($password, $user->fresh()->password));

        $reset = $password.'!2';
        UserAdmin::save(['password' => $reset], $user, $this->admin());
        $this->assertTrue(Hash::check($reset, $user->fresh()->password));
        $this->assertFalse(Hash::check($password, $user->fresh()->password));
    }

    public function test_surrounding_spaces_are_part_of_the_password(): void
    {
        $this->admin();
        $this->user(['email' => 'sp@example.com', 'password' => ' pw with spaces ']);

        $this->post('/login', ['email' => 'sp@example.com', 'password' => 'pw with spaces'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'sp@example.com', 'password' => ' pw with spaces '])->assertSessionHasNoErrors();
    }

    public function test_hashes_use_argon2id_and_cover_the_whole_password(): void
    {
        $long = str_repeat('a', 80);
        $hash = Hash::make($long);

        $this->assertSame('argon2id', Hash::info($hash)['algoName']);
        // bcrypt would ignore everything after 72 bytes; two long passwords differing only at the end must not match.
        $this->assertTrue(Hash::check($long, $hash));
        $this->assertFalse(Hash::check(str_repeat('a', 79).'b', $hash));
    }

    public function test_an_old_bcrypt_hash_still_signs_in_and_is_upgraded(): void
    {
        $this->admin();
        $user = $this->user(['email' => 'old@example.com']);
        DB::table('users')->where('id', $user->id)->update(['password' => password_hash('old-pw!#1', PASSWORD_BCRYPT, ['cost' => 4])]);

        $this->post('/login', ['email' => 'old@example.com', 'password' => 'old-pw!#1'])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        $this->assertSame('argon2id', Hash::info($user->fresh()->password)['algoName']);
        $this->assertTrue(Hash::check('old-pw!#1', $user->fresh()->password));
    }

    public function test_very_long_passwords_are_refused_with_a_message_not_an_error(): void
    {
        $this->admin();
        $long = str_repeat('x', 300);

        $this->post('/login', ['email' => 'a@example.com', 'password' => $long])->assertSessionHasErrors('password');
    }
}
