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

/* Bir alt grubun (bölüm başlığı) seçilebilen kutuları. */
function groupItems(key) {
    return items().filter((item) => item.dataset.bulkGroup === key);
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
    const everything = $('[data-bulk-all]');

    /*
     * Sayaç ve düğme birden çok olabilir: aynı seçimle ikinci bir iş
     * (sistem güncellemesinde "Seçilenleri denetle", bkz. data-bulk-copy).
     */
    $$('[data-bulk-count]').forEach((counter) => {
        counter.textContent = String(chosen.length);
    });

    $$('[data-bulk-submit]').forEach((submit) => {
        submit.disabled = chosen.length === 0;
    });

    if (everything && !everything.disabled) {
        const all = items();
        everything.checked = chosen.length === all.length;
        everything.indeterminate = chosen.length > 0 && chosen.length < all.length;
    }

    $$('[data-bulk-group-all]').forEach((box) => {
        if (box.disabled) {
            return;
        }

        const group = groupItems(box.dataset.bulkGroupAll);
        const count = group.filter((item) => item.checked).length;
        box.checked = group.length > 0 && count === group.length;
        box.indeterminate = count > 0 && count < group.length;
    });

    /*
     * Onay penceresi kaç kaydın etkileneceğini SAYIYLA söyler: "seçili
     * kayıtlar" diye sorulan bir onay, kullanıcının neyi onayladığını
     * bilmediği bir onaydır.
     */
    const name = chosen[0]?.getAttribute('aria-label') ?? '';

    /*
     * Formun kendi cümlesi varsa o kullanılır ({n} = sayı, {name} = tek
     * seçimde kayıt). Aynı şerit sistem güncellemesinde de duruyor ve orada
     * "parola değiştirilecek" demek yanlış olurdu.
     */
    const one = form.dataset.bulkConfirmOne;
    const many = form.dataset.bulkConfirmMany;

    if (one && many) {
        form.dataset.confirm = (chosen.length === 1 ? one : many)
            .replace('{n}', String(chosen.length))
            .replace('{name}', name);

        return;
    }

    form.dataset.confirm = chosen.length === 1
        ? `1 kaydın parolası UZAKTAKİ MAKİNEDE değiştirilecek: ${name}`
        : `Seçili ${chosen.length} kaydın parolası UZAKTAKİ MAKİNELERDE değiştirilecek.`;
}

/*
 * AYNI SEÇİMLE İKİNCİ FORM.
 *
 * Seçim kutuları tek bir forma bağlı (form="…"); bir kutu iki forma birden
 * ait olamaz. Seçimi kullanan ikinci form (data-bulk-copy) onaylanınca
 * seçili değerler ona gizli alan olarak kopyalanır. Kopya her onayda
 * baştan kurulur: eski seçim kalmasın.
 */
function copySelection(event, form) {
    form.querySelectorAll('[data-bulk-copied]').forEach((field) => field.remove());

    selected().forEach((box) => {
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = box.name;
        field.value = box.value;
        field.setAttribute('data-bulk-copied', '');
        form.appendChild(field);
    });
}

export function init() {
    on('change', '[data-bulk-item]', refresh);
    on('eys:confirmed', 'form[data-bulk-copy]', copySelection);

    on('change', '[data-bulk-group-all]', (event, box) => {
        groupItems(box.dataset.bulkGroupAll).forEach((item) => {
            item.checked = box.checked;
        });

        refresh();
    });

    on('change', '[data-bulk-all]', (event, box) => {
        items().forEach((item) => {
            item.checked = box.checked;
        });

        refresh();
    });

    refresh();
}
