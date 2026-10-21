@props(['title', 'subtitle' => null])

<header {{ $attributes->class('page-head') }}>
    <div class="page-head__text">
        <h1>{{ $title }}</h1>
        @if ($subtitle)
            <p class="page-head__subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="page-head__actions">{{ $actions }}</div>
    @endisset
</header>
