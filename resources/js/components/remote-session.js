import { $, on } from '../lib/dom.js';
import { http } from '../lib/http.js';
import { emit } from './toast.js';

/**
 * Uzak oturum açma ve kapatma.
 *
 * Yalnızca sunucuya haber verir ve dönen adresi YENİ SEKMEDE açar; hangi
 * portun ayrılacağına, oturumun ne kadar yaşayacağına ve yetkiye sunucu karar
 * verir.
 *
 * Kapatma iki yoldan tetiklenir:
 *   1. Oturum sekmesinin kapanması (pagehide — çarpıya basmak dahil),
 *   2. Paneli açan sekmenin kapanması.
 *
 * İkisi de aynı ucu çağırır; sunucu ikinci çağrıda kayıt açmaz. Ayrı bir
 * "Oturumu kapat" düğmesi vardı; sekmenin çarpısının yaptığının kopyasıydı.
 */

const CANLI_TUTMA_ARALIGI = 4 * 60 * 1000;
const PENCERE_KONTROL_ARALIGI = 2000;
const KAPATMA_UCU = '/ajax/uzak-oturum/kapat';

const acikOturumlar = new Map();

function csrf() {
    return $('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Sayfa kapanırken çalışır: fetch iptal edilir, sendBeacon edilmez.
 */
function kapatBeacon(token) {
    navigator.sendBeacon?.(KAPATMA_UCU, new Blob(
        [JSON.stringify({ token, _token: csrf() })],
        { type: 'application/json' },
    ));
}

function canliTut(token, pencere) {
    const zamanlayici = setInterval(async () => {
        // Oturum penceresi kapandıysa canlı tutmanın anlamı yok.
        if (pencere?.closed) {
            durdur(token);

            return;
        }

        try {
            const cevap = await http.post('/ajax/uzak-oturum/canli-tut', { token }, { silent: true });

            if (!cevap.alive) {
                durdur(token);
            }
        } catch {
            durdur(token);
        }
    }, CANLI_TUTMA_ARALIGI);

    acikOturumlar.set(token, zamanlayici);

    izle(token, pencere);
}

/**
 * Pencerenin kapandığını yalnızca yoklayarak anlayabiliyoruz; kapanınca
 * kaydın da kapanması için sunucuya haber verilir.
 */
function izle(token, pencere) {
    if (!pencere) {
        return;
    }

    const kontrol = setInterval(() => {
        if (!pencere.closed) {
            return;
        }

        clearInterval(kontrol);
        durdur(token);
        http.post(KAPATMA_UCU, { token }, { silent: true }).catch(() => {});
    }, PENCERE_KONTROL_ARALIGI);
}

function durdur(token) {
    clearInterval(acikOturumlar.get(token));
    acikOturumlar.delete(token);
}

let pagehideBagli = false;

/*
 * KONSOL SEKMEDE AÇILIR.
 *
 * Ayrı ve sabit boyutlu bir pencere çözünürlüğü bozar: uzak masaüstünün
 * ölçüsünü pencere belirliyor (istemci el sıkışmada çerçevenin ölçüsünü
 * söylüyor) ve küçük bir pencerede geniş bir masaüstü küçülüp görev çubuğu
 * okunmaz olur.
 *
 * Sekme o sorunu getirmiyor: sekmenin görünen alanı zaten pencerenin tamamı
 * ve tarayıcı tam ekranda daha da büyük. Kazancı, açılan her şeyin tek
 * pencerede toplanması — arayüzler de sekmede açılıyor; RDP ve SSH
 * bağlantıları da aynı yolu izler.
 *
 * ÖLÇÜ VERİLMEZ: özellik listesi verilen her çağrıyı tarayıcı açılır pencere
 * sayıyor. Ad yine veriliyor — oturumu canlı tutan kod o tutamacı kullanıyor.
 */

/**
 * Guacamole'u açar; önce başka sekmenin jeton kaydını siler.
 *
 * Guacamole yeni sekmede kayıtlı jetonu ("GUAC_AUTH") okuyup kendi jetonundan
 * farklıysa İPTAL ediyor: önceki sekmenin bağlantısı kopuyordu. Açık sekmeler jetonu bellekte tutar; kaydı silmek onlara
 * dokunmaz. Yenilemesiz geçişte init yeniden koşar; açılmış çerçeve ikinci
 * kez yüklenmez.
 */
function cerceveyiAc(cerceve) {
    if (!cerceve.dataset.src || cerceve.getAttribute('src')) {
        return;
    }

    try {
        window.localStorage.removeItem('GUAC_AUTH');
    } catch {
        /* Depolama kapalıysa Guacamole da okuyamaz; sorun yok. */
    }

    cerceve.src = cerceve.dataset.src;
}

/**
 * UZAK OTURUM ÇERÇEVESİNE ODAK VERİR.
 *
 * Guacamole ekranda fareye basıldığında `preventDefault()` çağırıyor (metin
 * seçilmesin diye) ve tarayıcıda mousedown'da preventDefault ODAK DEĞİŞİMİNİ
 * DE engeller. Guacamole kendi sayfasında açıldığında belge zaten odaktadır
 * ve bu görünmez; İFRAME İÇİNDE odak ana sayfada kalır ve tuşlar uzak
 * makineye hiç ulaşmaz (RDP ekranında klavye çalışmaz).
 *
 * İki yerde odaklanır: çerçeve yüklendiğinde ve fare çerçevenin ÜSTÜNE
 * geldiğinde. İkincisi şart — kullanıcı "Oturumu kapat" düğmesine ya da
 * adres çubuğuna gittikten sonra ekrana dönünce klavye yine çalışsın.
 *
 * Çerçeve panelin kendi kökeninde duruyor (nginx /rdp-proxy), o yüzden
 * `contentWindow.focus()` erişilebilir.
 */
function cerceveyiOdakla() {
    const cerceve = $('[data-oturum-cercevesi]');

    if (!cerceve) {
        return;
    }

    cerceveyiAc(cerceve);

    const odakla = () => {
        try {
            cerceve.contentWindow?.focus();
        } catch {
            /* Başka kökene düşerse odak veremeyiz; ekran yine çalışır. */
        }
    };

    cerceve.addEventListener('load', odakla);
    cerceve.addEventListener('mouseenter', odakla);
    cerceve.addEventListener('pointerenter', odakla);

    /* Sayfa zaten yüklü geldiyse `load` bir daha tetiklenmez. */
    odakla();
}

export function init() {
    const jeton = $('[data-oturum-jetonu]')?.dataset.oturumJetonu;

    /*
     * KAPATMA DÜĞMESİ KALDIRILDI.
     *
     * Düğmenin yaptığı, hemen aşağıdaki `pagehide` yolunun aynısıydı: kapatma
     * ucuna haber ver, pencereyi kapat. Sekmenin çarpısı ikisini de yapıyor ve
     * kapanış denetim defterine "Konsol kapatıldı" diye düşüyor. İkinci bir
     * kopya, konsolun üstünde yer kaplamaktan başka işe yaramıyordu.
     */

    // Oturum penceresi kapanırsa (çarpı, sekme kapatma, başka adrese gitme).
    // window dinleyicisi bir kez kurulur; init her sayfa geçişinde koşuyor.
    if (jeton && ! pagehideBagli) {
        pagehideBagli = true;
        window.addEventListener('pagehide', () => kapatBeacon(jeton));
    }

    on('click', '[data-uzak-baglan]', async (event, dugme) => {
        dugme.disabled = true;

        try {
            const oturum = await http.post(dugme.dataset.uzakBaglan, {
                id: dugme.dataset.uzakKimlik,

                /* Gerekçeyi onay penceresi bıraktı (bkz. confirm.js). */
                reason: dugme.dataset.gerekce ?? '',
            });

            const pencere = window.open(oturum.url, `oturum-${oturum.token}`);

            canliTut(oturum.token, pencere);
            emit(`${oturum.label} oturumu açıldı.`, 'success');
        } finally {
            dugme.disabled = false;
        }
    });

    cerceveyiOdakla();

    // Paneli açan sekme kapanırsa açık oturumlar sunucuda da kapatılır.
    window.addEventListener('pagehide', () => {
        acikOturumlar.forEach((zamanlayici, token) => {
            clearInterval(zamanlayici);
            kapatBeacon(token);
        });
    });
}
