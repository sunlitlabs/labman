{{--
    The admin panel frame. Pages extend this and fill the "admin" section.
    Optional: @section('focus', '1') hides the sidebar for wide, step-by-step pages (compare, review, run)
    and shows a breadcrumb instead: @section('crumb', 'Inventory'), @section('crumb_url', route(...)).
--}}
@extends('layouts.app')

@section('content')
    @php($focus = View::hasSection('focus'))
    <div class="admin {{ $focus ? 'admin-focus' : '' }}">
        @unless ($focus)
            <aside class="adminnav" aria-label="Admin sections">
                <a class="adminnav-title" href="{{ route('admin.index') }}">Admin</a>
                @foreach (\App\Support\AdminNav::sections(auth()->user()) as $section)
                    <div class="adminnav-group">
                        <h3>{{ $section['title'] }}</h3>
                        @foreach ($section['items'] as $item)
                            <a href="{{ route($item['route']) }}" class="adminnav-item @if(request()->routeIs($item['match'])) is-active @endif" @if(request()->routeIs($item['match'])) aria-current="page" @endif title="{{ $item['hint'] }}">
                                <span>{{ $item['label'] }}</span>
                                @if ($item['badge'] > 0) <span class="adminnav-badge" aria-label="{{ $item['badge'] }} waiting">{{ $item['badge'] }}</span> @endif
                                @if ($item['status'] !== null) <span class="dot {{ $item['status'] ? 'dot-on' : 'dot-off' }}" title="{{ $item['status'] ? 'Connected' : 'Not connected' }}"></span><span class="sr-only">{{ $item['status'] ? 'connected' : 'not connected' }}</span> @endif
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </aside>
        @endunless

        <div class="admin-main">
            @if ($focus)
                <nav class="crumbs" aria-label="Breadcrumb">
                    <a href="{{ route('admin.index') }}">Admin</a>
                    @hasSection('crumb') <span aria-hidden="true">/</span> <a href="@yield('crumb_url')">@yield('crumb')</a> @endif
                </nav>
            @endif

            @yield('admin')
        </div>
    </div>
@endsection
