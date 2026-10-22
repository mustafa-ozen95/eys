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
    const ENDPOINT = @json($identityUrl);

    const wait = (selectorText, duration = 30000) => new Promise((resolve, red) => {
        const deadline = Date.now() + duration;
        const look = () => {
            const n = document.querySelector(selectorText);
            if (n && n.offsetParent !== null) return resolve(n);
            if (Date.now() > deadline) return red(new Error(selectorText));
            setTimeout(look, 100);
        };
        look();
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
    const ngScope = (field) => {
        if (!window.angular) return null;

        const suitable = (k) => k && k.credentials && typeof k.login === 'function';

        let k = window.angular.element(field).scope && window.angular.element(field).scope();

        while (k && !suitable(k)) k = k.$parent;

        if (suitable(k)) return k;

        const ngInjector = window.angular.element(document.body).injector
            && window.angular.element(document.body).injector();

        if (!ngInjector) return null;

        const find = (s) => {
            if (suitable(s)) return s;

            let c = s && s.$$childHead;

            while (c) {
                const b = find(c);

                if (b) return b;

                c = c.$$nextSibling;
            }

            return null;
        };

        return find(ngInjector.get('$rootScope'));
    };

    const run = async () => {
        const username = await wait('#username');

        const response = await fetch(ENDPOINT, { credentials: 'same-origin' });

        /*
         * Uç tek kullanımlık: sayfa yenilenirse 410 döner. O zaman hiçbir şey
         * yapılmaz ve kullanıcı cihazın kendi giriş ekranında kalır — sessizce
         * boş bir form göndermekten iyidir.
         */
        if (!response.ok) return;

        const identity = await response.json();
        const k = ngScope(username);

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
            k.credentials.username = identity.username;
            k.credentials.password = identity.password;
        });

        k.login(k.credentials);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => run().catch(() => {}));
    } else {
        run().catch(() => {});
    }
})();
