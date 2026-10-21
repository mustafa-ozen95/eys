{{--
    ARAYÜZ AÇILAMADI.

    Bu sayfa panelin değil CİHAZIN "hayır"ını anlatır: panelde her şey
    yerindeyken cihaz girişi yine de tutmayabilir — kasadaki parola eskimiştir,
    hesap kilitlenmiştir, cihaz kapalıdır. Bu bir çökme değil, bilinmesi
    gereken bir durum; kullanıcı "HTTP 500" görmemeli.

    Sayfa ayrı bir sekmede açılır (form target="_blank"), o yüzden listeye
    geri dönmek yerine kendi başına ayakta durabilmeli: hangi kayıt, hangi
    hesap, ne yapmalı.
--}}
<x-layout.gate title="Arayüz açılamadı">
    <div class="gate__mark gate__mark--uyari mono">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <path d="M12 3 3 7v5c0 5 3.8 8.4 9 9 5.2-.6 9-4 9-9V7l-9-4Z" stroke-linejoin="round" />
            <path d="M12 8.5v4.2M12 16.2h.01" stroke-linecap="round" />
        </svg>
        502
    </div>

    <p class="gate__lead">
        <strong>Arayüz açılamadı.</strong><br>
        Panel cihaza bağlandı ama oturum açamadı.
    </p>

    <p class="gate__yol mono">{{ $credential->name }} · {{ $credential->username }}</p>

    {{--
        SEBEP YAZILIR.

        "Bir hata oluştu" demek kullanıcıyı yöneticiye yollamaktan başka işe
        yaramıyor. Cihazın durum kodundan okunabilen kadarı burada: 401 parola,
        404 adres, 5xx cihazın kendi arızası. Cihazın gövdesi basılmaz —
        okunmaz olur ve gereksiz bilgi sızdırır.
    --}}
    <p class="gate__lead gate__lead--kucuk">{{ $sebep }}</p>

    <p class="gate__lead gate__lead--kucuk">
        Deneme kayda geçti; panel yöneticisi kayıtlar ekranından görebilir.
    </p>

    <a href="{{ url('/erisim') }}" class="btn btn--primary btn--block">Kayıtlara dön</a>
</x-layout.gate>
