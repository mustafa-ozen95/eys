@props([
    'name' => 'q',
    'value' => null,
    'placeholder' => 'Ara',
    'label' => 'Filtrele',

    // Kendi formunu kurar mı? Kayıtlar ekranı gibi çok alanlı filtrelerde
    // kutu var olan formun içinde durur.
    'standalone' => true,
])

{{--
    Liste ekranlarının arama kutusu.

    Aynı büyüteç simgesi ve aynı giriş birden çok ekranda geçer; elle
    yazılırsa biri değişince diğerleri sessizce ayrışır.

    Görsel kutuyu (.filter) TEK bir öğe taşır: kendi formunu kuran biçimde
    form, kurmayanda etiket. İkisine birden verildiğinde iç içe iki çerçeve
    çiziliyor ve ekranda iki arama kutusu varmış gibi görünüyordu.
--}}
@if ($standalone)
    <form method="GET" {{ $attributes->class('filter column__filter') }}>
        <x-ui.search-field :name="$name" :value="$value" :placeholder="$placeholder" :label="$label" />
        {{ $slot }}
    </form>
@else
    <label {{ $attributes->class('filter') }}>
        <x-ui.search-field :name="$name" :value="$value" :placeholder="$placeholder" :label="$label" />
    </label>
@endif
