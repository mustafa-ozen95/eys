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
const HIDE_AFTER = 30000;

const timers = new WeakMap();

/*
 * Açılan değer bellekte tutulur: kopyalama DOM'daki metni değil bu değeri
 * alır. Metin kırpılmış görünse bile (dar ekranda "…") kopyalanan tamdır.
 */
const values = new WeakMap();

function hide(box) {
    const field = box.querySelector('[data-secret-value]');
    const button = box.querySelector('[data-secret-show]');
    const copy = box.querySelector('[data-secret-copy]');

    field.textContent = '••••••••';
    box.dataset.secretOpen = 'false';
    button.setAttribute('aria-label', 'Parolayı göster');
    button.textContent = '👁';

    if (copy) {
        copy.hidden = true;
        copy.textContent = '⧉';
    }

    values.delete(box);
    clearTimeout(timers.get(box));
}

async function show(box) {
    const field = box.querySelector('[data-secret-value]');
    const button = box.querySelector('[data-secret-show]');

    button.disabled = true;

    try {
        const response = await http.post(box.dataset.secretSource);

        field.textContent = response.password;
        values.set(box, response.password);
        box.dataset.secretOpen = 'true';
        button.setAttribute('aria-label', 'Parolayı gizle');
        button.textContent = '🙈';

        const copy = box.querySelector('[data-secret-copy]');

        if (copy) {
            copy.hidden = false;
        }

        timers.set(box, setTimeout(() => hide(box), HIDE_AFTER));
    } catch {
        // Hata bildirimini http katmanı gösterir.
    } finally {
        button.disabled = false;
    }
}

/**
 * Panoya kopyalar. Pano API'si güvenli olmayan bağlamda ya da izin
 * verilmediğinde çalışmaz; o durumda metin seçilir ve kullanıcı kendi
 * kopyalar (bkz. components/copy.js — aynı yaklaşım).
 */
async function copyToClipboard(box, button) {
    const val = values.get(box);

    if (!val) {
        return;
    }

    try {
        await navigator.clipboard.writeText(val);
        button.textContent = '✓';
    } catch {
        const selection = window.getSelection();
        const interval = document.createRange();

        interval.selectNodeContents(box.querySelector('[data-secret-value]'));
        selection.removeAllRanges();
        selection.addRange(interval);
        button.textContent = '⌸';
    }

    setTimeout(() => {
        button.textContent = '⧉';
    }, 2000);
}

export function init() {
    document.addEventListener('click', (event) => {
        const copy = event.target.closest('[data-secret-copy]');

        if (copy) {
            event.preventDefault();
            copyToClipboard(copy.closest('[data-secret]'), copy).catch(() => {});

            return;
        }

        const button = event.target.closest('[data-secret-show]');

        if (!button) {
            return;
        }

        event.preventDefault();

        const box = button.closest('[data-secret]');

        if (box.dataset.secretOpen === 'true') {
            hide(box);
        } else {
            show(box);
        }
    });
}
