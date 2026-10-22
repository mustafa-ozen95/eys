@props(['title', 'subtitle' => null, 'open' => false])

{{--
    Katlanır panel.

    Hepsi birden açık olunca ekran, birkaç kayıtta bile okunamaz bir yığına
    dönüşür; günlük iş "neler var" sorusu, ayrıntı nadiren gerekiyor.

    <details> seçildi çünkü betik yüklenmese de çalışır ve tarayıcının kendi
    davranışıdır.
--}}
<details {{ $attributes->class('collapsible') }} @if ($open) open @endif>
    <summary class="collapsible__title">
        {{ $prefix ?? '' }}
        <span class="collapsible__name">{{ $title }}</span>

        @if ($subtitle)
            <span class="collapsible__extra text-sm text-muted">{{ $subtitle }}</span>
        @endif

        {{ $actions ?? '' }}
    </summary>

    <div class="collapsible__body">{{ $slot }}</div>
</details>
