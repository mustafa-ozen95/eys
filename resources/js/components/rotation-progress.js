import { $ } from '../lib/dom.js';
import { tazele } from './navigation.js';

/**
 * Süren bir parola turunun ilerleme çubuğu.
 *
 * NEDEN VAR: tur kuyruğa alınıyor ve cihaza göre birkaç saniyeden yarım
 * dakikaya kadar sürüyor. Panel o süre boyunca "sıraya alındı" deyip
 * sussaydı kullanıcı işin yürüyüp yürümediğini ancak sayfayı yenileyerek
 * görür, çoğu zaman düğmeye ikinci kez basardı.
 *
 * Çubuk KARAR VERMEZ: aşamayı sunucudan okur. İlerleme sunucudaki adımdan
 * gelir, burada tahmin edilmez — tahmin eden bir çubuk, tur takıldığında
 * dolmaya devam ederek yalan söylerdi.
 */

/* Yoklama aralığı (ms). Tur saniyeler sürüyor; daha sık yoklamak boşuna yük. */
const ARALIK = 1500;

/*
 * Yoklama üst sınırı. Kuyruk durmuş ya da iş ölmüşse sayfa sonsuza dek
 * sunucuya soru sormamalı; sınıra gelince çubuk "sonuç için yenileyin" der.
 */
const AZAMI = 120;

let zamanlayici = null;

function durdur() {
    if (zamanlayici !== null) {
        clearTimeout(zamanlayici);
        zamanlayici = null;
    }
}

function ciz(kok, veri) {
    const cubuk = kok.querySelector('[data-ilerleme-cubuk]');
    const metin = kok.querySelector('[data-ilerleme-metin]');
    const adim = kok.querySelector('[data-ilerleme-adim]');

    if (cubuk) {
        cubuk.style.width = `${veri.yuzde}%`;
        cubuk.parentElement?.setAttribute('aria-valuenow', String(veri.yuzde));
    }

    if (metin) {
        metin.textContent = veri.metin ?? '';
    }

    if (adim) {
        adim.textContent = `${veri.adim}/${veri.toplam}`;
    }
}

async function yokla(kok, kalan) {
    const adres = kok.dataset.ilerlemeAdres;

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

        if (!yanit.ok) {
            throw new Error(String(yanit.status));
        }

        veri = await yanit.json();
    } catch {
        /*
         * Tek bir başarısız yoklama turu bitirmez: ağ kesintisi cihazdaki
         * işi durdurmuyor. Sayaç işliyor, üst sınıra gelince duruyoruz.
         */
        zamanlayici = setTimeout(() => yokla(kok, kalan - 1), ARALIK);

        return;
    }

    if (veri.calisiyor === false) {
        /*
         * Tur bitti. Sonuç rozeti, kasadaki yeni parola ve yarıda kalmış tur
         * uyarısı sunucuda çiziliyor; burada taklit edilmez, sayfa yeniden
         * ÇEKİLİR — sayfa geçişlerindeki gibi, yerinde. Tarayıcı yenilemesi
         * ekranı beyaza düşürür ve açık olan sağ paneli kapatırdı: iş bittiği
         * anda kullanıcı baktığı yerden kopardı.
         */
        durdur();
        tazele();

        return;
    }

    ciz(kok, veri);
    zamanlayici = setTimeout(() => yokla(kok, kalan - 1), ARALIK);
}

/*
 * SUNUCUDAN GELEN İLK DEĞERİ UYGULA.
 *
 * Çubuğun başlangıç genişliği Blade'de `style="width: X%"` olarak
 * basılmaz. İçerik CSP'si satır içi stil özniteliklerini engelliyor ve
 * böyle bir kural SESSİZCE düşer — sayfa açıldığında çubuk boş görünür, ilk
 * yoklama dönene kadar öyle kalır. Buradan yazmak CSP'ye takılmaz: CSSOM
 * üzerinden yapılan değişiklik satır içi stil sayılmaz.
 */
function baslangiclariUygula() {
    document.querySelectorAll('[data-baslangic]').forEach((cubuk) => {
        const yuzde = Number(cubuk.dataset.baslangic);

        if (Number.isFinite(yuzde)) {
            cubuk.style.width = `${yuzde}%`;
        }
    });
}

export function init() {
    durdur();
    baslangiclariUygula();

    const kok = $('[data-ilerleme]');

    if (!kok) {
        return;
    }

    zamanlayici = setTimeout(() => yokla(kok, AZAMI), ARALIK);
}
