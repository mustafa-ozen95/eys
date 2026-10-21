/*
    ESXi arayüzünün açılış betiği.

    Giriş, arayüzün KENDİ akışıyla yapılır (SOAP Login, CSRF jetonu, oturum
    çerezi — hepsi tarayıcıda ve bizim kökenimizde). Panel araya karışmaz, bir
    hata mesajı uydurmaz: giriş tutmazsa cihazın kendi metni görünür.

    PAROLA FORM ALANINA YAZILMAZ.

    Önce yazılıyordu ve Chrome bunu bir giriş denemesi sayıp "şifre kaydedilsin
    mi?" kutusunu açıyordu: parola ekranda AÇIKÇA görünüyor ve kullanıcının
    parola yöneticisine kaydedilebiliyordu. Kasanın
    varlık sebebi tam da bu.

    Onun yerine değer, arayüzün kendi AngularJS modeline veriliyor. Alan boş
    kaldığı için tarayıcının kaydedecek bir şeyi olmuyor.
*/
(() => {
    const ADRES = @json($kimlikAdresi);

    const bekle = (secici, sure = 30000) => new Promise((coz, red) => {
        const bitis = Date.now() + sure;
        const bak = () => {
            const n = document.querySelector(secici);
            if (n && n.offsetParent !== null) return coz(n);
            if (Date.now() > bitis) return red(new Error(secici));
            setTimeout(bak, 100);
        };
        bak();
    });

    /*
     * Giriş kapsamını bulur.
     *
     * İKİ YOL DENENİR. Alanın kendi kapsamı (element.scope()) yalnızca Angular
     * hata ayıklama bilgisi AÇIKSA var; üretim derlemelerinde kapalı olabiliyor
     * ve orada null dönüyor. O yüzden ikinci yol enjektörden başlayıp kapsam
     * ağacında "credentials + login" taşıyanı arıyor — enjektör, hata ayıklama
     * bilgisinden bağımsız olarak uygulama kökünde duruyor.
     */
    const kapsam = (alan) => {
        if (!window.angular) return null;

        const uygun = (k) => k && k.credentials && typeof k.login === 'function';

        let k = window.angular.element(alan).scope && window.angular.element(alan).scope();

        while (k && !uygun(k)) k = k.$parent;

        if (uygun(k)) return k;

        const enjektor = window.angular.element(document.body).injector
            && window.angular.element(document.body).injector();

        if (!enjektor) return null;

        const ara = (s) => {
            if (uygun(s)) return s;

            let c = s && s.$$childHead;

            while (c) {
                const b = ara(c);

                if (b) return b;

                c = c.$$nextSibling;
            }

            return null;
        };

        return ara(enjektor.get('$rootScope'));
    };

    const ac = async () => {
        const kullanici = await bekle('#username');

        const yanit = await fetch(ADRES, { credentials: 'same-origin' });

        /*
         * Uç tek kullanımlık: sayfa yenilenirse 410 döner. O zaman hiçbir şey
         * yapılmaz ve kullanıcı cihazın kendi giriş ekranında kalır — sessizce
         * boş bir form göndermekten iyidir.
         */
        if (!yanit.ok) return;

        const kimlik = await yanit.json();
        const k = kapsam(kullanici);

        /*
         * Kapsam bulunamazsa HİÇBİR ŞEY YAPILMAZ. Forma yazmak "çalışsın" diye
         * kolay bir geri dönüş olurdu ama parolayı yine ekrana çıkarırdı;
         * kullanıcının kendi elle girmesi ondan iyidir.
         */
        if (!k) {
            console.warn('EYS: giriş kapsamı bulunamadı, otomatik giriş yapılmadı.');

            return;
        }

        k.$apply(() => {
            k.credentials.username = kimlik.kullanici;
            k.credentials.password = kimlik.parola;
        });

        k.login(k.credentials);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ac().catch(() => {}));
    } else {
        ac().catch(() => {});
    }
})();
