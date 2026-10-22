import { $$ } from '../lib/dom.js';

/**
 * Koşullu alanlar — bir alan yalnızca seçim onu gerektirdiğinde görünür.
 *
 * Kayıt formu her alanı her kayıtta gösteriyordu; gereksiz alanlar formu
 * kalabalıklaştırıyordu. Alan, açılır listedeki seçime ya da işaretlenen
 * kutuya göre görünür. Kural HTML'de yazılı, burada yalnızca uygulanır:
 *
 *   data-show="access_type:rdp"               seçim bu değerlerdense
 *   data-show="access_type:!ssh,rdp,vnc"      seçim bu değerlerden DEĞİLSE
 *   data-show="portal_acik:*"                 kutu işaretliyse / alan doluysa
 *   data-show="a:x|b:*"                       koşullardan BİRİ tutarsa
 *
 * Koşuldaki ad önce bir form alanının `name`'i, yoksa ekrana ait bir kutunun
 * `data-condition`'u olarak aranır (formla gönderilmeyen "portal kullan" gibi).
 *
 * ÜÇ GÜVENCE:
 *  - Betik hiç çalışmazsa her şey görünür kalır: sunucu gizli çizmez.
 *  - DOLU ya da HATALI alan hiçbir kuralla gizlenmez. Gizli bir alanda
 *    duran değer ya da okunamayan bir hata mesajı, formu yanlış kaydettirir.
 *  - Gizlenen alan formdan ÇIKMAZ, yalnızca görünmez; değeri gönderilmeye
 *    devam eder ve tür değiştirildiğinde sessizce silinmez. Silinmesi
 *    İSTENEN yerde (kutu kaldırılınca kapanan bir özellik) sarmalayıcı
 *    `data-clear-on-hide` taşır.
 */

const HIDDEN = 'is-hidden';

function control(form, name) {
    return form.querySelector(`[name="${CSS.escape(name)}"]`)
        ?? form.querySelector(`[data-condition="${CSS.escape(name)}"]`);
}

function val(node) {
    if (!node) {
        return '';
    }

    if (node.type === 'checkbox' || node.type === 'radio') {
        return node.checked ? (node.value || '1') : '';
    }

    return String(node.value ?? '').trim();
}

/** "alan:değer,değer|alan:*" ifadesini değerlendirir. */
function isMatch(form, expression) {
    return expression.split('|').some((condition) => {
        const split = condition.indexOf(':');
        const name = condition.slice(0, split).trim();
        let expected = condition.slice(split + 1).trim();
        const currentValue = val(control(form, name));

        if (expected === '*') {
            return currentValue !== '';
        }

        const isNot = expected.startsWith('!');
        if (isNot) {
            expected = expected.slice(1);
        }

        const within = expected.split(',').map((v) => v.trim()).includes(currentValue);

        return isNot ? !within : within;
    });
}

/** Sarmalayıcının içinde kullanıcının girdiği bir değer ya da hata var mı? */
function mustKeep(wrapper) {
    if (wrapper.querySelector('[aria-invalid="true"], .field__error')) {
        return true;
    }

    /* Temizlenecek bölüm korunmaz: gizlenmesi zaten "özelliği kapat" demek. */
    if (wrapper.hasAttribute('data-clear-on-hide')) {
        return false;
    }

    return [...wrapper.querySelectorAll('input[name], select[name], textarea[name]')].some((field) => {
        if (field.type === 'hidden') {
            return false;
        }

        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.checked;
        }

        return String(field.value ?? '').trim() !== '';
    });
}

function clear(wrapper) {
    wrapper.querySelectorAll('input[name], textarea[name]').forEach((field) => {
        if (field.type === 'checkbox' || field.type === 'radio') {
            field.checked = false;
        } else if (field.type !== 'hidden') {
            field.value = '';
        }
    });
}

