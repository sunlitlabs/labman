<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the MCP endpoint: the feature switch, DNS-rebinding protection (the Origin
 * header, when a browser sends one, must be our own origin or an allowed one) and
 * bearer-token authentication. Tokens are looked up by their SHA-256 hash.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('labman.mcp.enabled'), 404);

        if (! $this->originAllowed($request)) {
            return response()->json(['error' => 'Origin not allowed.'], 403);
        }

        $plain = $request->bearerToken();
        $token = $plain ? ApiToken::findByPlain($plain) : null;

        if ($token === null || ! $token->isUsable()) {
            return response()->json(['error' => 'A valid API token is required.'], 401, ['WWW-Authenticate' => 'Bearer realm="labman"']);
        }

        // Keep last_used_at roughly current without a write on every call.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null || $origin === '') {
            return true; // non-browser clients (CLIs, desktop apps) send no Origin
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) config('labman.mcp.allowed_origins'))));
        $allowed[] = $request->getSchemeAndHttpHost();

        return in_array(rtrim($origin, '/'), array_map(fn ($o) => rtrim($o, '/'), $allowed), true);
    }
}
