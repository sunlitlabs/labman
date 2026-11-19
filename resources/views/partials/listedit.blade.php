{{--
    An add / remove list of text values (domains). Posts $name[] in order; with $defaultName also posts the chosen default.
    $name, $values (list), $default (string|null), $defaultName (string|null), $placeholder, $addLabel,
    $suffix + $slugFrom (optional): "Add" pre-fills <slug of the #slugFrom field>.<suffix>
--}}
@php
    $defaultName = $defaultName ?? null;
    $default = $default ?? null;
    $placeholder = $placeholder ?? "";
    $values = array_values($values);
    $withDefault = ! empty($defaultName);
    $defaultValue = $default ?? ($values[0] ?? null);
    $row = function (string $value, bool $checked) use ($name, $defaultName, $withDefault, $placeholder) {
        return compact('value', 'checked', 'name', 'defaultName', 'withDefault', 'placeholder');
    };
@endphp
<div class="listedit" data-listedit @if (! empty($suffix)) data-suffix="{{ $suffix }}" @endif @if (! empty($slugFrom)) data-slug-from="{{ $slugFrom }}" @endif>
    <div class="listedit-rows">
        @foreach ($values as $value)
            @include('partials.listedit-row', $row($value, $withDefault && $value === $defaultValue))
        @endforeach
    </div>
    <template data-row>@include('partials.listedit-row', $row('', false))</template>
    <button type="button" class="btn btn-small" data-add>+ {{ $addLabel ?? 'Add' }}</button>
</div>
