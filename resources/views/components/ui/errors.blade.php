{{--
    Doğrulama hatalarının listesi.

    Alan altındaki tek satırlık uyarı uzun formda gözden kaçıyor: hata
    ekranın altında kalıyor, kullanıcı "kaydetmedi ama neden" diye bakıyordu.
    Blok hem sayfa formunda hem pencerede aynı yerde, aynı biçimde durur.
--}}
@if ($errors->any())
    <div {{ $attributes->class(['alert', 'alert--danger']) }}>
        <ul class="alert__list">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
