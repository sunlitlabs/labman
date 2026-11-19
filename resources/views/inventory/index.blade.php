@extends('layouts.app')

@section('title', 'Inventory')

@section('content')
    <h1>Inventory sync</h1>
    <p class="muted">
        Keep LabMan's servers and the Ansible inventory in your git repository (the one Semaphore reads) lined up.
        LabMan reads the inventory, shows what matches and what does not, and lets you decide, item by item, what to
        change on either side. Nothing is written until you have reviewed the exact diff.
    </p>

    @if ($connection)
        <div class="card">
            <h2>Connected</h2>
            <p>
                <span class="mono">{{ $connection['url'] }}</span>
                on branch <span class="mono">{{ $connection['branch'] }}</span>,
                file <span class="mono">{{ $connection['path'] }}</span>
                <span class="pill pill-{{ \App\Inventory\Inventory::formatFor($connection['path']) === 'yaml' ? 'up' : 'degraded' }}">{{ strtoupper(\App\Inventory\Inventory::formatFor($connection['path'])) }}</span>
            </p>
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('inventory.compare') }}">Compare now</a>
                <form method="POST" action="{{ route('inventory.disconnect') }}">
                    @csrf @method('DELETE')
                    <button class="btn-danger" type="submit" data-confirm>Disconnect</button>
                </form>
            </div>
            <p class="hint">
                Commits are made as {{ $connection['author_name'] }} &lt;{{ $connection['author_email'] }}&gt;.
                The access token is stored encrypted and never shown, exported or sent to an MCP client.
            </p>
        </div>
    @endif

    <form class="card" method="POST" action="{{ route('inventory.connect') }}">
        @csrf
        <h2>{{ $connection ? 'Change connection' : 'Connect the inventory repository' }}</h2>

        <div class="grid2">
            <div class="field">
                <label for="repo_url">Repository address <span class="hint">https only</span></label>
                <input id="repo_url" name="repo_url" type="text" required placeholder="https://git.example.com/org/inventory.git" value="{{ old('repo_url', $connection['url'] ?? '') }}">
            </div>
            <div class="field">
                <label for="branch">Branch</label>
                <input id="branch" name="branch" type="text" required value="{{ old('branch', $connection['branch'] ?? 'main') }}">
            </div>
            <div class="field">
                <label for="path">Inventory file <span class="hint">path inside the repository</span></label>
                <input id="path" name="path" type="text" required placeholder="inventory/hosts.yml" value="{{ old('path', $connection['path'] ?? 'inventory/hosts.yml') }}">
            </div>
            <div class="field">
                <label for="username">Git user name <span class="hint">the account that owns the token</span></label>
                <input id="username" name="username" type="text" required autocomplete="off" value="{{ old('username', $connection['username'] ?? 'git') }}">
            </div>
            <div class="field">
                <label for="token">Personal access token <span class="hint">{{ $connection ? 'leave blank to keep the saved one (same repository only)' : 'needs read and write access to the repository contents' }}</span></label>
                <input id="token" name="token" type="password" autocomplete="new-password" @required(! $connection)>
            </div>
            <div class="field">
                <label for="author_name">Commit author name</label>
                <input id="author_name" name="author_name" type="text" required value="{{ old('author_name', $connection['author_name'] ?? 'LabMan') }}">
            </div>
            <div class="field">
                <label for="author_email">Commit author e-mail</label>
                <input id="author_email" name="author_email" type="text" required value="{{ old('author_email', $connection['author_email'] ?? 'labman@localhost') }}">
            </div>
        </div>

        <p class="hint">
            LabMan tests the connection (clone, find the file, read it) before saving.
            It reads both YAML and INI inventories and always writes YAML: if your inventory is INI, the first write
            creates the YAML file next to it and leaves the INI file alone unless you choose to delete it.
        </p>
        <button class="primary" type="submit">Test and save</button>
    </form>
@endsection
