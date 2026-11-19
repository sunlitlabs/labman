{{-- A searchable icon picker. $model (Service|Server, maybe unsaved), $value (stored icon: '', 'none' or a library name) --}}
@php
    $value = (string) $value;
    $auto = $model->exists ? \App\Icons\Icons::slugFor(new (get_class($model))(['name' => $model->name, 'domain' => $model->domain] + ($model instanceof \App\Models\Server ? ['address' => $model->address] : []))) : null;
    $shown = $value === 'none' ? null : ($value !== '' ? $value : $auto);
    $label = $value === 'none' ? 'No icon' : ($value !== '' ? $value : 'Automatic'.($auto ? ' ('.$auto.')' : ''));
@endphp
<div class="iconpick" data-iconpick data-search-url="{{ route('icons.search') }}" data-icon-url="{{ url('/icons') }}" data-seed-from="name" data-auto="{{ $auto }}">
    <input type="hidden" id="icon" name="icon" value="{{ $value }}">
    <button type="button" class="iconpick-toggle" aria-haspopup="listbox" aria-expanded="false" data-toggle>
        <span class="ico ico-sm" aria-hidden="true">@if ($shown)<img class="ico-img" src="{{ route('icons.show', $shown) }}" alt="" data-current>@endif</span>
        <span class="iconpick-label" data-label>{{ $label }}</span>
        <span class="iconpick-caret" aria-hidden="true">▾</span>
    </button>
    <div class="iconpick-panel" hidden>
        <input type="search" class="iconpick-search" placeholder="Search the icon library…" autocomplete="off" spellcheck="false" aria-label="Search the icon library" data-search>
        <ul class="iconpick-list" role="listbox" data-list>
            <li><button type="button" role="option" data-value="">Automatic <small>guess from the name{{ $auto ? ': '.$auto : '' }}</small></button></li>
            <li><button type="button" role="option" data-value="none">No icon</button></li>
        </ul>
        <p class="iconpick-note muted" data-note></p>
    </div>
</div>
