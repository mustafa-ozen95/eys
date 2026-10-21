/**
 * EKRAN KİLİDİ — hareketsiz kalan sekme oturumu kapatır.
 *
 * Sunucudaki oturum süre dolunca düşer ama açık sayfa olduğu gibi kalır:
 * panel bütün gece ekranda açık durabilir ve oturumun düştüğü ancak bir yere
 * tıklanınca anlaşılır. Arka planda çalışan bir yoklama da sunucudaki
 * oturumu canlı tutabilir.
 *
 * Süre SUNUCUDAN gelir (<meta name="oturum-suresi">, dakika): panel
 * ayarından değişirse kilit de ona uyar, burada ikinci bir sayı tutulmaz.
 * Etiket yalnızca oturum açıkken basılır; giriş ekranında kilit çalışmaz.
 *
 * Bütün sekmeler TEK saat paylaşır (localStorage): bir sekmede çalışan
 * kişiyi öbür sekme dışarı atmaz. Süre dolunca önce çıkış istenir — oturumu
 * yoklamalar canlı tutmuş olsa bile kapansın — sonra giriş ekranına gidilir.
 *
 * AYNI DOSYA KARDEŞ PANELLERDE DE VAR; ayrışmamalı.
 */

const ANAHTAR = 'oturum-kilidi:son-hareket';
const OLAYLAR = ['keydown', 'pointerdown', 'wheel', 'touchstart', 'mousemove'];

/* Denetim aralığı ve yazma seyrekliği (ms). Fare hareketi her piksel yazılmasın. */
const DENETIM = 30000;
const YAZMA = 15000;

let kuruldu = false;
let yerel = Date.now();
let sonYazma = 0;

function sonHareket() {
    let deger = yerel;

    try {
        const ortak = Number(localStorage.getItem(ANAHTAR));
        if (Number.isFinite(ortak) && ortak > deger) {
            deger = ortak;
        }
    } catch {
        /* Tarayıcı belleği kapalıysa yalnızca bu sekmenin saati sayılır. */
    }

    return deger;
}

function hareket() {
    yerel = Date.now();

    if (yerel - sonYazma < YAZMA) {
        return;
    }

    sonYazma = yerel;

    try {
        localStorage.setItem(ANAHTAR, String(yerel));
    } catch {
        /* yok say */
    }
}

async function kilitle(cikisAdresi, girisAdresi) {
    const simge = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    try {
        await fetch(cikisAdresi, {
            method: 'POST',
            credentials: 'same-origin',
            /*
             * ZAMAN AŞIMI İŞARETİ: bu çıkış kullanıcının değil, saatin kararı.
             * Ortak çıkışı TETİKLEMEZ — bir sekmede süresi dolan kişi öteki
             * panellerden atılmasın (bkz. OrtakCikis · zamanAsimiMi).
             */
            headers: { 'X-CSRF-TOKEN': simge, Accept: 'text/html', 'X-Oturum-Zaman-Asimi': '1' },
            redirect: 'manual',
        });
    } catch {
        /* Oturum zaten düşmüş ya da ağ yok: giriş ekranına gitmek yine doğru. */
    }

    window.location.replace(girisAdresi);
}

export function init() {
    if (kuruldu) {
        return;
    }

    const dakika = Number(document.querySelector('meta[name="oturum-suresi"]')?.content);
    const cikis = document.querySelector('meta[name="oturum-cikis"]')?.content;
    const giris = document.querySelector('meta[name="oturum-giris"]')?.content;

    if (!Number.isFinite(dakika) || dakika <= 0 || !cikis || !giris) {
        return;
    }

    kuruldu = true;
    const sinir = dakika * 60000;

    hareket();
    OLAYLAR.forEach((olay) => window.addEventListener(olay, hareket, { passive: true, capture: true }));

    const denetle = () => {
        if (Date.now() - sonHareket() >= sinir) {
            kilitle(cikis, giris);
        }
    };

    setInterval(denetle, DENETIM);

    /* Uykudan dönen bilgisayarda zamanlayıcı gecikir; sekme görününce hemen bak. */
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            denetle();
        }
    });
}
