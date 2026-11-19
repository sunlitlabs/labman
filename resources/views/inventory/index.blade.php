@extends('layouts.admin')

@section('title', 'Inventory')

@section('admin')
    <h1>Inventory (Ansible)</h1>
    <p class="muted">
        Keep LabMan's servers and the Ansible inventory in your git repository (the one Semaphore reads) lined up.
        LabMan shows what matches and what does not, and you decide, item by item, what changes on either side.
        Nothing is written until you have reviewed the exact diff.
    </p>

    @if ($connection)
        <div class="formcard">
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
                Signed in to the repository as <strong>{{ $connection['username'] }}</strong>; commits are made as {{ $connection['author_name'] }} &lt;{{ $connection['author_email'] }}&gt;.
                The access token is stored encrypted and never shown, exported or sent to an MCP client.
            </p>
        </div>
    @endif

    @php($open = $connection || $errors->hasAny(['path', 'branch', 'username']) || old('path') || old('branch'))
    <form class="formcard" method="POST" action="{{ route('inventory.connect') }}" id="connect-form" data-parse-url="{{ route('inventory.parse') }}">
        @csrf
        <h2>{{ $connection ? 'Change connection' : 'Connect your inventory' }}</h2>
        <p class="sub">Two things are enough: where the inventory file is, and a token that may push to that repository.</p>

        <div class="field">
            <label for="repo_url">Link to your inventory file or repository</label>
            <input id="repo_url" name="repo_url" type="text" required autocomplete="off" spellcheck="false"
                   placeholder="https://github.com/your-org/your-repo/blob/main/ansible/inventory/hosts.ini"
                   value="{{ old('repo_url', $connection['url'] ?? '') }}">
            <p class="hint">Paste the address from your browser. Links to a file on GitHub, GitLab, Gitea, Forgejo, Codeberg or Bitbucket work, and so do plain repository and <code>git@…</code> addresses.</p>
            <p id="detected" class="detected" hidden></p>
        </div>

        <div class="field">
            <label for="token">Access token <span class="hint" id="token-hint">{{ $connection ? 'leave blank to keep the saved one (same repository only)' : 'must be able to read and write the repository contents' }}</span></label>
            <input id="token" name="token" type="password" autocomplete="new-password" @required(! $connection)>
            <p class="hint">The account name is worked out from the token. <a id="token-help" href="#" target="_blank" rel="noopener noreferrer" hidden>Create a token →</a></p>
        </div>

        <details @if ($open) open @endif>
            <summary>Details <span class="muted">(filled in from the link, change only if needed)</span></summary>
            <div class="grid2 detail-grid">
                <div class="field">
                    <label for="branch">Branch</label>
                    <input id="branch" name="branch" type="text" placeholder="main" value="{{ old('branch', $connection['branch'] ?? '') }}">
                </div>
                <div class="field">
                    <label for="path">Inventory file <span class="hint">path inside the repository</span></label>
                    <input id="path" name="path" type="text" placeholder="ansible/inventory/hosts.ini" value="{{ old('path', $connection['path'] ?? '') }}">
                </div>
                <div class="field">
                    <label for="username">Git user name <span class="hint">leave blank to detect it from the token</span></label>
                    <input id="username" name="username" type="text" autocomplete="off" value="{{ old('username') }}">
                </div>
                <div class="field"></div>
                <div class="field">
                    <label for="author_name">Commit author name</label>
                    <input id="author_name" name="author_name" type="text" placeholder="LabMan" value="{{ old('author_name', $connection['author_name'] ?? '') }}">
                </div>
                <div class="field">
                    <label for="author_email">Commit author e-mail</label>
                    <input id="author_email" name="author_email" type="text" placeholder="labman@localhost" value="{{ old('author_email', $connection['author_email'] ?? '') }}">
                </div>
            </div>
        </details>

        <p class="hint">
            LabMan tests the connection (clone, find the file, read it) before saving. It reads YAML and INI inventories and always writes YAML:
            if yours is INI, the first write creates the YAML file next to it and leaves the INI file alone unless you choose to delete it.
        </p>
        <div class="savebar"><button class="primary" type="submit">Test and save</button></div>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('js/connect.js') }}" defer></script>
@endpush
