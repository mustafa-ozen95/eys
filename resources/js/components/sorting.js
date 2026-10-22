import { on } from '../lib/dom.js';

/**
 * Satırları sürükleyerek sıralar.
 *
 * Gruplar ve alt gruplar kenar çubuğunda bu sırayla diziliyor; sayı kutusuna
 * elle 10, 20, 30 yazmak sırayı değiştirmenin dolambaçlı yolu olurdu.
 *
 * Bırakma anında yeni sıra sunucuya gönderilir: tarayıcıda görünen sıra ile
 * kayıttaki sıra ayrılamaz. Gönderim YERİNDE yapılır (data-inline, bkz.
 * navigation.js): sayfa yenilenmez, kaydırma ve açık panel kaybolmaz.
 *
 * Dış kütüphane kullanılmaz; tarayıcının kendi sürükleme olayları yeterli.
 *
 *   <tbody data-order="{{ route(...) }}" data-order-field="sira">
 *       <tr draggable="true" data-order-id="{{ $uuid }}">…</tr>
 */

/*
 * Listenin DOĞRUDAN çocukları: iç içe listelerde (grup → alt grup) dıştaki
 * liste, içtekinin satırlarını kendi satırı sanmamalı — yoksa bir grup bir
 * alt grup satırının önüne sokulur ve tablo bozulurdu.
 */
function rows(list) {
    return [...list.children].filter((row) => row.matches('[data-order-id]'));
}

function ordered(list) {
    return rows(list).map((row) => row.dataset.orderId);
}

/** Sürüklenen satırın imlece göre nereye bırakılacağını bulur. */
function targetRow(list, y) {
    return rows(list).filter((row) => !row.classList.contains('is-dragging')).reduce(
        (nearby, row) => {
            const box = row.getBoundingClientRect();
            const diff = y - box.top - box.height / 2;

            return diff < 0 && diff > nearby.diff ? { diff, row } : nearby;
        },
        { diff: Number.NEGATIVE_INFINITY, row: null },
    ).row;
}

/*
 * BIRAKINCA ONAY SORULUR: yanlışlıkla bırakılan satır sırayı değiştirmesin.
 * Form onay özniteliğiyle kurulur ve
 * gönderimi confirm.js yakalar; vazgeçilirse satırlar eski yerine döner —
 * ekranda görünen sıra ile kayıttaki sıra ayrılamaz.
 */
let pending = null;

function undo() {
    if (!pending) {
        return;
    }

    pending.oldOrder.forEach((row) => pending.list.appendChild(row));
    pending.form.remove();
    pending = null;
}

function send(list, oldOrder) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = list.dataset.order;
    form.hidden = true;
    form.setAttribute('data-inline', '');
    form.setAttribute('data-confirm', 'Yeni dizilim kaydedilecek; kenar çubuğu ve listeler bu sırayla gelir.');
    form.setAttribute('data-confirm-title', 'Sırayı kaydet');
    form.setAttribute('data-confirm-reasonless', '');

    const fields = [
        ['_token', document.querySelector('meta[name="csrf-token"]')?.content ?? ''],
        ['_method', 'PUT'],
        ...ordered(list).map((identity) => [`${list.dataset.orderField ?? 'sira'}[]`, identity]),
    ];

    fields.forEach(([name, val]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = val;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    pending = { list, oldOrder, form };

    /* Onaylanınca confirm.js formu gönderir; bekleyen kayıt temizlenir. */
    form.addEventListener('eys:confirmed', () => {
        pending = null;
    });

    /* requestSubmit "submit" olayını üretir; onay penceresi onu yakalar. */
    form.requestSubmit();
}

export function init() {
    let dragged = null;

    let oldOrder = [];

    /* Sürüklenenin ilk yeri: liste dışına bırakılırsa tam oraya döner. */
    let oldPlace = null;

    on('dragstart', '[data-order-id]', (event, row) => {
        dragged = row;
        oldOrder = rows(row.parentElement);
        oldPlace = { list: row.parentElement, next: row.nextSibling };
        row.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });

    on('dragend', '[data-order-id]', (event, row) => {
        row.classList.remove('is-dragging');

        /* Liste dışına bırakıldı (drop gelmedi): kaydedilmeyen dizilim ekranda kalmasın. */
        if (dragged && oldPlace) {
            oldPlace.list.insertBefore(dragged, oldPlace.next);
            dragged = null;
        }
    });

    /*
     * İÇ İÇE LİSTELER: kayıt listesinde bölümler sıralanır, bölümün içinde
     * de kayıtlar. En yakın [data-order] her zaman doğru liste değil — bölüm
     * sürüklenirken imleç bir kaydın üstündeyse en yakını kayıt listesidir.
     * Doğru liste, sürüklenenin DOĞRUDAN ebeveyni olandır; başka bir listeye
     * (alt gruptan ana gruba) bırakma böylece hiç mümkün olmaz.
     */
    const findList = (target) => {
        let list = target instanceof Element ? target.closest('[data-order]') : null;

        while (list && dragged?.parentElement !== list) {
            list = list.parentElement?.closest('[data-order]') ?? null;
        }

        return list;
    };

    document.addEventListener('dragover', (event) => {
        const list = dragged ? findList(event.target) : null;

        if (!list) {
            return;
        }

        event.preventDefault();

        const target = targetRow(list, event.clientY);

        if (target) {
            list.insertBefore(dragged, target);
        } else {
            list.appendChild(dragged);
        }
    });

    document.addEventListener('drop', (event) => {
        const list = dragged ? findList(event.target) : null;

        if (!list) {
            return;
        }

        event.preventDefault();
        dragged = null;

        send(list, oldOrder);
    });

    /* Vazgeçildi: pencere kapanır, satırlar eski yerine döner. */
    on('click', '[data-confirm-cancel]', undo);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            undo();
        }
    });
}
