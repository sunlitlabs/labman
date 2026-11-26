{{-- Further addresses: existing ones plus a few blank rows (save to get more). Expects $model (may be a new, empty one). --}}
@php
    $rows = old('alternates', \App\Support\Alternates::of($model));
    $rows = array_values(is_array($rows) ? $rows : []);
    $blank = max(2, 3 - count($rows));
    for ($i = 0; $i < $blank && count($rows) < \App\Support\Alternates::MAX; $i++) { $rows[] = ['label' => '', 'address' => '', 'domain' => '']; }
@endphp
<div class="altrows">
    <div class="altrow althead" aria-hidden="true"><span>Label</span><span>Address</span><span>Domain <small class="hint">optional</small></span></div>
    @foreach ($rows as $i => $row)
        <div class="altrow">
            <input type="text" name="alternates[{{ $i }}][label]" maxlength="30" placeholder="Wi-Fi" aria-label="Label" value="{{ $row['label'] ?? '' }}">
            <input type="text" name="alternates[{{ $i }}][address]" maxlength="253" placeholder="192.0.2.45" aria-label="Address" autocomplete="off" value="{{ $row['address'] ?? '' }}">
            <input type="text" name="alternates[{{ $i }}][domain]" maxlength="253" placeholder="kvm-wifi.example.com" aria-label="Domain" autocomplete="off" value="{{ $row['domain'] ?? '' }}">
        </div>
    @endforeach
    @error('alternates.*.address')<p class="field-error">{{ $message }}</p>@enderror
    @error('alternates.*.domain')<p class="field-error">{{ $message }}</p>@enderror
</div>
