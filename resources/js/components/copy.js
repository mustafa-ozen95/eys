import { on } from '../lib/dom.js';

/**
 * Bir metin alanının içeriğini panoya kopyalar.
 *
 * Uzun metinler kopyala-yapıştırla taşınır; elle seçtirmek satır kaçırmaya
 * açık.
 *
 * Pano API'si güvenli olmayan bağlamda ya da izin verilmediğinde çalışmaz;
 * o durumda metin seçilir ve kullanıcı kendi kopyalar.
 */
async function kopyala(dugme) {
    const kaynak = document.querySelector(dugme.dataset.kopyala);

    if (!kaynak) {
        return;
    }

    const eskiMetin = dugme.textContent;

    try {
        await navigator.clipboard.writeText(kaynak.value);
        dugme.textContent = 'Kopyalandı';
    } catch {
        kaynak.focus();
        kaynak.select();
        dugme.textContent = 'Seçildi, kopyalayın';
    }

    setTimeout(() => {
        dugme.textContent = eskiMetin;
    }, 2000);
}

/**
 * Kutunun içindeki METNİ kopyalar (kullanıcı adı gibi, girdi olmayan
 * değerler). Kaynak düğmenin kendi kutusunda aranır; sayfada aynı bileşenden
 * birden çok bulunabileceği için kimlik verilmez.
 */
async function metniKopyala(dugme) {
    const kaynak = dugme.closest('.secret')?.querySelector('[data-kopya-kaynak]');

    if (!kaynak) {
        return;
    }

    const eskiMetin = dugme.textContent;

    try {
        await navigator.clipboard.writeText(kaynak.textContent.trim());
        dugme.textContent = '✓';
    } catch {
        /* Pano yoksa kullanıcı kendi seçsin: metin seçili bırakılır. */
        const secim = window.getSelection();
        const aralik = document.createRange();

        aralik.selectNodeContents(kaynak);
        secim?.removeAllRanges();
        secim?.addRange(aralik);
    }

    setTimeout(() => {
        dugme.textContent = eskiMetin;
    }, 2000);
}

export function init() {
    on('click', '[data-kopyala]', (event, dugme) => {
        kopyala(dugme).catch(() => {});
    });

    on('click', '[data-kopyala-metin]', (event, dugme) => {
        metniKopyala(dugme).catch(() => {});
    });
}
