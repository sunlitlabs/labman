@extends('layouts.admin')

@section('title', 'Import and export')

@section('admin')
    <h1>Import and export</h1>
    <p class="muted">Your servers, services and settings as one YAML file. Importing adds or updates by name; nothing is deleted unless you ask for it.</p>

    <div class="formcard">
        <h2>Export</h2>
        <p class="sub">Download everything as <code>labman.yml</code>. Secrets (tokens, passwords) are never included.</p>
        <form method="GET" action="{{ route('settings.export') }}">
            <label class="check">
                <input type="checkbox" name="suggest_domains" value="1" @disabled(! \App\Support\DomainSuggestions::available())>
                <span>Suggest a local domain for every service and server that has none
                    <span class="hint">built from its name under your default local domain{{ \App\Support\DomainSuggestions::available() ? ' ('.\App\Support\Domains::defaultFor('local').')' : '; set one under Settings > General first' }}. Off by default; the file only, nothing is saved.</span></span>
            </label>
            <button class="btn btn-primary" type="submit">Download labman.yml</button>
        </form>
    </div>

    <form class="formcard" method="POST" action="{{ route('settings.import') }}" enctype="multipart/form-data">
        @csrf
        <h2>Import</h2>
        <p class="sub">Paste YAML or choose a file. If anything in it is wrong, nothing is imported and every problem is listed.</p>
        <div class="field">
            <label for="yaml">Paste YAML</label>
            <textarea id="yaml" name="yaml" spellcheck="false" placeholder="servers:&#10;  - name: nas&#10;    address: 192.0.2.10&#10;services: []">{{ old('yaml') }}</textarea>
        </div>
        <div class="field">
            <label for="yaml_file">…or choose a file</label>
            <input id="yaml_file" name="yaml_file" type="file" accept=".yml,.yaml,text/yaml,text/plain">
        </div>
        <label class="check"><input type="checkbox" name="replace" value="1"> Replace: delete servers and services that are not in the file</label>
        <div class="savebar"><button class="primary" type="submit">Import</button></div>
    </form>

    <p class="hint">The same import and export is available on the command line: <code>php artisan labman:import &lt;file&gt;</code> and <code>labman:export &lt;file&gt;</code>.</p>
@endsection
