@extends('layouts.app')

@section('title', 'Diagnose '.$service->name)

@section('content')
    <div class="wide">
        <h1>Diagnose: {{ $service->name }}</h1>

        @if (isset($result['error']))
            <div class="card"><p>{{ $result['error'] }}</p></div>
        @else
            <div class="card">
                <p class="muted">Probed {{ $result['scheme'] }}://{{ $result['address'] }}:{{ $result['port'] }}{{ $result['path'] }} from LabMan (accepted statuses: {{ $result['expect'] }}).</p>
                <p><strong>{{ $result['message'] }}</strong></p>
                @foreach ($result['notes'] as $note)
                    <p class="muted">{{ $note }}</p>
                @endforeach
                @if (! empty($result['hint']['note']))
                    <p class="muted">{{ $result['hint']['app'] }}: {{ $result['hint']['note'] }}</p>
                @endif

                @if ($result['changes'])
                    <form method="POST" action="{{ route('services.diagnose.apply', $service) }}">
                        @csrf
                        <ul>
                            @foreach ($result['changes'] as $field => $value)
                                <li><code>{{ $field }}</code> → <code>{{ is_bool($value) ? ($value ? 'on' : 'off') : $value }}</code></li>
                                <input type="hidden" name="changes[{{ $field }}]" value="{{ is_bool($value) ? ($value ? 1 : 0) : $value }}">
                            @endforeach
                        </ul>
                        <button class="primary" type="submit">Apply these settings</button>
                    </form>
                @endif
            </div>

            <div class="card">
                <h2>Port</h2>
                <p>
                    <span class="pill {{ $result['port_result']['ok'] ? 'up' : 'down' }}">{{ $result['port_result']['ok'] ? 'open' : 'closed' }}</span>
                    {{ $result['port_result']['label'] ?? 'tcp' }} · {{ $result['port_result']['detail'] }}
                </p>
                @if ($result['other_ports'])
                    <p class="muted">Other ports open on this address:
                        @foreach ($result['other_ports'] as $o) :{{ $o['port'] }} ({{ $o['detail'] }})@if (! $loop->last), @endif @endforeach
                    </p>
                @endif
            </div>

            @if ($result['attempts'])
                <div class="card">
                    <h2>Web requests</h2>
                    <table class="list">
                        <thead><tr><th>Request</th><th>Result</th><th>Time</th></tr></thead>
                        <tbody>
                            @foreach ($result['attempts'] as $a)
                                <tr>
                                    <td><code>{{ $a['scheme'] }} {{ $a['path'] }}</code>@if ($a['host']) <span class="muted">Host: {{ $a['host'] }}</span>@endif</td>
                                    <td>{{ $a['status'] !== null ? 'HTTP '.$a['status'] : $a['detail'] }}</td>
                                    <td class="muted">{{ $a['ms'] !== null ? $a['ms'].' ms' : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        <div class="actions">
            <a class="btn" href="{{ route('services.edit', $service) }}">Back to the service</a>
        </div>
    </div>
@endsection
