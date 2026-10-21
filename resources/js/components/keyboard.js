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

const SATIR_SECICI = '.row-item, .grid-row';

function sutunlar() {
    return $$('.columns .column, .columns .detail');
}

function satirlar(sutun) {
    return $$(SATIR_SECICI, sutun).filter((satir) => satir.offsetParent !== null);
}

function aktifSutun() {
    const odak = document.activeElement?.closest('.column, .detail');

    return odak ?? sutunlar().at(-1) ?? null;
}

function tasi(sutun, yon) {
    const liste = satirlar(sutun);

    if (liste.length === 0) {
        return;
    }

    const simdiki = liste.indexOf(document.activeElement);
    const sonraki = simdiki === -1
        ? 0
        : Math.min(liste.length - 1, Math.max(0, simdiki + yon));

    liste[sonraki].focus();
    liste[sonraki].scrollIntoView({ block: 'nearest' });
}

function komsuSutun(sutun, yon) {
    const liste = sutunlar();
    const sonraki = liste[liste.indexOf(sutun) + yon];

    if (!sonraki) {
        return;
    }

    const hedef = satirlar(sonraki);
    (hedef.find((satir) => satir.getAttribute('aria-selected') === 'true') ?? hedef[0])?.focus();
    sonraki.scrollIntoView({ inline: 'nearest', behavior: 'smooth' });
}

function yazmaAlanindaMi(hedef) {
    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(hedef?.tagName);
}

/**
 * Satırları klavyeyle odaklanabilir yapar.
 *
 * Her sayfa çiziminde yeniden çalışır: geçişlerde tarayıcı yenilenmediği
 * için yeni satırlar bu işlemden geçmemiş olurdu. Bağlantı ve düğme zaten
 * odaklanabilir.
 */
export function prepare() {
    $$(SATIR_SECICI).forEach((satir) => {
        if (! satir.hasAttribute('tabindex') && ! ['A', 'BUTTON'].includes(satir.tagName)) {
            satir.tabIndex = 0;
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

        if (event.key === '/' && ! yazmaAlanindaMi(event.target)) {
            event.preventDefault();
            $('.filter__input')?.focus();

            return;
        }

        if (yazmaAlanindaMi(event.target)) {
            return;
        }

        const sutun = aktifSutun();

        if (! sutun) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                tasi(sutun, 1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                tasi(sutun, -1);
                break;
            case 'ArrowRight':
                event.preventDefault();
                document.activeElement?.matches(SATIR_SECICI)
                    ? document.activeElement.click()
                    : komsuSutun(sutun, 1);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                komsuSutun(sutun, -1);
                break;
            default:
        }
    });
}
