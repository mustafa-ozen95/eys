@props(['title', 'subtitle' => null, 'open' => false])

{{--
    Katlanır panel.

    Hepsi birden açık olunca ekran, birkaç kayıtta bile okunamaz bir yığına
    dönüşür; günlük iş "neler var" sorusu, ayrıntı nadiren gerekiyor.

    <details> seçildi çünkü betik yüklenmese de çalışır ve tarayıcının kendi
    davranışıdır.
--}}
<details {{ $attributes->class('katlanir') }} @if ($open) open @endif>
    <summary class="katlanir__baslik">
        {{ $onek ?? '' }}
        <span class="katlanir__ad">{{ $title }}</span>

        @if ($subtitle)
            <span class="katlanir__ek text-sm text-muted">{{ $subtitle }}</span>
        @endif

        {{ $actions ?? '' }}
    </summary>

    <div class="katlanir__govde">{{ $slot }}</div>
</details>
