<span {{ $attributes->class('status') }}>
    <span class="dot dot--{{ $variant->value }}" aria-hidden="true"></span>
    @if ($showLabel)
        {{ $label }}
    @else
        <span class="visually-hidden">{{ $label }}</span>
    @endif
</span>
