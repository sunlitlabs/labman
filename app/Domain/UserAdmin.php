<?php

namespace App\Domain;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * User administration shared by the Users screen and the MCP tools: create, update
 * (role, status, password reset), approve, with the "cannot demote or disable
 * yourself / the last admin" guards.
 */
final class UserAdmin
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(?User $existing, bool $confirmPassword): array
    {
        $password = ['string', 'min:8', 'max:255'];
        if ($confirmPassword) {
            $password[] = 'confirmed';
        }

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($existing)],
            'password' => array_merge($existing ? ['nullable'] : ['required'], $password),
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_PENDING, User::STATUS_DISABLED])],
        ];
    }

    /**
     * Create a user from input, or update $existing with the given changes.
     * $actor is the admin doing it (the signed-in admin, or the owner of an API token).
     *
     * @throws ValidationException
     */
    public static function save(array $input, ?User $existing, ?User $actor, bool $confirmPassword = false): User
    {
        if ($existing) {
            $input = array_merge([
                'name' => $existing->name, 'email' => $existing->email,
                'role' => $existing->role, 'status' => $existing->status,
            ], $input);
        } else {
            $input += ['role' => User::ROLE_USER, 'status' => User::STATUS_ACTIVE];
        }

        $data = Validator::make($input, self::rules($existing, $confirmPassword))->validate();

        if ($existing) {
            self::guard($existing, $actor, $data);
        }

        $user = $existing ?? new User;
        $user->fill(['name' => $data['name'], 'email' => mb_strtolower($data['email'])]);
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password']; // hashed by the model cast
        }
        $user->role = $data['role'];
        $user->status = $data['status'];
        $user->save();

        if ($existing && ($user->status !== User::STATUS_ACTIVE || filled($data['password'] ?? null))) {
            // Drop their sessions right away; EnsureUserIsActive also guards the next request.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return $user;
    }

    public static function approve(User $user): User
    {
        if ($user->status === User::STATUS_PENDING) {
            $user->status = User::STATUS_ACTIVE;
            $user->save();
        }

        return $user;
    }

    /** @throws ValidationException */
    private static function guard(User $target, ?User $actor, array $data): void
    {
        $losesAdmin = $data['role'] !== User::ROLE_ADMIN || $data['status'] !== User::STATUS_ACTIVE;

        if ($losesAdmin && $actor !== null && $target->is($actor)) {
            throw ValidationException::withMessages(['role' => 'You cannot demote or disable your own account.']);
        }

        if ($losesAdmin && $target->isAdmin() && $target->isActive()
            && ! User::activeAdmins()->whereKeyNot($target->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'This is the last active administrator and cannot be demoted or disabled.']);
        }
    }
}
