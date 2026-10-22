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

const KEEP_ALIVE_INTERVAL = 4 * 60 * 1000;
const WINDOW_CHECK_INTERVAL = 2000;
const CLOSE_ENDPOINT = '/ajax/remote-session/disconnect';

const openSessions = new Map();

function csrf() {
    return $('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Sayfa kapanırken çalışır: fetch iptal edilir, sendBeacon edilmez.
 */
function closeBeacon(token) {
    navigator.sendBeacon?.(CLOSE_ENDPOINT, new Blob(
        [JSON.stringify({ token, _token: csrf() })],
        { type: 'application/json' },
    ));
}

function keepAlive(token, popup) {
    const timer = setInterval(async () => {
        // Oturum penceresi kapandıysa canlı tutmanın anlamı yok.
        if (popup?.closed) {
            stop(token);

            return;
        }

        try {
            const reply = await http.post('/ajax/remote-session/keep-alive', { token }, { silent: true });

            if (!reply.alive) {
                stop(token);
            }
        } catch {
            stop(token);
        }
    }, KEEP_ALIVE_INTERVAL);

    openSessions.set(token, timer);

    watch(token, popup);
}

/**
 * Pencerenin kapandığını yalnızca yoklayarak anlayabiliyoruz; kapanınca
 * kaydın da kapanması için sunucuya haber verilir.
 */
function watch(token, popup) {
    if (!popup) {
        return;
    }

    const check = setInterval(() => {
        if (!popup.closed) {
            return;
        }

        clearInterval(check);
        stop(token);
        http.post(CLOSE_ENDPOINT, { token }, { silent: true }).catch(() => {});
    }, WINDOW_CHECK_INTERVAL);
}

function stop(token) {
    clearInterval(openSessions.get(token));
    openSessions.delete(token);
}

let pagehideBound = false;

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
function openFrame(frame) {
    if (!frame.dataset.src || frame.getAttribute('src')) {
        return;
    }

    try {
        window.localStorage.removeItem('GUAC_AUTH');
    } catch {
        /* Depolama kapalıysa Guacamole da okuyamaz; sorun yok. */
    }

    frame.src = frame.dataset.src;
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
function focusFrame() {
    const frame = $('[data-session-frame]');

    if (!frame) {
        return;
    }

    openFrame(frame);

    const focusOn = () => {
        try {
            frame.contentWindow?.focus();
        } catch {
            /* Başka kökene düşerse odak veremeyiz; ekran yine çalışır. */
        }
    };

    frame.addEventListener('load', focusOn);
    frame.addEventListener('mouseenter', focusOn);
    frame.addEventListener('pointerenter', focusOn);

    /* Sayfa zaten yüklü geldiyse `load` bir daha tetiklenmez. */
    focusOn();
}

export function init() {
    const pageToken = $('[data-session-token]')?.dataset.sessionToken;

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
    if (pageToken && ! pagehideBound) {
        pagehideBound = true;
        window.addEventListener('pagehide', () => closeBeacon(pageToken));
    }

    on('click', '[data-remote-connect]', async (event, button) => {
        button.disabled = true;

        try {
            const session = await http.post(button.dataset.remoteConnect, {
                id: button.dataset.remoteId,

                /* Gerekçeyi onay penceresi bıraktı (bkz. confirm.js). */
                reason: button.dataset.reason ?? '',
            });

            const popup = window.open(session.url, `session-${session.token}`);

            keepAlive(session.token, popup);
            emit(`${session.label} oturumu açıldı.`, 'success');
        } finally {
            button.disabled = false;
        }
    });

    focusFrame();

    // Paneli açan sekme kapanırsa açık oturumlar sunucuda da kapatılır.
    window.addEventListener('pagehide', () => {
        openSessions.forEach((timer, token) => {
            clearInterval(timer);
            closeBeacon(token);
        });
    });
}
