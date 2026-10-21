@props(['submit' => 'Kaydet', 'cancel' => 'Vazgeç'])

{{--
    Pencere altlığı.

    Her pencerede aynı iki düğme durur; metin dışında hiçbiri farklı değil
    ve elle yazılırsa biri değişince diğerleri sessizce ayrışır.

    Ek düğme (silme, kopyalama) slot'a konur ve vazgeçin SOLUNDA durur.
    Yalnızca kapatan pencerelerde :submit="false" verilir.

    DİKKAT: sentinel null DEĞİL false'tur. Blade @props varsayılanı "??" ile
    uygular, yani açıkça null geçmek de varsayılana düşer ve salt okunur
    pencerede "Kaydet" düğmesi belirir.
--}}
<footer {{ $attributes->class('modal__footer') }}>
    {{ $slot }}

    <button type="button" class="btn" data-modal-close>{{ $cancel }}</button>

    @if ($submit)
        <button type="submit" class="btn btn--primary">{{ $submit }}</button>
    @endif
</footer>
