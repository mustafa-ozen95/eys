@props(['title' => null, 'subtitle' => null, 'flush' => false])

<section {{ $attributes->class('panel') }}>
    @if ($title || isset($actions))
        <header class="panel__head">
            @if ($title)
                <div>
                    <h2 class="panel__title">{{ $title }}</h2>
                    @if ($subtitle)
                        <p class="text-sm text-muted">{{ $subtitle }}</p>
                    @endif
                </div>
            @endif

            @isset($actions)
                <div class="panel__actions">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['panel__body', 'panel__body--flush' => $flush])>{{ $slot }}</div>
</section>
