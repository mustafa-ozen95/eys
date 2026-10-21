import { $ } from '../lib/dom.js';
import { tazele } from './navigation.js';

/**
 * Toplu turun TEK göstergesi.
 *
 * Kayıtları tek tek açmak yerine partinin tamamı listenin başında izlenir
 * (bkz. BulkRound). Tek kaydın çubuğuyla aynı ilke: gösterge KARAR VERMEZ,
 * sayıları sunucudan okur. Parti bitince sayfa bir kez yerinde tazelenir;
 * sonuç kartı ve satırlar sunucudan son hâliyle gelir.
 *
 * Çizim Blade'deki sınıf adlarının AYNISINI kullanır
 * (credentials/partials/bulk-progress.blade.php); durum anahtarları
 * BulkRound::ETIKETLER ile aynıdır.
 */

/* Yoklama aralığı (ms). Tek tur birkaç saniye sürüyor. */
const ARALIK = 2000;

/*
 * Üst sınır: ~20 dakika. Kuyruk durmuşsa sayfa sonsuza dek sormasın;
 * sunucu zaten süre dolunca başlamamış kayıtları "başlamadı" sayıyor.
 */
const AZAMI = 600;

/* Lejant sırası ve adları — BulkRound::ETIKETLER ile aynı. */
const ETIKETLER = [
    ['basarili', 'başarılı'],
    ['suruyor', 'sürüyor'],
    ['bekliyor', 'sırada'],
    ['dogrulanamadi', 'doğrulanamadı'],
    ['basarisiz', 'başarısız'],
    ['baslamadi', 'başlamadı'],
];

const ADLAR = Object.fromEntries(ETIKETLER.map(([k, ad]) => [k, ad.charAt(0).toLocaleUpperCase('tr') + ad.slice(1)]));

let zamanlayici = null;

function durdur() {
    if (zamanlayici !== null) {
        clearTimeout(zamanlayici);
        zamanlayici = null;
    }
}

/*
 * Halkanın doluluğu bir CSS değişkeni. Satır içi stil özniteliği CSP'ye
 * takılıyor; CSSOM üzerinden yazılan değer takılmaz (bkz.
 * rotation-progress.js baslangiclariUygula).
 */
function yuzdeYaz(kok, yuzde) {
    kok.style.setProperty('--toplu-yuzde', String(yuzde));
}

function ciz(kok, veri) {
    yuzdeYaz(kok, veri.yuzde);

    const yuzdeMetin = kok.querySelector('[data-toplu-yuzde-metin]');
    if (yuzdeMetin) {
        yuzdeMetin.textContent = `%${veri.yuzde}`;
    }

    const biten = kok.querySelector('[data-toplu-biten]');
    if (biten) {
        biten.textContent = String(veri.biten);
    }

    const simdi = kok.querySelector('[data-toplu-simdi]');
    const suren = kok.querySelector('[data-toplu-suren]');
    if (simdi && suren) {
        simdi.hidden = !veri.suren;
        suren.textContent = veri.suren ?? '';
    }

    /* Parçalar: her makine bir <li>; yalnızca sınıfı ve başlığı değişir. */
    const iz = kok.querySelector('[data-toplu-iz]');
    if (iz && Array.isArray(veri.kayitlar)) {
        while (iz.children.length < veri.kayitlar.length) {
            iz.appendChild(document.createElement('li'));
        }

        veri.kayitlar.forEach((kayit, i) => {
            const parca = iz.children[i];
            parca.className = `toplu__parca toplu__parca--${kayit.durum}`;
            parca.title = `${kayit.ad} · ${ADLAR[kayit.durum] ?? kayit.durum}`;
        });
    }

    /* Lejant: yalnızca sıfır olmayan durumlar. */
    const lejant = kok.querySelector('[data-toplu-lejant]');
    if (lejant && Array.isArray(veri.kayitlar)) {
        const sayilar = {};
        veri.kayitlar.forEach(({ durum }) => { sayilar[durum] = (sayilar[durum] ?? 0) + 1; });

        lejant.replaceChildren(...ETIKETLER
            .filter(([anahtar]) => sayilar[anahtar] > 0)
            .map(([anahtar, ad]) => {
                const satir = document.createElement('span');
                satir.className = 'toplu__lej';

                const renk = document.createElement('i');
                renk.className = `toplu__renk toplu__parca--${anahtar}`;

                satir.append(renk, `${sayilar[anahtar]} ${ad}`);

                return satir;
            }));
    }
}

async function yokla(kok, kalan) {
    const adres = kok.dataset.topluIlerlemeAdres;

    if (!adres || kalan <= 0) {
        durdur();

        return;
    }

    let veri;

    try {
        const yanit = await fetch(adres, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (yanit.status === 404) {
            /* Parti düştü (süresi doldu ya da kapatıldı): soracak bir şey yok. */
            durdur();

            return;
        }

        if (!yanit.ok) {
            throw new Error(String(yanit.status));
        }

        veri = await yanit.json();
    } catch {
        /* Tek başarısız yoklama turu durdurmaz; sayaç işliyor. */
        zamanlayici = setTimeout(() => yokla(kok, kalan - 1), ARALIK);

        return;
    }

    ciz(kok, veri);

    if (veri.calisiyor === false) {
        /*
         * Parti bitti: sonuç kartı, sorunlu makinelerin adları ve satırların
         * son hâli sunucuda çiziliyor — sayfa YERİNDE tazelenir, açık olan
         * sağ panel kapanmaz.
         */
        durdur();
        tazele();

        return;
    }

    zamanlayici = setTimeout(() => yokla(kok, kalan - 1), ARALIK);
}

export function init() {
    durdur();

    /* Bitmiş kartın halkası da dolu çizilsin: ilk değer sunucudan. */
    const kart = $('[data-toplu-kok]');
    if (kart) {
        yuzdeYaz(kart, Number(kart.dataset.topluYuzde) || 0);
    }

    const kok = $('[data-toplu-ilerleme]');

    if (!kok) {
        return;
    }

    /* İlk yoklama HEMEN: sayfa açıldığında iki saniye eski görüntü kalmasın. */
    yokla(kok, AZAMI);
}
