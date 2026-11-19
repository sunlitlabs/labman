@extends('layouts.admin')

@section('title', 'AI access (MCP)')

@section('admin')
    <h1>AI access (MCP)</h1>
    <p class="muted">
        Let an AI assistant (Claude Code, Claude Desktop or any MCP client) use LabMan at <code>{{ url('/mcp') }}</code>.
        @unless (config('labman.mcp.enabled')) <strong>MCP is currently switched off (LABMAN_MCP_ENABLED).</strong> @endunless
    </p>

    <div class="formcard">
        <h2>API tokens</h2>
        <p class="sub">Each client gets its own token. A <strong>read</strong> token can look; an <strong>admin</strong> token can do everything an administrator can except manage tokens. Revoke one here at any time.</p>

        @if ($tokens->isNotEmpty())
            <table class="plain">
                <thead><tr><th>Name</th><th>Scope</th><th>Token</th><th>Owner</th><th>Last used</th><th></th></tr></thead>
                <tbody>
                    @foreach ($tokens as $token)
                        <tr>
                            <td>{{ $token->name }}</td>
                            <td><span class="tag">{{ $token->scope }}</span></td>
                            <td class="mono">{{ $token->token_prefix }}…</td>
                            <td>{{ $token->user?->name }}</td>
                            <td>{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                            <td>
                                <form method="POST" action="{{ route('tokens.destroy', $token) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn-danger" type="submit" data-confirm>Revoke</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="muted">No tokens yet.</p>
        @endif

        <form method="POST" action="{{ route('tokens.store') }}" class="grid2 token-form">
            @csrf
            <div class="field">
                <label for="token_name">New token name</label>
                <input id="token_name" name="name" type="text" maxlength="60" required placeholder="e.g. Claude on my laptop">
            </div>
            <div class="field">
                <label for="token_scope">Scope</label>
                <select id="token_scope" name="scope">
                    <option value="read">read: view services, servers, health, settings</option>
                    <option value="admin">admin: everything an admin can do (except tokens)</option>
                </select>
            </div>
            <div><button class="primary" type="submit">Create token</button></div>
        </form>
    </div>

    <p class="hint">Tokens are shown once, when created, and only a hash is stored. Put LabMan behind HTTPS before using tokens over a network.</p>
@endsection
