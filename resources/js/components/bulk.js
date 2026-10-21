import { $, $$, on } from '../lib/dom.js';

/**
 * Toplu seçim şeridi.
 *
 * Yalnızca sayaç, "hepsini seç" ve düğmenin açık/kapalı durumu — karar
 * sunucuda: hangi kaydın döndürülebileceğine de, kullanıcının yetkisine de
 * orası bakar (bkz. RotationLauncher).
 */

function items() {
    return $$('[data-bulk-item]');
}

function selected() {
    return items().filter((box) => box.checked);
}

function refresh() {
    const form = $('[data-bulk-form]');

    if (!form) {
        return;
    }

    const secili = selected();
    const sayac = form.querySelector('[data-bulk-count]');
    const submit = form.querySelector('[data-bulk-submit]');
    const hepsi = $('[data-bulk-all]');

    if (sayac) {
        sayac.textContent = String(secili.length);
    }

    if (submit) {
        submit.disabled = secili.length === 0;
    }

    if (hepsi && !hepsi.disabled) {
        const tumu = items();
        hepsi.checked = secili.length === tumu.length;
        hepsi.indeterminate = secili.length > 0 && secili.length < tumu.length;
    }

    /*
     * Onay penceresi kaç kaydın etkileneceğini SAYIYLA söyler: "seçili
     * kayıtlar" diye sorulan bir onay, kullanıcının neyi onayladığını
     * bilmediği bir onaydır.
     */
    form.dataset.confirm = secili.length === 1
        ? `1 kaydın parolası UZAKTAKİ MAKİNEDE değiştirilecek: ${secili[0].getAttribute('aria-label') ?? ''}`
        : `Seçili ${secili.length} kaydın parolası UZAKTAKİ MAKİNELERDE değiştirilecek.`;
}

export function init() {
    on('change', '[data-bulk-item]', refresh);

    on('change', '[data-bulk-all]', (event, box) => {
        items().forEach((item) => {
            item.checked = box.checked;
        });

        refresh();
    });

    refresh();
}
