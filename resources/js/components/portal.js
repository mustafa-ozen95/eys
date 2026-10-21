import { on } from '../lib/dom.js';

/**
 * Portal oturumu: girişten sonra cihazın arayüzüne devretme.
 *
 * Kimi cihaz giriş isteğine SAYFA DEĞİL VERİ döndürür. iLO 4 böyle: giriş
 * gövdesi kabul edilir, oturum çerezi yazılır, ama tarayıcı arayüz yerine ham
 * JSON'un üstünde kalır. Kullanıcı oradan arayüze elle gitmek zorundaydı.
 *
 * "Çerez yazıldı" anı TAHMİN EDİLMEZ, GÖZLENİR. Açtığımız pencere önce bizim
 * kökenimizdedir ve adresi okunabilir; cihaz yanıt verip sayfa değiştiği anda
 * aynı okuma güvenlik hatası atar. Tarayıcı sayfayı ancak yanıt başlıklarını
 * aldıktan sonra değiştirdiğine göre o hata "Set-Cookie işlendi" demektir.
 * Sabit bir bekleme yazsaydık yavaş bir cihazda çerezsiz sayfaya düşerdik.
 *
 * Nereye gidileceğine sunucu karar verir (bkz. PortalLogin::$landing); burada
 * yalnızca pencere yönetiliyor.
 */

/*
 * Yoklama sık: pencere kökeni değiştirdiği an yakalanmalı. Geç kalırsak
 * cihazın JSON yanıtı EKRANDA KALIR ve kullanıcı ham veriyi görür.
 */
const ARALIK = 10;

const AZAMİ_BEKLEME = 20000;

const PENCERE = 'eys-portal';

/*
 * Cihaz arayüzleri SEKMEDE açılır.
 *
 * Bir süre ayrı pencerede açıldı: gerekçe "panel ile arayüz yan yana dursun"
 * idi. Kullanımda tersi çıktı: arayüzün kendi
 * açtığı sayfalar — iLO'nun HTML5 konsolu gibi — ana pencerede sekme olarak
 * açılıyor, arayüzün kendisi ayrı pencerede kalıyor ve ikisi birbirini
 * kaybediyordu. Sekme hepsini aynı yerde tutuyor.
 *
 * ÖLÇÜ VERİLMEZ: özellik listesi verilen her çağrıyı tarayıcı AÇILIR PENCERE
 * sayıyor. Ad yine veriliyor — formun hedefi o.
 *
 * Guacamole konsolu da aynı yolu izliyor: o da sekmede açılıyor
 * (bkz. remote-session.js).
 */

/*
 * Cihazdaki oturumun kapanması için beklenen süre.
 *
 * Çıkış tek istek değil, yönlendirme zinciri; daha kısa bir bekleme zincirin
 * bitmesine yetmeyebilir ve açılış 401 alır. Beklemek açılışı geciktiriyor
 * ama hata sayfasına düşmekten iyidir.
 */
const CIKIS_BEKLEMESI = 5000;

/** Bu form kendi penceresini ister mi? */
function pencereIster(form) {
    return form.dataset.yeniPencere !== undefined || form.dataset.portalLanding !== undefined;
}

/** Pencere bizim kökenimizden ayrıldı mı? Ayrıldıysa yanıt gelmiş demektir. */
function ayrildi(pencere) {
    try {
        const adres = pencere.location.href;

        return adres !== 'about:blank' && new URL(adres).origin !== window.location.origin;
    } catch {
        /* Okuma engellendi: pencere artık başka bir kökende. */
        return true;
    }
}

/**
 * Kısılmayan zamanlayıcı.
 *
 * ÖNEMLİ: yeni sekme öne geçtiği an panel sekmesi GİZLENİR ve Chrome gizli
 * sekmede setInterval'i saniyede bire kısar. Yoklama orada durduğu için
 * kullanıcı ham JSON'u bir iki saniye görüyordu. İşçi
 * iş parçacığındaki zamanlayıcı bu kısıtlamaya girmez.
 *
 * İşçi kurulamazsa (blob engelli bir ortam) sıradan zamanlayıcıya düşülür:
 * geç ama çalışır.
 *
 * @param  {() => void} tik
 * @return {() => void} durdurucu
 */
