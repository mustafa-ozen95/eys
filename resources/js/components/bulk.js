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

    const chosen = selected();
    const counter = form.querySelector('[data-bulk-count]');
    const submit = form.querySelector('[data-bulk-submit]');
    const everything = $('[data-bulk-all]');

    if (counter) {
        counter.textContent = String(chosen.length);
    }

    if (submit) {
        submit.disabled = chosen.length === 0;
    }

    if (everything && !everything.disabled) {
        const all = items();
        everything.checked = chosen.length === all.length;
        everything.indeterminate = chosen.length > 0 && chosen.length < all.length;
    }

    /*
     * Onay penceresi kaç kaydın etkileneceğini SAYIYLA söyler: "seçili
     * kayıtlar" diye sorulan bir onay, kullanıcının neyi onayladığını
     * bilmediği bir onaydır.
     */
    form.dataset.confirm = chosen.length === 1
        ? `1 kaydın parolası UZAKTAKİ MAKİNEDE değiştirilecek: ${chosen[0].getAttribute('aria-label') ?? ''}`
        : `Seçili ${chosen.length} kaydın parolası UZAKTAKİ MAKİNELERDE değiştirilecek.`;
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
