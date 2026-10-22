import { $ } from '../lib/dom.js';
import { refresh } from './navigation.js';

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
const INTERVAL = 1500;

/*
 * Yoklama üst sınırı. Kuyruk durmuş ya da iş ölmüşse sayfa sonsuza dek
 * sunucuya soru sormamalı; sınıra gelince çubuk "sonuç için yenileyin" der.
 */
const MAX = 120;

let timer = null;

function stop() {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

function render(root, data) {
    const bar = root.querySelector('[data-progress-bar]');
    const text = root.querySelector('[data-progress-text]');
    const step = root.querySelector('[data-progress-step]');

    if (bar) {
        bar.style.width = `${data.percent}%`;
        bar.parentElement?.setAttribute('aria-valuenow', String(data.percent));
    }

    if (text) {
        text.textContent = data.text ?? '';
    }

    if (step) {
        step.textContent = `${data.step}/${data.total}`;
    }
}

async function poll(root, remaining) {
    const url = root.dataset.progressUrl;

    if (!url || remaining <= 0) {
        stop();

        return;
    }

    let data;

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        data = await response.json();
    } catch {
        /*
         * Tek bir başarısız yoklama turu bitirmez: ağ kesintisi cihazdaki
         * işi durdurmuyor. Sayaç işliyor, üst sınıra gelince duruyoruz.
         */
        timer = setTimeout(() => poll(root, remaining - 1), INTERVAL);

        return;
    }

    if (data.running === false) {
        /*
         * Tur bitti. Sonuç rozeti, kasadaki yeni parola ve yarıda kalmış tur
         * uyarısı sunucuda çiziliyor; burada taklit edilmez, sayfa yeniden
         * ÇEKİLİR — sayfa geçişlerindeki gibi, yerinde. Tarayıcı yenilemesi
         * ekranı beyaza düşürür ve açık olan sağ paneli kapatırdı: iş bittiği
         * anda kullanıcı baktığı yerden kopardı.
         */
        stop();
        refresh();

        return;
    }

    render(root, data);
    timer = setTimeout(() => poll(root, remaining - 1), INTERVAL);
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
function applyStarts() {
    document.querySelectorAll('[data-start]').forEach((bar) => {
        const percent = Number(bar.dataset.start);

        if (Number.isFinite(percent)) {
            bar.style.width = `${percent}%`;
        }
    });
}

export function init() {
    stop();
    applyStarts();

    const root = $('[data-progress]');

    if (!root) {
        return;
    }

    timer = setTimeout(() => poll(root, MAX), INTERVAL);
}
