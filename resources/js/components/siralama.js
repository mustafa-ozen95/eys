import { on } from '../lib/dom.js';

/**
 * Satırları sürükleyerek sıralar.
 *
 * Gruplar ve alt gruplar kenar çubuğunda bu sırayla diziliyor; sayı kutusuna
 * elle 10, 20, 30 yazmak sırayı değiştirmenin dolambaçlı yolu olurdu.
 *
 * Bırakma anında yeni sıra sunucuya gönderilir: tarayıcıda görünen sıra ile
 * kayıttaki sıra ayrılamaz. Gönderim YERİNDE yapılır (data-yerinde, bkz.
 * navigation.js): sayfa yenilenmez, kaydırma ve açık panel kaybolmaz.
 *
 * Dış kütüphane kullanılmaz; tarayıcının kendi sürükleme olayları yeterli.
 *
 *   <tbody data-sira="{{ route(...) }}" data-sira-alan="sira">
 *       <tr draggable="true" data-sira-kimlik="{{ $uuid }}">…</tr>
 */

/*
 * Listenin DOĞRUDAN çocukları: iç içe listelerde (grup → alt grup) dıştaki
 * liste, içtekinin satırlarını kendi satırı sanmamalı — yoksa bir grup bir
 * alt grup satırının önüne sokulur ve tablo bozulurdu.
 */
function satirlar(liste) {
    return [...liste.children].filter((satir) => satir.matches('[data-sira-kimlik]'));
}

function sirali(liste) {
    return satirlar(liste).map((satir) => satir.dataset.siraKimlik);
}

/** Sürüklenen satırın imlece göre nereye bırakılacağını bulur. */
function hedefSatir(liste, y) {
    return satirlar(liste).filter((satir) => !satir.classList.contains('is-dragging')).reduce(
        (yakin, satir) => {
            const kutu = satir.getBoundingClientRect();
            const fark = y - kutu.top - kutu.height / 2;

            return fark < 0 && fark > yakin.fark ? { fark, satir } : yakin;
        },
        { fark: Number.NEGATIVE_INFINITY, satir: null },
    ).satir;
}

/*
 * BIRAKINCA ONAY SORULUR: yanlışlıkla bırakılan satır sırayı değiştirmesin.
 * Form onay özniteliğiyle kurulur ve
 * gönderimi confirm.js yakalar; vazgeçilirse satırlar eski yerine döner —
 * ekranda görünen sıra ile kayıttaki sıra ayrılamaz.
 */
let bekleyen = null;

function geriAl() {
    if (!bekleyen) {
        return;
    }

    bekleyen.eskiSira.forEach((satir) => bekleyen.liste.appendChild(satir));
    bekleyen.form.remove();
    bekleyen = null;
}

function gonder(liste, eskiSira) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = liste.dataset.sira;
    form.hidden = true;
    form.setAttribute('data-yerinde', '');
    form.setAttribute('data-confirm', 'Yeni dizilim kaydedilecek; kenar çubuğu ve listeler bu sırayla gelir.');
    form.setAttribute('data-confirm-title', 'Sırayı kaydet');
    form.setAttribute('data-confirm-reasonless', '');

    const alanlar = [
        ['_token', document.querySelector('meta[name="csrf-token"]')?.content ?? ''],
        ['_method', 'PUT'],
        ...sirali(liste).map((kimlik) => [`${liste.dataset.siraAlan ?? 'sira'}[]`, kimlik]),
    ];

    alanlar.forEach(([ad, deger]) => {
        const girdi = document.createElement('input');
        girdi.type = 'hidden';
        girdi.name = ad;
        girdi.value = deger;
        form.appendChild(girdi);
    });

    document.body.appendChild(form);
    bekleyen = { liste, eskiSira, form };

    /* Onaylanınca confirm.js formu gönderir; bekleyen kayıt temizlenir. */
    form.addEventListener('eys:onaylandi', () => {
        bekleyen = null;
    });

    /* requestSubmit "submit" olayını üretir; onay penceresi onu yakalar. */
    form.requestSubmit();
}

export function init() {
    let suruklenen = null;

    let eskiSira = [];

    /* Sürüklenenin ilk yeri: liste dışına bırakılırsa tam oraya döner. */
    let eskiYer = null;

    on('dragstart', '[data-sira-kimlik]', (event, satir) => {
        suruklenen = satir;
        eskiSira = satirlar(satir.parentElement);
        eskiYer = { liste: satir.parentElement, sonraki: satir.nextSibling };
        satir.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });

    on('dragend', '[data-sira-kimlik]', (event, satir) => {
        satir.classList.remove('is-dragging');

        /* Liste dışına bırakıldı (drop gelmedi): kaydedilmeyen dizilim ekranda kalmasın. */
        if (suruklenen && eskiYer) {
            eskiYer.liste.insertBefore(suruklenen, eskiYer.sonraki);
            suruklenen = null;
        }
    });

    /*
     * İÇ İÇE LİSTELER: kayıt listesinde bölümler sıralanır, bölümün içinde
     * de kayıtlar. En yakın [data-sira] her zaman doğru liste değil — bölüm
     * sürüklenirken imleç bir kaydın üstündeyse en yakını kayıt listesidir.
     * Doğru liste, sürüklenenin DOĞRUDAN ebeveyni olandır; başka bir listeye
     * (alt gruptan ana gruba) bırakma böylece hiç mümkün olmaz.
     */
    const listeBul = (hedef) => {
        let liste = hedef instanceof Element ? hedef.closest('[data-sira]') : null;

        while (liste && suruklenen?.parentElement !== liste) {
            liste = liste.parentElement?.closest('[data-sira]') ?? null;
        }

        return liste;
    };

    document.addEventListener('dragover', (event) => {
        const liste = suruklenen ? listeBul(event.target) : null;

        if (!liste) {
            return;
        }

        event.preventDefault();

        const hedef = hedefSatir(liste, event.clientY);

        if (hedef) {
            liste.insertBefore(suruklenen, hedef);
        } else {
            liste.appendChild(suruklenen);
        }
    });

    document.addEventListener('drop', (event) => {
        const liste = suruklenen ? listeBul(event.target) : null;

        if (!liste) {
            return;
        }

        event.preventDefault();
        suruklenen = null;

        gonder(liste, eskiSira);
    });

    /* Vazgeçildi: pencere kapanır, satırlar eski yerine döner. */
    on('click', '[data-confirm-cancel]', geriAl);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            geriAl();
        }
    });
}