function saatKur(tik) {
    /*
     * İKİSİ BİRDEN KURULUR.
     *
     * İşçi KURULAMAYABİLİR ve bunu SESSİZCE yapar: panelin içerik güvenliği
     * politikası blob'dan işçi açmayı engellediğinde `new Worker` hata
     * FIRLATMIYOR — betiğin yüklenmesi sonradan engelleniyor ve işçi hiç tık
     * atmıyor. try/catch bu durumu yakalayamaz; yedek zamanlayıcı hiç
     * devreye girmez ve iş sessizce asılı kalır.
     *
     * Belirti (vCenter ikinci açılış): konsolda
     * "Creating a worker from 'blob:…' violates the following Content
     * Security Policy directive" yazıyor, devir hiç çalışmıyor ve kullanıcı
     * "[401] This request requires HTTP authentication" sayfasında kalıyordu.
     *
     * Çözüm: sıradan zamanlayıcı HER ZAMAN kurulur (gizli sekmede saniyede
     * bire kısılır ama çalışır), işçi de kurulabiliyorsa onun yanında
     * çalışır ve ince ayrıntıyı o yakalar. Durdurucu ikisini birden kapatır.
     */
    const kimlik = setInterval(tik, ARALIK);
    let isci = null;

    try {
        const kod = `setInterval(() => postMessage(0), ${ARALIK});`;

        isci = new Worker(URL.createObjectURL(new Blob([kod], { type: 'text/javascript' })));
        isci.onmessage = tik;
    } catch {
        /* İşçi kurulamadı; sıradan zamanlayıcı zaten çalışıyor. */
        isci = null;
    }

    return () => {
        clearInterval(kimlik);
        isci?.terminate();
    };
}

/**
 * @param {Window} pencere
 * @param {string} hedef
 * @param {number} bekle  Kökeni değiştirdikten SONRA beklenecek süre (ms).
 *                        vCenter'da şart: cihazın yanıtı kendi kendini
 *                        yollayan bir SAML formudur ve hemen devralmak o
 *                        gönderimi yarıda kesiyor (bkz. PortalLogin::
 *                        inisGecikmesi).
 */
function devret(pencere, hedef, bekle = 0) {
    const basladi = Date.now();
    let ayrilmaAni = null;

    const durdur = saatKur(() => {
        if (pencere.closed) {
            durdur();

            return;
        }

        if (ayrilmaAni === null) {
            if (!ayrildi(pencere) && Date.now() - basladi < AZAMİ_BEKLEME) {
                return;
            }

            ayrilmaAni = Date.now();
        }

        /*
         * Cihaz kendi akışını tamamlasın: bu sürede oturum çerezi yazılıyor
         * ve tarayıcı çoğu zaman zaten arayüze düşüyor. Devir o zaman bir
         * şeyi bozmaz; düşmediyse hata sayfasından alır.
         */
        if (Date.now() - ayrilmaAni < bekle) {
            return;
        }

        durdur();
        pencere.location.href = hedef;
    });
}

