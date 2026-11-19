<div class="listedit-row">
    <input type="text" name="{{ $name }}[]" value="{{ $value }}" maxlength="253" autocomplete="off" spellcheck="false" placeholder="{{ $placeholder ?? '' }}" aria-label="{{ $placeholder ?? 'Value' }}">
    @if ($withDefault)
        <label class="listedit-default"><input type="radio" name="{{ $defaultName }}" value="{{ $value }}" @checked($checked)> default</label>
    @else
        <span class="listedit-primary">primary</span>
    @endif
    <button type="button" class="btn btn-small listedit-remove" data-remove aria-label="Remove">Remove</button>
</div>
