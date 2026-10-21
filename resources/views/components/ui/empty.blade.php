@props(['title' => 'Kayıt bulunamadı', 'text' => null])

<div {{ $attributes->class('empty') }}>
    <span class="empty__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <circle cx="11" cy="11" r="7" />
            <path d="M20 20l-3.5-3.5" stroke-linecap="round" />
        </svg>
    </span>
    <p class="empty__title">{{ $title }}</p>
    @if ($text)
        <p class="empty__text">{{ $text }}</p>
    @endif
    {{ $slot }}
</div>
