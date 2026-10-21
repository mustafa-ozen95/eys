import { http } from '../lib/http.js';

/**
 * Cihaz erişim parolasını istek üzerine gösterir.
 *
 * Parola sayfa kaynağında HİÇ bulunmaz: göz simgesine basıldığında ayrı bir
 * istekle alınır. Böylece ekranı açan herkes değil, açıkça soran görür ve
 * her soruş sunucuda kayda geçer.
 *
 * Gösterilen parola bir süre sonra kendiliğinden gizlenir: omuz üstünden
 * bakılan bir ekranda açık kalmasın.
 */
const GIZLENME_SURESI = 30000;

const zamanlayicilar = new WeakMap();

/*
 * Açılan değer bellekte tutulur: kopyalama DOM'daki metni değil bu değeri
 * alır. Metin kırpılmış görünse bile (dar ekranda "…") kopyalanan tamdır.
 */
const degerler = new WeakMap();

function gizle(kutu) {
    const alan = kutu.querySelector('[data-sir-deger]');
    const dugme = kutu.querySelector('[data-sir-goster]');
    const kopyala = kutu.querySelector('[data-sir-kopyala]');

    alan.textContent = '••••••••';
    kutu.dataset.sirAcik = 'false';
    dugme.setAttribute('aria-label', 'Parolayı göster');
    dugme.textContent = '👁';

    if (kopyala) {
        kopyala.hidden = true;
        kopyala.textContent = '⧉';
    }

    degerler.delete(kutu);
    clearTimeout(zamanlayicilar.get(kutu));
}

async function goster(kutu) {
    const alan = kutu.querySelector('[data-sir-deger]');
    const dugme = kutu.querySelector('[data-sir-goster]');

    dugme.disabled = true;

    try {
        const yanit = await http.post(kutu.dataset.sirKaynak);

        alan.textContent = yanit.password;
        degerler.set(kutu, yanit.password);
        kutu.dataset.sirAcik = 'true';
        dugme.setAttribute('aria-label', 'Parolayı gizle');
        dugme.textContent = '🙈';

        const kopyala = kutu.querySelector('[data-sir-kopyala]');

        if (kopyala) {
            kopyala.hidden = false;
        }

        zamanlayicilar.set(kutu, setTimeout(() => gizle(kutu), GIZLENME_SURESI));
    } catch {
        // Hata bildirimini http katmanı gösterir.
    } finally {
        dugme.disabled = false;
    }
}

/**
 * Panoya kopyalar. Pano API'si güvenli olmayan bağlamda ya da izin
 * verilmediğinde çalışmaz; o durumda metin seçilir ve kullanıcı kendi
 * kopyalar (bkz. components/copy.js — aynı yaklaşım).
 */
async function panoyaAl(kutu, dugme) {
    const deger = degerler.get(kutu);

    if (!deger) {
        return;
    }

    try {
        await navigator.clipboard.writeText(deger);
        dugme.textContent = '✓';
    } catch {
        const secim = window.getSelection();
        const aralik = document.createRange();

        aralik.selectNodeContents(kutu.querySelector('[data-sir-deger]'));
        secim.removeAllRanges();
        secim.addRange(aralik);
        dugme.textContent = '⌸';
    }

    setTimeout(() => {
        dugme.textContent = '⧉';
    }, 2000);
}

export function init() {
    document.addEventListener('click', (event) => {
        const kopyala = event.target.closest('[data-sir-kopyala]');

        if (kopyala) {
            event.preventDefault();
            panoyaAl(kopyala.closest('[data-sir]'), kopyala).catch(() => {});

            return;
        }

        const dugme = event.target.closest('[data-sir-goster]');

        if (!dugme) {
            return;
        }

        event.preventDefault();

        const kutu = dugme.closest('[data-sir]');

        if (kutu.dataset.sirAcik === 'true') {
            gizle(kutu);
        } else {
            goster(kutu);
        }
    });
}
