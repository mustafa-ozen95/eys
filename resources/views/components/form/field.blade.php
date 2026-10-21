@props(['name', 'label', 'hint' => null, 'id' => null])

{{-- class dışındaki öznitelikler (hidden, data-*) sarmalayıcıya geçer. --}}
<div {{ $attributes->except('id')->class('field') }}>
    <label class="field__label" for="{{ $id ?? $name }}">{{ $label }}</label>

    {{ $slot }}

    @if ($hint)
        <span class="field__hint">{{ $hint }}</span>
    @endif

    @error($name)
        <span class="field__error">{{ $message }}</span>
    @enderror
</div>
