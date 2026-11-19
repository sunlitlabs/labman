<?php

namespace App\Mcp;

use App\Models\ApiToken;
use App\Models\User;

/** Who is calling: the API token and the admin who owns it (the "actor" for guard rules). */
final class McpContext
{
    public function __construct(public readonly ApiToken $token) {}

    public function actor(): User
    {
        return $this->token->user;
    }

    public function canWrite(): bool
    {
        return $this->token->scope === ApiToken::SCOPE_ADMIN;
    }
}