/** Fırlatma penceresini açar; gerekiyorsa devri de başlatır. */
function hazirla(form) {
    /*
     * Her AÇILIŞ kendi penceresini alır; ad her seferinde yenilenir.
     *
     * Önce cihaz başına sabit bir ad kullanılıyordu ve ikinci tıklamada aynı
     * pencere YENİDEN KULLANILIYORDU. Tek kullanımlık giriş akışları bundan
     * zarar görüyor: vCenter'ın SAML gönderimi o pencerede zaten tüketilmiş
     * bir oturumun üstüne düşüp 401 veriyordu.
     * Ayrı pencere, ayrı akış.
     */
    const ad = `${PENCERE}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
    const pencere = window.open('', ad);

    /*
     * Açılamadıysa (engelleyici) hiçbir şey yapılmaz: form kendi hedefine
     * gider, kullanıcı JSON sayfasında kalır ama oturumu yine açılmış olur.
     */
    if (!pencere) {
        return;
    }

    /*
     * HEDEF SAYFA PANELİ `window.opener` İLE GÖRMESİN.
     *
     * `noopener` özelliğiyle açmak elde tutamaç bırakmıyor (aşağıda pencerenin
     * konumunu kendimiz sürüyoruz), o yüzden bağ AÇILDIKTAN SONRA kesilir.
     * Pencere bu anda hâlâ boş ve aynı kökende (about:blank); henüz hiçbir
     * dış sayfa yüklenmedi, atama bu yüzden izinli. Kesilmezse giriş yapılan
     * dış sitenin betiği opener üzerinden panel sekmesini benzer bir giriş
     * sayfasına yönlendirebilirdi.
     */
    try {
        pencere.opener = null;
    } catch {
        // Tarayıcı izin vermezse eskisi gibi devam: akış bozulmamalı.
    }

    form.target = ad;

    /*
     * ÖNCE CİHAZDAKİ ESKİ OTURUM KAPATILIR.
     *
     * vCenter, açık bir oturum dururken yeni bir giriş gönderimini kabul
     * etmiyor ve "[401] This request requires HTTP authentication" diyor;
     * kullanıcı o hata sayfasında kalıyordu (ilk açılış çalışıyor, aynı
     * tarayıcıda ikincisi düşüyor).
     *
     * Çıkışı GİZLİ ÇERÇEVEDE yapmak işe yaramadı — oradaki çerezler üçüncü
     * taraf sayılıyor. Pencerenin KENDİSİNİ çıkış adresine götürmek ise
     * birinci taraf: çerez gerçekten temizleniyor. Gönderim ondan sonra
     * yapılır; bu yüzden confirm.js bu formu göndermez (bkz. oradaki not).
     */
    if (form.dataset.portalOnceki) {
        pencere.location.href = form.dataset.portalOnceki;

        /*
         * ÇIKIŞIN TAMAMLANMASI BEKLENİR.
         *
         * Cihazın çıkışı tek istek değil: yönlendirme zinciri var. Erken
         * gönderirsek oturum hâlâ duruyor ve arayüz yeni belgeyi reddedip
         * 401 veriyor; denemelerin bir kısmı böyle düşüyordu.
         *
         * Bekleme İŞÇİ ZAMANLAYICISIYLA yapılır: yeni pencere öne geçtiği an
         * panel sekmesi gizleniyor ve Chrome gizli sekmede setTimeout'u
         * kısıyor (aynı sorun devret() için de yaşanmıştı).
         */
        const basladi = Date.now();

        const durdur = saatKur(() => {
            if (Date.now() - basladi < CIKIS_BEKLEMESI) {
                return;
            }

            durdur();

            if (pencere.closed) {
                return;
            }

            form.submit();

            if (form.dataset.portalLanding) {
                devret(pencere, form.dataset.portalLanding, Number(form.dataset.portalLandingBekle ?? 0));
            }
        });

        return;
    }

    /* Devir yalnızca giriş isteğine VERİ döndüren cihazlarda gerekiyor. */
    if (form.dataset.portalLanding) {
        devret(pencere, form.dataset.portalLanding, Number(form.dataset.portalLandingBekle ?? 0));
    }
}

export function init() {
    /*
     * Onaylı formlar: onay penceresi kapandıktan sonra confirm.js haber verir.
     * Sekme ancak burada açılır — kullanıcı onaylamadan (hatta vazgeçtiğinde)
     * boş sekme açan bir sürüm olmuştu.
     */
    const secici = 'form[data-portal-landing], form[data-yeni-pencere]';

    on('eys:onaylandi', secici, (event, form) => hazirla(form));

    /* Onay istemeyen bir form eklenirse o da çalışsın. */
    on('submit', secici, (event, form) => {
        if (form.dataset.confirm === undefined) {
            hazirla(form);
        }
    });
}
