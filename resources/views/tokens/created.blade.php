@extends('layouts.app')

@section('title', 'API token created')

@section('content')
    <div class="wide">
        <h1>API token created</h1>
        <div class="flash" role="status">
            Copy this token now. It is shown only once and LabMan stores just a hash of it.
        </div>

        <div class="card">
            <div class="field">
                <label for="token">{{ $token->name }} <span class="hint">({{ $token->scope }} scope)</span></label>
                <input id="token" type="text" readonly value="{{ $plain }}" class="mono" autocomplete="off">
            </div>

            <h2>Connect an MCP client</h2>
            <p class="muted">Endpoint: <code>{{ $endpoint }}</code> (Streamable HTTP, bearer token).</p>
            <p class="muted">Claude Code:</p>
            <pre class="code">claude mcp add --transport http labman {{ $endpoint }} --header "Authorization: Bearer {{ $plain }}"</pre>
            <p class="muted">Any client that takes a JSON config:</p>
            <pre class="code">{ "mcpServers": { "labman": { "type": "http", "url": "{{ $endpoint }}", "headers": { "Authorization": "Bearer {{ $plain }}" } } } }</pre>

            @if ($token->scope === 'admin')
                <p class="hint">An admin token can do everything an administrator can except manage tokens: change servers, services, settings and users, and push to Nginx Proxy Manager. It stops working if you are disabled or demoted.</p>
            @else
                <p class="hint">A read token can only list services and servers, read health and settings, and export the YAML.</p>
            @endif

            <a class="btn btn-primary" href="{{ route('settings.index') }}">Done</a>
        </div>
    </div>
@endsection
