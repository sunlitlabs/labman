<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    public const SCOPE_READ = 'read';

    public const SCOPE_ADMIN = 'admin';

    protected $fillable = ['name', 'scope'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a token for $user. Returns the model and the plaintext token, which is
     * never stored and cannot be shown again.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $name, string $scope): array
    {
        $plain = 'lm_'.Str::random(40);

        $token = new self(['name' => $name, 'scope' => $scope]);
        $token->user_id = $user->id;
        $token->token_hash = self::hash($plain);
        $token->token_prefix = substr($plain, 0, 8);
        $token->save();

        return [$token, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByPlain(string $plain): ?self
    {
        return self::query()->where('token_hash', self::hash($plain))->first();
    }

    /** A token is only as strong as its owner: disabled or demoted creators kill their admin tokens. */
    public function isUsable(): bool
    {
        $owner = $this->user;

        if ($owner === null || ! $owner->isActive()) {
            return false;
        }

        return $this->scope !== self::SCOPE_ADMIN || $owner->isAdmin();
    }
}
