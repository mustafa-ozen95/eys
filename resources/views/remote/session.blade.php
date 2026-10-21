<x-layout.gate :title="$session['label'].' oturumu'" wide>
    <div class="remote-session" data-oturum-jetonu="{{ $token }}">
        <header class="remote-session__head">
            <span class="dot dot--green"></span>
            <span class="mono">{{ $session['label'] }}</span>
            <x-ui.badge variant="blue">{{ Str::upper($session['protocol']) }}</x-ui.badge>

            <span class="spacer"></span>

            {{--
                "Oturumu kapat" DÜĞMESİ YOK.

                Düğme tek işi yapıyordu: oturumu kapatma ucuna haber verip
                pencereyi kapatmak. Sekmenin çarpısı zaten ikisini de yapıyor —
                `pagehide` olayında sendBeacon aynı uca gidiyor ve kapanış
                denetim defterine "Konsol kapatıldı" diye düşüyor; beacon
                kaçarsa paneli açan sekme `pencere.closed`'ı görüp aynı ucu
                çağırıyor (bkz. remote-session.js).

                Yani düğme, kullanıcının zaten yaptığı hareketin ikinci bir
                kopyasıydı ve konsolun üstünde yer kaplıyordu.
            --}}
        </header>

        {{--
            KLAVYE ODAK İSTİYOR.

            Guacamole ekranda fareye basıldığında `preventDefault()` çağırıyor
            (metin seçilmesin diye) ve tarayıcıda mousedown'da preventDefault
            ODAK DEĞİŞİMİNİ DE engeller. Guacamole kendi sayfasında açıldığında
            belge zaten odaktadır ve bu görünmez; İFRAME İÇİNDE odak ana
            sayfada kalır, tuşlar uzak makineye hiç ulaşmaz.

            Odağı ana sayfa veriyor: çerçeve yüklenince ve fare üstüne
            gelince (bkz. remote-session.js).
        --}}
        {{--
            ADRES `data-src`TE, çerçeveyi remote-session.js açar.

            Guacamole açılırken tarayıcıda kayıtlı jetonu (localStorage
            "GUAC_AUTH") okuyor; bu jeton bir ÖNCEKİ sekmenin. Kendi jetonu
            farklı olduğu için öncekini İPTAL ediyor (DELETE api/session) ve
            o sekmenin bağlantısı kopuyordu: aynı anda ikinci bağlantı
            birincisini düşürüyordu. Açık sekmeler
            jetonu bellekte tuttuğu için kaydı silmek onlara dokunmaz.
            Satır içi betik CSP'de yasak; bu yüzden src'yi betik veriyor.
        --}}
        <iframe class="remote-session__frame"
                data-src="{{ $session['path'] }}"
                title="{{ $session['label'] }} uzak oturumu"
                data-oturum-cercevesi
                allow="clipboard-read; clipboard-write"></iframe>
    </div>
</x-layout.gate>
