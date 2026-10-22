import { $, $$ } from '../lib/dom.js';

/**
 * Sütunlar arasında klavyeyle gezinme.
 *
 * Bu tasarımın çekirdeği klavye gezinmesidir: ↑↓ satırlar arasında dolaşır,
 * → veya Enter seçili satırı açar, ← bir önceki sütuna döner, "/" filtreye
 * odaklanır.
 *
 * Yalnızca odak ve tıklama yönetir; hangi satırın nereye gittiğine sunucunun
 * ürettiği bağlantı karar verir.
 */

const ROW_SELECTOR = '.row-item, .grid-row';

function columnItems() {
    return $$('.columns .column, .columns .detail');
}

function rows(column) {
    return $$(ROW_SELECTOR, column).filter((row) => row.offsetParent !== null);
}

function activeColumn() {
    const focusTarget = document.activeElement?.closest('.column, .detail');

    return focusTarget ?? columnItems().at(-1) ?? null;
}

function move(column, direction) {
    const list = rows(column);

    if (list.length === 0) {
        return;
    }

    const currentValue = list.indexOf(document.activeElement);
    const next = currentValue === -1
        ? 0
        : Math.min(list.length - 1, Math.max(0, currentValue + direction));

    list[next].focus();
    list[next].scrollIntoView({ block: 'nearest' });
}

function adjacentColumn(column, direction) {
    const list = columnItems();
    const next = list[list.indexOf(column) + direction];

    if (!next) {
        return;
    }

    const target = rows(next);
    (target.find((row) => row.getAttribute('aria-selected') === 'true') ?? target[0])?.focus();
    next.scrollIntoView({ inline: 'nearest', behavior: 'smooth' });
}

function isWritableField(target) {
    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName);
}

/**
 * Satırları klavyeyle odaklanabilir yapar.
 *
 * Her sayfa çiziminde yeniden çalışır: geçişlerde tarayıcı yenilenmediği
 * için yeni satırlar bu işlemden geçmemiş olurdu. Bağlantı ve düğme zaten
 * odaklanabilir.
 */
export function prepare() {
    $$(ROW_SELECTOR).forEach((row) => {
        if (! row.hasAttribute('tabindex') && ! ['A', 'BUTTON'].includes(row.tagName)) {
            row.tabIndex = 0;
        }
    });
}

export function init() {
    prepare();

    document.addEventListener('keydown', (event) => {
        /* Sütunlu ekranlarda çalışır; kontrol her tuşta yapılır çünkü sayfa
           geçişte değişiyor ve dinleyici bir kez kuruluyor. */
        if (! $('.columns') || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (event.key === '/' && ! isWritableField(event.target)) {
            event.preventDefault();
            $('.filter__input')?.focus();

            return;
        }

        if (isWritableField(event.target)) {
            return;
        }

        const column = activeColumn();

        if (! column) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                move(column, 1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                move(column, -1);
                break;
            case 'ArrowRight':
                event.preventDefault();
                document.activeElement?.matches(ROW_SELECTOR)
                    ? document.activeElement.click()
                    : adjacentColumn(column, 1);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                adjacentColumn(column, -1);
                break;
            default:
        }
    });
}
