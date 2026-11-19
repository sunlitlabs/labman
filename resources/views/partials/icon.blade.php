{{-- An icon for a service or server: a letter avatar, with the library icon on top when there is one. $model --}}
@php
    $slug = \App\Icons\Icons::slugFor($model);
    $letter = mb_strtoupper(mb_substr(trim($model->name), 0, 1)) ?: '?';
    $hue = crc32(mb_strtolower($model->name)) % 8;
@endphp
<span class="ico hue-{{ $hue }}" aria-hidden="true"><span class="ico-letter">{{ $letter }}</span>@if ($slug)<img class="ico-img" src="{{ route('icons.show', $slug) }}" alt="" loading="lazy" decoding="async">@endif</span>
