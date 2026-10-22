/**
 * EKRAN KİLİDİ — hareketsiz kalan sekme oturumu kapatır.
 *
 * Sunucudaki oturum süre dolunca düşer ama açık sayfa olduğu gibi kalır:
 * panel bütün gece ekranda açık durabilir ve oturumun düştüğü ancak bir yere
 * tıklanınca anlaşılır. Arka planda çalışan bir yoklama da sunucudaki
 * oturumu canlı tutabilir.
 *
 * Süre SUNUCUDAN gelir (<meta name="session-lifetime">, dakika): panel
 * ayarından değişirse kilit de ona uyar, burada ikinci bir sayı tutulmaz.
 * Etiket yalnızca oturum açıkken basılır; giriş ekranında kilit çalışmaz.
 *
 * Bütün sekmeler TEK saat paylaşır (localStorage): bir sekmede çalışan
 * kişiyi öbür sekme dışarı atmaz. Süre dolunca önce çıkış istenir — oturumu
 * yoklamalar canlı tutmuş olsa bile kapansın — sonra giriş ekranına gidilir.
 *
 * AYNI DOSYA KARDEŞ PANELLERDE DE VAR; ayrışmamalı.
 */

const STORAGE_KEY = 'oturum-kilidi:son-hareket';
const EVENTS = ['keydown', 'pointerdown', 'wheel', 'touchstart', 'mousemove'];

/* Denetim aralığı ve yazma seyrekliği (ms). Fare hareketi her piksel yazılmasın. */
const CHECK_INTERVAL = 30000;
const WRITE = 15000;

let ready = false;
let local = Date.now();
let lastWrite = 0;

function lastMovement() {
    let val = local;

    try {
        const shared = Number(localStorage.getItem(STORAGE_KEY));
        if (Number.isFinite(shared) && shared > val) {
            val = shared;
        }
    } catch {
        /* Tarayıcı belleği kapalıysa yalnızca bu sekmenin saati sayılır. */
    }

    return val;
}

function movement() {
    local = Date.now();

    if (local - lastWrite < WRITE) {
        return;
    }

    lastWrite = local;

    try {
        localStorage.setItem(STORAGE_KEY, String(local));
    } catch {
        /* yok say */
    }
}

async function lock(logoutUrl, loginUrl) {
    const icon = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    try {
        await fetch(logoutUrl, {
            method: 'POST',
            credentials: 'same-origin',
            /*
             * ZAMAN AŞIMI İŞARETİ: bu çıkış kullanıcının değil, saatin kararı.
             * Ortak çıkışı TETİKLEMEZ — bir sekmede süresi dolan kişi öteki
             * panellerden atılmasın (bkz. SharedLogout · zamanAsimiMi).
             */
            headers: { 'X-CSRF-TOKEN': icon, Accept: 'text/html', 'X-Oturum-Zaman-Asimi': '1' },
            redirect: 'manual',
        });
    } catch {
        /* Oturum zaten düşmüş ya da ağ yok: giriş ekranına gitmek yine doğru. */
    }

    window.location.replace(loginUrl);
}

export function init() {
    if (ready) {
        return;
    }

    const minutes = Number(document.querySelector('meta[name="session-lifetime"]')?.content);
    const logoutMeta = document.querySelector('meta[name="session-logout"]')?.content;
    const login = document.querySelector('meta[name="session-login"]')?.content;

    if (!Number.isFinite(minutes) || minutes <= 0 || !logoutMeta || !login) {
        return;
    }

    ready = true;
    const limit = minutes * 60000;

    movement();
    EVENTS.forEach((evt) => window.addEventListener(evt, movement, { passive: true, capture: true }));

    const verify = () => {
        if (Date.now() - lastMovement() >= limit) {
            lock(logoutMeta, login);
        }
    };

    setInterval(verify, CHECK_INTERVAL);

    /* Uykudan dönen bilgisayarda zamanlayıcı gecikir; sekme görününce hemen bak. */
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            verify();
        }
    });
}
