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
const INTERVAL = 10;

const MAX_WAIT = 20000;

const WINDOW = 'eys-portal';

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
const LOGOUT_WAIT = 5000;

/** Bu form kendi penceresini ister mi? */
function wantsWindow(form) {
    return form.dataset.newWindow !== undefined || form.dataset.portalLanding !== undefined;
}

/** Pencere bizim kökenimizden ayrıldı mı? Ayrıldıysa yanıt gelmiş demektir. */
function left(popup) {
    try {
        const url = popup.location.href;

        return url !== 'about:blank' && new URL(url).origin !== window.location.origin;
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
function startClock(tick) {
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
    const identity = setInterval(tick, INTERVAL);
    let worker = null;

    try {
        const code = `setInterval(() => postMessage(0), ${INTERVAL});`;

        worker = new Worker(URL.createObjectURL(new Blob([code], { type: 'text/javascript' })));
        worker.onmessage = tick;
    } catch {
        /* İşçi kurulamadı; sıradan zamanlayıcı zaten çalışıyor. */
        worker = null;
    }

    return () => {
        clearInterval(identity);
        worker?.terminate();
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
function handOver(popup, target, wait = 0) {
    const started = Date.now();
    let leaveMoment = null;

    const stop = startClock(() => {
        if (popup.closed) {
            stop();

            return;
        }

        if (leaveMoment === null) {
            if (!left(popup) && Date.now() - started < MAX_WAIT) {
                return;
            }

            leaveMoment = Date.now();
        }

        /*
         * Cihaz kendi akışını tamamlasın: bu sürede oturum çerezi yazılıyor
         * ve tarayıcı çoğu zaman zaten arayüze düşüyor. Devir o zaman bir
         * şeyi bozmaz; düşmediyse hata sayfasından alır.
         */
        if (Date.now() - leaveMoment < wait) {
            return;
        }

        stop();
        popup.location.href = target;
    });
}

/** Fırlatma penceresini açar; gerekiyorsa devri de başlatır. */
function prepare(form) {
    /*
     * Her AÇILIŞ kendi penceresini alır; ad her seferinde yenilenir.
     *
     * Önce cihaz başına sabit bir ad kullanılıyordu ve ikinci tıklamada aynı
     * pencere YENİDEN KULLANILIYORDU. Tek kullanımlık giriş akışları bundan
     * zarar görüyor: vCenter'ın SAML gönderimi o pencerede zaten tüketilmiş
     * bir oturumun üstüne düşüp 401 veriyordu.
     * Ayrı pencere, ayrı akış.
     */
    const name = `${WINDOW}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
    const popup = window.open('', name);

    /*
     * Açılamadıysa (engelleyici) hiçbir şey yapılmaz: form kendi hedefine
     * gider, kullanıcı JSON sayfasında kalır ama oturumu yine açılmış olur.
     */
    if (!popup) {
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
        popup.opener = null;
    } catch {
        // Tarayıcı izin vermezse eskisi gibi devam: akış bozulmamalı.
    }

    form.target = name;

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
    if (form.dataset.portalPrevious) {
        popup.location.href = form.dataset.portalPrevious;

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
        const started = Date.now();

        const stop = startClock(() => {
            if (Date.now() - started < LOGOUT_WAIT) {
                return;
            }

            stop();

            if (popup.closed) {
                return;
            }

            form.submit();

            if (form.dataset.portalLanding) {
                handOver(popup, form.dataset.portalLanding, Number(form.dataset.portalLandingWait ?? 0));
            }
        });

        return;
    }

    /* Devir yalnızca giriş isteğine VERİ döndüren cihazlarda gerekiyor. */
    if (form.dataset.portalLanding) {
        handOver(popup, form.dataset.portalLanding, Number(form.dataset.portalLandingWait ?? 0));
    }
}

export function init() {
    /*
     * Onaylı formlar: onay penceresi kapandıktan sonra confirm.js haber verir.
     * Sekme ancak burada açılır — kullanıcı onaylamadan (hatta vazgeçtiğinde)
     * boş sekme açan bir sürüm olmuştu.
     */
    const selectorText = 'form[data-portal-landing], form[data-new-window]';

    on('eys:confirmed', selectorText, (event, form) => prepare(form));

    /* Onay istemeyen bir form eklenirse o da çalışsın. */
    on('submit', selectorText, (event, form) => {
        if (form.dataset.confirm === undefined) {
            prepare(form);
        }
    });
}
