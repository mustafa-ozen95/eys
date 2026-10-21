import { $$ } from '../lib/dom.js';

/**
 * Koşullu alanlar — bir alan yalnızca seçim onu gerektirdiğinde görünür.
 *
 * Kayıt formu her alanı her kayıtta gösteriyordu; gereksiz alanlar formu
 * kalabalıklaştırıyordu. Alan, açılır listedeki seçime ya da işaretlenen
 * kutuya göre görünür. Kural HTML'de yazılı, burada yalnızca uygulanır:
 *
 *   data-goster="access_type:rdp"               seçim bu değerlerdense
 *   data-goster="access_type:!ssh,rdp,vnc"      seçim bu değerlerden DEĞİLSE
 *   data-goster="portal_acik:*"                 kutu işaretliyse / alan doluysa
 *   data-goster="a:x|b:*"                       koşullardan BİRİ tutarsa
 *
 * Koşuldaki ad önce bir form alanının `name`'i, yoksa ekrana ait bir kutunun
 * `data-kosul`'u olarak aranır (formla gönderilmeyen "portal kullan" gibi).
 *
 * ÜÇ GÜVENCE:
 *  - Betik hiç çalışmazsa her şey görünür kalır: sunucu gizli çizmez.
 *  - DOLU ya da HATALI alan hiçbir kuralla gizlenmez. Gizli bir alanda
 *    duran değer ya da okunamayan bir hata mesajı, formu yanlış kaydettirir.
 *  - Gizlenen alan formdan ÇIKMAZ, yalnızca görünmez; değeri gönderilmeye
 *    devam eder ve tür değiştirildiğinde sessizce silinmez. Silinmesi
 *    İSTENEN yerde (kutu kaldırılınca kapanan bir özellik) sarmalayıcı
 *    `data-gizlenince-temizle` taşır.
 */

const GIZLI = 'is-hidden';

function denetim(form, ad) {
    return form.querySelector(`[name="${CSS.escape(ad)}"]`)
        ?? form.querySelector(`[data-kosul="${CSS.escape(ad)}"]`);
}

function deger(dugum) {
    if (!dugum) {
        return '';
    }

    if (dugum.type === 'checkbox' || dugum.type === 'radio') {
        return dugum.checked ? (dugum.value || '1') : '';
    }

    return String(dugum.value ?? '').trim();
}

/** "alan:değer,değer|alan:*" ifadesini değerlendirir. */
function tutarMi(form, ifade) {
    return ifade.split('|').some((kosul) => {
        const bol = kosul.indexOf(':');
        const ad = kosul.slice(0, bol).trim();
        let beklenen = kosul.slice(bol + 1).trim();
        const simdiki = deger(denetim(form, ad));

        if (beklenen === '*') {
            return simdiki !== '';
        }

        const degil = beklenen.startsWith('!');
        if (degil) {
            beklenen = beklenen.slice(1);
        }

        const icinde = beklenen.split(',').map((v) => v.trim()).includes(simdiki);

        return degil ? !icinde : icinde;
    });
}

/** Sarmalayıcının içinde kullanıcının girdiği bir değer ya da hata var mı? */
function korunmali(sarmalayici) {
    if (sarmalayici.querySelector('[aria-invalid="true"], .field__error')) {
        return true;
    }

    /* Temizlenecek bölüm korunmaz: gizlenmesi zaten "özelliği kapat" demek. */
    if (sarmalayici.hasAttribute('data-gizlenince-temizle')) {
        return false;
    }

    return [...sarmalayici.querySelectorAll('input[name], select[name], textarea[name]')].some((alan) => {
        if (alan.type === 'hidden') {
            return false;
        }

        if (alan.type === 'checkbox' || alan.type === 'radio') {
            return alan.checked;
        }

        return String(alan.value ?? '').trim() !== '';
    });
}

function temizle(sarmalayici) {
    sarmalayici.querySelectorAll('input[name], textarea[name]').forEach((alan) => {
        if (alan.type === 'checkbox' || alan.type === 'radio') {
            alan.checked = false;
        } else if (alan.type !== 'hidden') {
            alan.value = '';
        }
    });
}

