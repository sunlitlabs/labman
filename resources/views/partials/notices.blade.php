@php($notices = \App\Models\Notice::open())
@if ($notices->isNotEmpty())
    <div class="notices" role="region" aria-label="Notices">
        @foreach ($notices as $notice)
            <div class="notice-bar" role="status">
                <div>
                    <strong>{{ $notice->title }}</strong>
                    @if ($notice->body)<div class="muted">{{ $notice->body }}</div>@endif
                    @if ($notice->url)<a href="{{ $notice->url }}">Take a look</a>@endif
                </div>
                <form method="POST" action="{{ route('notices.dismiss', $notice) }}">
                    @csrf
                    <button type="submit" class="btn" aria-label="Dismiss: {{ $notice->title }}">Dismiss</button>
                </form>
            </div>
        @endforeach
    </div>
@endif
