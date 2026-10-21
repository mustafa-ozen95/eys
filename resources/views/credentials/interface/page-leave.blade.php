/*
    Sayfadan ayrılış haberi.

    NEDEN VAR: cihazdaki oturumu panel açıyor (Plesk, iLO, vSphere) ya da
    arayüzün kendi betiği açıyor (ESXi, VAMI); ikisinde de kapatan olmazsa
    oturum cihazın kendi zaman aşımını bekliyor ve o süre boyunca listede
    duruyor. Art arda birkaç açılışta cihazın oturum listesi doluyordu
    (iLO'nun oturum sınırı kolayca dolar).

    Haber oturumu KAPATMAZ, "sesi kesildi" diye işaretler. Aynı olay sayfa
    yenilenirken de tetikleniyor ve tek bir F5 oturumu kapatmamalı: sayfa geri
    gelirse ilk istek işareti siler, gerçekten gidildiyse dakikalık temizlik
    oturumu kapatır ve cihazda da çıkış yapar.

    "pagehide" kullanılır, "unload" değil: tarayıcılar sayfayı geri/ileri
    önbelleğine alırken unload'ı hiç çalıştırmıyor.
*/
(() => {
    /*
     * YALNIZCA EN ÜST PENCEREDE. Betik cihazdan gelen her HTML belgesine
     * ekleniyor ve bu arayüzlerin içi çerçeve dolu (iLO'da masthead, gezinti,
     * içerik ayrı çerçeveler). Çerçeveler kendi aralarında sürekli gezindiği
     * için haber boşuna atılırdı; asıl soru "kullanıcı gitti mi" ve onun
     * cevabı en üst pencerededir.
     */
    if (window.top !== window.self) return;

    const ADRES = @json($ayrilisAdresi);

    /*
     * sendBeacon: sayfa kapanırken normal bir istek iptal ediliyor, beacon
     * ise tarayıcı tarafından teslim ediliyor. Yanıtı okunmaz, okunamaz da.
     */
    const bildir = () => {
        try {
            navigator.sendBeacon(ADRES);
        } catch (e) {
            /* Haber verilemedi: oturum yine de sessizlikten kapanacak. */
        }
    };

    addEventListener('pagehide', bildir);
})();
@if ($sekmeyeAc ?? false)

/*
    VM KONSOLU AÇILIR PENCEREDE DEĞİL SEKMEDE.

    vSphere konsolu açarken pencereye boyut veriyor ("width=…,height=…") ve
    tarayıcı boyut gören her çağrıyı AÇILIR PENCERE sayıyor: ayrı bir pencere,
    adres çubuğu yok, sekme listesinde görünmüyor. Boyut verilmezse aynı çağrı
    normal bir sekme açıyor.

    Sarmalayıcı yalnızca boyutu düşürür; adresi ve pencere adını olduğu gibi
    bırakır — adı kullanan kod (aynı VM'in konsolunu ikinci kez açmak gibi)
    eskisi gibi çalışır.

    Yalnızca vSphere ailesine eklenir: iLO gibi arayüzlerin kendi açılır
    pencereleri var ve onların boyutu işlerine yarıyor.
*/
(() => {
    const asilAc = window.open;

    window.open = function (adres, ad, ozellikler) {
        return asilAc.call(window, adres, ad || '_blank');
    };
})();
@endif
@if ($iloKonsolu ?? false)
@include('credentials.interface.ilo')
@endif

@if ($onekYolu ?? null)

/*
    ADRES ÇUBUĞU ÖNEK DIŞINA DÜŞERSE GERİ YAZILIR.

    Arayüzler bağlantılarını kökten MUTLAK veriyor: Plesk'te bir menüye
    tıklamak tarayıcıyı "/admin/subscription/list" adresine götürüyor ve önek
    düşüyor. Sayfa yine DOĞRU geliyor — o isteğin Referer'ı önekli olduğu için
    vekil oturumu oradan buluyor — ama o sayfadan çıkan isteklerin Referer'ı
    artık öneksiz ve zincir kopuyor; oturum yine çereze, yani en son açılan
    arayüze düşüyor.

    Adresi geri yazmak zinciri onarıyor. Sayfa yeniden YÜKLENMİYOR:
    replaceState yalnızca adres çubuğunu değiştiriyor, o kadar.

    YALNIZCA EN ÜST PENCEREDE: çerçevelerin kendi adresleri kendilerine ait ve
    onları değiştirmek arayüzün iç gezinmesini bozabilir.
*/
(() => {
    if (window.top !== window.self) return;

    const ONEK = @json($onekYolu, JSON_UNESCAPED_SLASHES);

    if (location.pathname === ONEK || location.pathname.startsWith(ONEK + '/')) return;

    /*
        SAYFA GEÇİŞLERİ DE ÖNEK ALTINDA KALIR.

        Arayüzler kendi içinde gezinirken sayfayı YENİDEN YÜKLEMİYOR;
        history.pushState ile adresi değiştiriyorlar ve verdikleri adres
        MUTLAK oluyor ("/admin/domain/list?context=domains"). Yeni belge
        yüklenmediği için bu betik bir daha çalışmıyor: adres önek dışında
        kalıyor, o sayfadan çıkan isteklerin Referer'ı öneksiz oluyor ve oturum
        yine çereze — yani son açılan arayüze — düşüyor. İki Plesk sekmesi tam
        burada karışıyordu: sayfa geçişlerinde bağlantı önekten çıkıyordu.

        Sarmalayıcı yalnızca ADRESİ düzeltir; durum nesnesine ve başlığa
        dokunmaz. Zaten önekli olan adres iki kez öneklenmez.
    */
    const yamala = (ad) => {
        const asil = history[ad].bind(history);

        history[ad] = function (durum, baslik, adres) {
            if (typeof adres === 'string' && adres.startsWith('/') && !adres.startsWith(ONEK + '/')) {
                adres = ONEK + adres;
            }

            return asil(durum, baslik, adres);
        };
    };

    yamala('pushState');
    yamala('replaceState');

    try {
        history.replaceState(history.state, '', ONEK + location.pathname + location.search + location.hash);
    } catch (e) {
        /* Tarayıcı izin vermediyse sayfa yine çalışır; yalnızca zincir kopar. */
    }
})();
@endif
