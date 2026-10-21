@props(['name', 'value' => null, 'placeholder', 'label'])

{{-- Büyüteç ve giriş; çerçeveyi saran öğe çizer. --}}
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
    <circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" stroke-linecap="round" />
</svg>

<input class="filter__input" type="search" name="{{ $name }}" value="{{ $value }}"
       placeholder="{{ $placeholder }}" aria-label="{{ $label }}">
