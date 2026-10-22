import { $, $$ } from '../lib/dom.js';

/**
 * GÜNCELLEME HESABI ALANLARI: HANGİSİ GÖRÜNÜR, HANGİSİ GÖRÜNMEZ.
 *
 * İki kural var ve ikisi de aynı yerde duruyor, çünkü ikisi de aynı seçime
 * bakıyor:
 *
 *  - "KASADAN SEÇ" yalnızca MAKİNENİN SSH KİMLİĞİNİ isteyen sürücülerde
 *    çıkar. Çoğu sürücüde parolayı değiştiren, parolası değişen hesabın
 *    kendisidir; Plesk, mailcow süper yöneticisi ve rspamd'de bağlantı
 *    makinenin SSH kimliğiyle kuruluyor. Alan ise her kayıtta duruyor ve
 *    sürücüsü onu hiç kullanmayan kayıtlarda da "kasadan seç" diyordu; oysa
 *    gereklilik sürücüye göre değişir.
 *
 *  - "ELLE" hesap ve parola kutuları, kasadan bir kayıt SEÇİLİYKEN gizlenir:
 *    seçim varken o iki kutu okunmuyor ve ekranda durmaları hangisinin
 *    geçerli olduğunu soruşturmak demekti.
 *
 * BAŞLANGIÇ DURUMUNU SUNUCU VERİR (bkz. credentials/form.blade.php); burası
 * yalnızca kullanıcı kutuları değiştirdiğinde günceller. Tersi olsaydı,
 * betik yüklenmediğinde alanlar hiç görünmezdi.
 *
 * HANGİ SÜRÜCÜLERİN İSTEDİĞİ SUNUCUDAN GELİR (`data-machine-account`): burada
 * elle tutulan ikinci bir liste, yeni bir sürücü eklendiğinde sessizce
 * eskirdi.
 */

const HIDDEN = 'is-hidden';

function driverList(field) {
    try {
        const list = JSON.parse(field.dataset.machineAccount || '[]');

        return Array.isArray(list) ? list : [];
    } catch {
        /* Bozuk öznitelik ekranı kilitlemesin: alan sunucunun bıraktığı gibi kalır. */
        return null;
    }
}

function setUp(field) {
    const form = field.closest('form');
    const driver = form ? $('[name="rotation_driver"]', form) : null;
    const vault = form ? $('[name="rotation_credential_id"]', form) : null;
    const manual = form ? $('[data-manual-account]', form) : null;
    const requesters = driverList(field);

    if (!driver || requesters === null) {
        return;
    }

    const apply = () => {
        const machineSsh = requesters.includes(driver.value);

        field.classList.toggle(HIDDEN, !machineSsh);

        /*
         * Kasa kutusu gizliyken seçim de yok sayılır: sürücü değişince
         * görünmeyen bir kutudaki seçim yüzünden elle alanları kapalı
         * kalmasın.
         */
        if (manual) {
            manual.classList.toggle(HIDDEN, machineSsh && !!vault?.value);
        }
    };

    driver.addEventListener('change', apply);
    vault?.addEventListener('change', apply);

    /*
     * PENCERE KAPANINCA FORM SIFIRLANIYOR (bkz. dialog.js) ve sıfırlama
     * `change` üretmiyor: kutular eski değerine dönerken alanlar, kullanıcının
     * son seçimine göre açık ya da kapalı kalırdı. Sıfırlama olayı değerler
     * geri yüklenmeden önce tetiklendiği için kuyruğun sonuna bırakılır.
     */
    form.addEventListener('reset', () => setTimeout(apply, 0));
}

export function init() {
    $$('[data-machine-account]').forEach(setUp);
}
