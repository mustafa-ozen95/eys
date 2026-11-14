import { refresh } from './navigation.js';

/**
 * Toplu turun TEK göstergesi.
 *
 * Kayıtları tek tek açmak yerine partinin tamamı listenin başında izlenir
 * (bkz. BulkRound). Tek kaydın çubuğuyla aynı ilke: gösterge KARAR VERMEZ,
 * sayıları sunucudan okur. Parti bitince sayfa bir kez yerinde tazelenir;
 * sonuç kartı ve satırlar sunucudan son hâliyle gelir.
 *
 * Çizim Blade'deki sınıf adlarının AYNISINI kullanır
 * (credentials/partials/bulk-progress.blade.php); durum anahtarları
 * BulkRound::ETIKETLER ile aynıdır.
 */

/* Yoklama aralığı (ms). Tek tur birkaç saniye sürüyor. */
const INTERVAL = 2000;

/*
 * Üst sınır: ~20 dakika. Kuyruk durmuşsa sayfa sonsuza dek sormasın;
 * sunucu zaten süre dolunca başlamamış kayıtları "başlamadı" sayıyor.
 */
const MAX = 600;

/* Lejant sırası ve adları — BulkRound::ETIKETLER ile aynı. */
const LABELS = [
    ['success', 'başarılı'],
    ['running', 'sürüyor'],
    ['waiting', 'sırada'],
    ['unverified', 'doğrulanamadı'],
    ['failed', 'başarısız'],
    ['unstarted', 'başlamadı'],
];

const NAMES = Object.fromEntries(LABELS.map(([k, name]) => [k, name.charAt(0).toLocaleUpperCase('tr') + name.slice(1)]));

/*
 * Kart başına bir zamanlayıcı: sistem güncellemeleri ekranında kurulum ve
 * denetim kartı aynı anda sürebilir.
 */
const timers = new Map();

function stopOne(root) {
    clearTimeout(timers.get(root));
    timers.delete(root);
}

function stop() {
    timers.forEach((timer) => clearTimeout(timer));
    timers.clear();
}

/*
 * Halkanın doluluğu bir CSS değişkeni. Satır içi stil özniteliği CSP'ye
 * takılıyor; CSSOM üzerinden yazılan değer takılmaz (bkz.
 * rotation-progress.js baslangiclariUygula).
 */
function writePercent(root, percent) {
    root.style.setProperty('--bulk-round-percent', String(percent));
}

function render(root, data) {
    writePercent(root, data.percent);

    const percentText = root.querySelector('[data-bulk-round-percent-text]');
    if (percentText) {
        percentText.textContent = `%${data.percent}`;
    }

    const done = root.querySelector('[data-bulk-round-done]');
    if (done) {
        done.textContent = String(data.done);
    }

    const nowEl = root.querySelector('[data-bulk-round-now]');
    const currentEl = root.querySelector('[data-bulk-round-current]');
    if (nowEl && currentEl) {
        nowEl.hidden = !data.current;
        currentEl.textContent = data.current ?? '';
    }

    /* Parçalar: her makine bir <li>; yalnızca sınıfı ve başlığı değişir. */
    const trace = root.querySelector('[data-bulk-round-trace]');
    if (trace && Array.isArray(data.records)) {
        while (trace.children.length < data.records.length) {
            trace.appendChild(document.createElement('li'));
        }

        data.records.forEach((record, i) => {
            const segment = trace.children[i];
            segment.className = `bulk-round__segment bulk-round__segment--${record.status}`;
            segment.title = `${record.name} · ${NAMES[record.status] ?? record.status}`;
        });
    }

    /* Lejant: yalnızca sıfır olmayan durumlar. */
    const legend = root.querySelector('[data-bulk-round-legend]');
    if (legend && Array.isArray(data.records)) {
        const counts = {};
        data.records.forEach(({ status }) => { counts[status] = (counts[status] ?? 0) + 1; });

        legend.replaceChildren(...LABELS
            .filter(([key]) => counts[key] > 0)
            .map(([key, name]) => {
                const row = document.createElement('span');
                row.className = 'bulk-round__legend-item';

                const color = document.createElement('i');
                color.className = `bulk-round__color bulk-round__segment--${key}`;

                row.append(color, `${counts[key]} ${name}`);

                return row;
            }));
    }
}

async function poll(root, remaining) {
    const url = root.dataset.bulkRoundProgressUrl;

    if (!url || remaining <= 0) {
        stopOne(root);

        return;
    }

    let data;

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (response.status === 404) {
            /* Parti düştü (süresi doldu ya da kapatıldı): soracak bir şey yok. */
            stopOne(root);

            return;
        }

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        data = await response.json();
    } catch {
        /* Tek başarısız yoklama turu durdurmaz; sayaç işliyor. */
        timers.set(root, setTimeout(() => poll(root, remaining - 1), INTERVAL));

        return;
    }

    render(root, data);

    if (data.running === false) {
        /*
         * Parti bitti: sonuç kartı, sorunlu makinelerin adları ve satırların
         * son hâli sunucuda çiziliyor — sayfa YERİNDE tazelenir, açık olan
         * sağ panel kapanmaz. Tazelenen sayfa süren öbür kartın
         * yoklamasını yeniden başlatır (init).
         */
        stop();
        refresh();

        return;
    }

    timers.set(root, setTimeout(() => poll(root, remaining - 1), INTERVAL));
}

export function init() {
    stop();

    /* Bitmiş kartın halkası da dolu çizilsin: ilk değer sunucudan. */
    document.querySelectorAll('[data-bulk-round-root]').forEach((card) => {
        writePercent(card, Number(card.dataset.bulkRoundPercent) || 0);
    });

    /* İlk yoklama HEMEN: sayfa açıldığında iki saniye eski görüntü kalmasın. */
    document.querySelectorAll('[data-bulk-round-progress]').forEach((root) => poll(root, MAX));
}