/**
 * BAĞLI LİSTE: bir seçimin seçenekleri başka bir seçime göre daralır.
 *
 *   <select name="access_type" data-linked="operating_system"
 *           data-linked-options='{"windows":["rdp","ssh",...], ...}'>
 *
 * Önce platform seçilir, erişim türü listesi ona göre kurulur ve en olası
 * tür başta durur: önce platform, sonra erişim türü. Liste sunucudan gelir
 * (OperatingSystem::accessTypes).
 *
 * Sayfa açılışında KAYDIN MEVCUT SEÇİMİNE DOKUNULMAZ: listede olmasa bile
 * seçili kalır. Seçim ancak kullanıcı platformu değiştirdiğinde ve eski tür
 * yeni platformda anlamsızsa listenin başına geçer.
 */
function linkedList(form, selection) {
    const source = control(form, selection.dataset.linked);
    let mapping;

    try {
        mapping = JSON.parse(selection.dataset.linkedOptions || '{}');
    } catch {
        return null;
    }

    if (!source) {
        return null;
    }

    const all = [...selection.options].map((o) => ({ val: o.value, label: o.textContent }));
    const firstSelection = selection.value;

    /** Listeyi kurar; seçim değiştiyse true döner. */
    const buildList = (keepSelection) => {
        const allowed = Array.isArray(mapping[source.value]) ? mapping[source.value] : all.map((o) => o.val);
        const previous = selection.value;
        const list = allowed.map((d) => all.find((o) => o.val === d)).filter(Boolean);

        if (keepSelection && previous !== '' && !list.some((o) => o.val === previous)) {
            const old = all.find((o) => o.val === previous);
            if (old) {
                list.push(old);
            }
        }

        selection.replaceChildren(...list.map((o) => new Option(o.label, o.val, false, o.val === previous)));

        if (!list.some((o) => o.val === previous)) {
            selection.value = list[0]?.val ?? '';
        }

        return selection.value !== previous;
    };

    buildList(true);

    source.addEventListener('change', () => {
        if (buildList(false)) {
            /* Bağlı alanlar (RDP kipi, portal…) yeni türe göre yeniden karar versin. */
            selection.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    /* Formun sıfırlanması: platform tarayıcı tarafından geri alınır, tür ilk hâline döner. */
    return () => {
        selection.replaceChildren(...all.map((o) => new Option(o.label, o.val, false, o.val === firstSelection)));
        selection.value = firstSelection;
        buildList(true);
    };
}

function setUp(form) {
    const conditionals = [...form.querySelectorAll('[data-show]')];
    const resetters = [...form.querySelectorAll('select[data-linked]')]
        .map((selection) => linkedList(form, selection))
        .filter(Boolean);

    if (conditionals.length === 0) {
        return;
    }

    const rows = [...form.querySelectorAll('.field-row')];

    const apply = (first = false) => {
        conditionals.forEach((wrapper) => {
            const visible = isMatch(form, wrapper.dataset.show) || mustKeep(wrapper);
            const wasHidden = wrapper.classList.contains(HIDDEN);

            wrapper.classList.toggle(HIDDEN, !visible);

            /*
             * Temizleme yalnızca KULLANICI bir kutuyu kaldırdığında: sayfa
             * açılışında boş gelen bölüm zaten boştur, dolu gelen bölümün
             * kutusu da işaretli gelir.
             */
            if (!first && !visible && !wasHidden && wrapper.hasAttribute('data-clear-on-hide')) {
                clear(wrapper);
            }
        });

        /*
         * Bütün alanları gizlenen satır da yer kaplamasın: üyelik kaydında
         * adres ve port gizlenince arada boş bir satır kalıyordu.
         */
        rows.forEach((row) => {
            const childItems = [...row.children];

            row.classList.toggle('is-empty', childItems.length > 0 && childItems.every((c) => c.classList.contains(HIDDEN)));
        });
    };

    form.addEventListener('change', () => apply());

    /* Pencerenin "Vazgeç"i formu sıfırlar; değerler dönünce görünüm de dönsün. */
    form.addEventListener('reset', () => setTimeout(() => {
        resetters.forEach((reset) => reset());
        apply(true);
    }, 0));

    apply(true);
}

export function init() {
    $$('[data-conditional-form]').forEach((root) => {
        const form = root.closest('form');

        /* Aynı form iki kez kurulmasın (sayfa geçişinde DOM zaten yenileniyor). */
        if (form && form.dataset.conditionalReady === undefined) {
            form.dataset.conditionalReady = '';
            setUp(form);
        }
    });
}
