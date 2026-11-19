@extends('layouts.admin')

@section('title', 'Review inventory changes')

@section('focus', '1')
@section('crumb', 'Inventory')
@section('crumb_url', route('inventory.index'))

@section('admin')
    <h1>Review before anything is written</h1>
    <p class="muted">
        This is exactly what will happen. The inventory commit is pushed first; LabMan is only updated if the push succeeds.
        If the repository or LabMan changes in the meantime, LabMan refuses and nothing is applied.
    </p>

    @if (! $review['changes'])
        <div class="card empty">Nothing would change. <a href="{{ route('inventory.compare') }}">Back to the comparison</a></div>
    @else
        <div class="grid2 review-cols">
            <div class="card">
                <h2>In the inventory repository</h2>
                @if ($review['inventory'] || $review['diff'])
                    <p class="muted">
                        Commit to <span class="mono">{{ $repo['branch'] }}</span> of <span class="mono">{{ $repo['url'] }}</span>
                        as {{ $repo['author_name'] }}.
                    </p>
                    <ul class="changes">
                        @foreach ($review['inventory'] as $line) <li>{{ $line }}</li> @endforeach
                    </ul>
                @else
                    <p class="muted">No change to the repository.</p>
                @endif
            </div>
            <div class="card">
                <h2>In LabMan</h2>
                @if ($review['labman'])
                    <ul class="changes">
                        @foreach ($review['labman'] as $line) <li>{{ $line }}</li> @endforeach
                    </ul>
                @else
                    <p class="muted">No server is created, changed or deleted</p>
                @endif
                <p class="hint">Matched servers are also linked to their inventory host so later syncs follow renames.</p>
            </div>
        </div>

        @if ($review['convert'])
            <div class="notice">
                <strong>Converting to YAML.</strong> The inventory is written to <span class="mono">{{ $review['convert']['to'] }}</span>.
                @if ($deleteOld)
                    <span class="mono">{{ $review['convert']['from'] }}</span> is deleted in the same commit: point Semaphore's inventory at the new file now, or its runs will fail.
                @else
                    <span class="mono">{{ $review['convert']['from'] }}</span> is left in place, so Semaphore keeps working until you change its inventory path to the new file.
                @endif
                LabMan will read the YAML file from now on.
            </div>
        @endif

        @if ($review['diff'])
            <div class="card">
                <h2>Diff</h2>
                <pre class="diff">@foreach (preg_split('/\R/', rtrim($review['diff'])) as $line)@php($kind = str_starts_with($line, '+++') || str_starts_with($line, '---') || str_starts_with($line, 'diff ') || str_starts_with($line, 'index ') || str_starts_with($line, 'new file') || str_starts_with($line, 'deleted file') ? 'dmeta' : (str_starts_with($line, '+') ? 'dadd' : (str_starts_with($line, '-') ? 'ddel' : (str_starts_with($line, '@@') ? 'dhunk' : ''))))<span class="{{ $kind }}">{{ $line }}</span>@endforeach</pre>
                <p class="hint">Comments and formatting in the inventory file are not preserved when LabMan rewrites it, so they show up in the diff.</p>
            </div>

            <div class="card">
                <h2>Commit message</h2>
                <pre class="code">{{ $review['commit_message'] }}</pre>
            </div>
        @endif

        <form method="POST" action="{{ route('inventory.apply') }}" class="actions plan-actions">
            @csrf
            <input type="hidden" name="choices" value="{{ $choices }}">
            <input type="hidden" name="fingerprint" value="{{ $review['fingerprint'] }}">
            @if ($deleteOld) <input type="hidden" name="delete_old" value="1"> @endif
            @if ($convert) <input type="hidden" name="convert" value="1"> @endif
            <button class="primary" type="submit" data-busy="{{ $review['diff'] ? 'Pushing to the repository…' : 'Applying…' }}">{{ $review['diff'] ? 'Commit, push and apply' : 'Apply to LabMan' }}</button>
            <a class="btn" href="{{ route('inventory.compare') }}">Back and change choices</a>
        </form>
    @endif
@endsection
