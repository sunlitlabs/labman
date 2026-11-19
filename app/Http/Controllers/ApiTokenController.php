<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin-only management of the bearer tokens that authenticate MCP clients. */
class ApiTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'scope' => ['required', Rule::in([ApiToken::SCOPE_READ, ApiToken::SCOPE_ADMIN])],
        ]);

        [$token, $plain] = ApiToken::issue($request->user(), $data['name'], $data['scope']);

        // Rendered directly (not flashed through the session) so the plaintext token is never stored.
        return response()
            ->view('tokens.created', ['token' => $token, 'plain' => $plain, 'endpoint' => url('/mcp')])
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(ApiToken $token)
    {
        $token->delete();

        return redirect()->route('settings.mcp')->with('status', "Token '{$token->name}' revoked.");
    }
}
