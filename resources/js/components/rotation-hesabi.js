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
 * HANGİ SÜRÜCÜLERİN İSTEDİĞİ SUNUCUDAN GELİR (`data-makine-hesabi`): burada
 * elle tutulan ikinci bir liste, yeni bir sürücü eklendiğinde sessizce
 * eskirdi.
 */

const GIZLI = 'is-hidden';

function surucuListesi(alan) {
    try {
        const liste = JSON.parse(alan.dataset.makineHesabi || '[]');

        return Array.isArray(liste) ? liste : [];
    } catch {
        /* Bozuk öznitelik ekranı kilitlemesin: alan sunucunun bıraktığı gibi kalır. */
        return null;
    }
}

function kur(alan) {
    const form = alan.closest('form');
    const surucu = form ? $('[name="rotation_driver"]', form) : null;
    const kasa = form ? $('[name="rotation_credential_id"]', form) : null;
    const elle = form ? $('[data-elle-hesap]', form) : null;
    const isteyenler = surucuListesi(alan);

    if (!surucu || isteyenler === null) {
        return;
    }

    const uygula = () => {
        const makineSsh = isteyenler.includes(surucu.value);

        alan.classList.toggle(GIZLI, !makineSsh);

        /*
         * Kasa kutusu gizliyken seçim de yok sayılır: sürücü değişince
         * görünmeyen bir kutudaki seçim yüzünden elle alanları kapalı
         * kalmasın.
         */
        if (elle) {
            elle.classList.toggle(GIZLI, makineSsh && !!kasa?.value);
        }
    };

    surucu.addEventListener('change', uygula);
    kasa?.addEventListener('change', uygula);

    /*
     * PENCERE KAPANINCA FORM SIFIRLANIYOR (bkz. dialog.js) ve sıfırlama
     * `change` üretmiyor: kutular eski değerine dönerken alanlar, kullanıcının
     * son seçimine göre açık ya da kapalı kalırdı. Sıfırlama olayı değerler
     * geri yüklenmeden önce tetiklendiği için kuyruğun sonuna bırakılır.
     */
    form.addEventListener('reset', () => setTimeout(uygula, 0));
}

export function init() {
    $$('[data-makine-hesabi]').forEach(kur);
}