/**
 * BAĞLI LİSTE: bir seçimin seçenekleri başka bir seçime göre daralır.
 *
 *   <select name="access_type" data-bagli="operating_system"
 *           data-bagli-secenekler='{"windows":["rdp","ssh",...], ...}'>
 *
 * Önce platform seçilir, erişim türü listesi ona göre kurulur ve en olası
 * tür başta durur: önce platform, sonra erişim türü. Liste sunucudan gelir
 * (OperatingSystem::accessTypes).
 *
 * Sayfa açılışında KAYDIN MEVCUT SEÇİMİNE DOKUNULMAZ: listede olmasa bile
 * seçili kalır. Seçim ancak kullanıcı platformu değiştirdiğinde ve eski tür
 * yeni platformda anlamsızsa listenin başına geçer.
 */
function bagliListe(form, secim) {
    const kaynak = denetim(form, secim.dataset.bagli);
    let harita;

    try {
        harita = JSON.parse(secim.dataset.bagliSecenekler || '{}');
    } catch {
        return null;
    }

    if (!kaynak) {
        return null;
    }

    const tumu = [...secim.options].map((o) => ({ deger: o.value, etiket: o.textContent }));
    const ilkSecim = secim.value;

    /** Listeyi kurar; seçim değiştiyse true döner. */
    const listeyiKur = (koruSecimi) => {
        const izinli = Array.isArray(harita[kaynak.value]) ? harita[kaynak.value] : tumu.map((o) => o.deger);
        const onceki = secim.value;
        const liste = izinli.map((d) => tumu.find((o) => o.deger === d)).filter(Boolean);

        if (koruSecimi && onceki !== '' && !liste.some((o) => o.deger === onceki)) {
            const eski = tumu.find((o) => o.deger === onceki);
            if (eski) {
                liste.push(eski);
            }
        }

        secim.replaceChildren(...liste.map((o) => new Option(o.etiket, o.deger, false, o.deger === onceki)));

        if (!liste.some((o) => o.deger === onceki)) {
            secim.value = liste[0]?.deger ?? '';
        }

        return secim.value !== onceki;
    };

    listeyiKur(true);

    kaynak.addEventListener('change', () => {
        if (listeyiKur(false)) {
            /* Bağlı alanlar (RDP kipi, portal…) yeni türe göre yeniden karar versin. */
            secim.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    /* Formun sıfırlanması: platform tarayıcı tarafından geri alınır, tür ilk hâline döner. */
    return () => {
        secim.replaceChildren(...tumu.map((o) => new Option(o.etiket, o.deger, false, o.deger === ilkSecim)));
        secim.value = ilkSecim;
        listeyiKur(true);
    };
}

function kur(form) {
    const kosullular = [...form.querySelectorAll('[data-goster]')];
    const sifirlayicilar = [...form.querySelectorAll('select[data-bagli]')]
        .map((secim) => bagliListe(form, secim))
        .filter(Boolean);

    if (kosullular.length === 0) {
        return;
    }

    const satirlar = [...form.querySelectorAll('.field-row')];

    const uygula = (ilk = false) => {
        kosullular.forEach((sarmalayici) => {
            const gorunsun = tutarMi(form, sarmalayici.dataset.goster) || korunmali(sarmalayici);
            const gizliydi = sarmalayici.classList.contains(GIZLI);

            sarmalayici.classList.toggle(GIZLI, !gorunsun);

            /*
             * Temizleme yalnızca KULLANICI bir kutuyu kaldırdığında: sayfa
             * açılışında boş gelen bölüm zaten boştur, dolu gelen bölümün
             * kutusu da işaretli gelir.
             */
            if (!ilk && !gorunsun && !gizliydi && sarmalayici.hasAttribute('data-gizlenince-temizle')) {
                temizle(sarmalayici);
            }
        });

        /*
         * Bütün alanları gizlenen satır da yer kaplamasın: üyelik kaydında
         * adres ve port gizlenince arada boş bir satır kalıyordu.
         */
        satirlar.forEach((satir) => {
            const cocuklar = [...satir.children];

            satir.classList.toggle('is-bos', cocuklar.length > 0 && cocuklar.every((c) => c.classList.contains(GIZLI)));
        });
    };

    form.addEventListener('change', () => uygula());

    /* Pencerenin "Vazgeç"i formu sıfırlar; değerler dönünce görünüm de dönsün. */
    form.addEventListener('reset', () => setTimeout(() => {
        sifirlayicilar.forEach((sifirla) => sifirla());
        uygula(true);
    }, 0));

    uygula(true);
}

export function init() {
    $$('[data-kosullu-form]').forEach((kok) => {
        const form = kok.closest('form');

        /* Aynı form iki kez kurulmasın (sayfa geçişinde DOM zaten yenileniyor). */
        if (form && form.dataset.kosulluKurulu === undefined) {
            form.dataset.kosulluKurulu = '';
            kur(form);
        }
    });
}
