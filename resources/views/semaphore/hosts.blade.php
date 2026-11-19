@extends('layouts.admin')

@section('title', 'Hosts in Semaphore')

@section('focus', '1')
@section('crumb', 'Semaphore')
@section('crumb_url', route('semaphore.index'))

@section('admin')
    <h1>Hosts in Semaphore that LabMan does not have</h1>
    <p class="muted">
        Found in the Semaphore inventory{{ $known ? ' when LabMan last looked, '.\Illuminate\Support\Carbon::parse($known['at'])->diffForHumans() : '' }}.
        Add them to your server list, or ignore the ones that do not belong there (an ignored host is not mentioned again).
    </p>

    @if ($rows === [])
        <div class="card empty">Every host in Semaphore is either in LabMan's server list or ignored. <a href="{{ route('semaphore.index') }}">Back</a></div>
    @else
        <form method="POST" action="{{ route('semaphore.hosts.apply') }}">
            @csrf
            <div class="rows">
                @foreach ($rows as $row)
                    <div class="plan-item wiz-row">
                        <div class="plan-head">
                            <strong class="mono">{{ $row['host'] }}</strong>
                            <span class="muted">{{ $row['address'] }}@if ($row['group']) · group {{ $row['group'] }}@endif</span>
                        </div>
                        <div class="field">
                            <label for="name-{{ md5($row['host']) }}">Name in LabMan <span class="hint">{{ $row['clash'] ? 'a server with this name exists already, pick another' : 'how it appears in the server list' }}</span></label>
                            <input id="name-{{ md5($row['host']) }}" type="text" name="name[{{ $row['host'] }}]" value="{{ $row['host'] }}" maxlength="100" autocomplete="off">
                        </div>
                        <fieldset class="choices">
                            <legend class="sr-only">What to do with {{ $row['host'] }}</legend>
                            <label class="check"><input type="radio" name="choice[{{ $row['host'] }}]" value="add" @checked(! $row['clash'])> Add it to LabMan</label>
                            <label class="check"><input type="radio" name="choice[{{ $row['host'] }}]" value="ignore"> Ignore it</label>
                            <label class="check"><input type="radio" name="choice[{{ $row['host'] }}]" value="leave" @checked($row['clash'])> Decide later</label>
                        </fieldset>
                    </div>
                @endforeach
            </div>

            <div class="savebar">
                <button class="primary" type="submit">Apply my choices</button>
                <a class="btn" href="{{ route('semaphore.index') }}">Back</a>
            </div>
        </form>
    @endif
@endsection
