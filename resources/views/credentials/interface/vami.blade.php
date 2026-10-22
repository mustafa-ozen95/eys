/*
    Appliance yönetim arayüzünün (VAMI) açılış betiği.

    ESXi'den farkı: burada GİRİŞ YAPILMAZ, oturum jetonu doğrudan yerleştirilir.
    Arayüz jetonu localStorage'da tutuyor ve sayfa panelin kökeninde olduğu
    için oraya biz yazabiliyoruz. Jetonu sunucu alıyor — PAROLA TARAYICIYA HİÇ
    UĞRAMIYOR.
*/
(() => {
    const ENDPOINT = @json($identityUrl);

    /* Arayüzün beklediği anahtarlar; adları ONUN belirlediği adlardır. */
    const TOKEN_KEY = 'auth_token';
    const USER_KEY = 'username';

    const run = async () => {
        /* Zaten yerleştirdiysek tekrar yazmayız: uç tek kullanımlık. */
        if (localStorage.getItem(TOKEN_KEY)) return;

        const response = await fetch(ENDPOINT, { credentials: 'same-origin' });

        /*
         * Uç tek kullanımlık: sayfa yenilenirse 410 döner. O zaman hiçbir şey
         * yapılmaz ve kullanıcı arayüzün kendi giriş ekranında kalır.
         */
        if (!response.ok) return;

        const identity = await response.json();

        localStorage.setItem(TOKEN_KEY, identity.token);
        localStorage.setItem(USER_KEY, identity.username);

        /*
         * Arayüz jetonu AÇILIŞTA okuyor; biz ondan sonra yazdık. Sayfayı
         * yeniden yüklemek, oturumu görmesinin tek yolu.
         */
        window.location.reload();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => run().catch(() => {}));
    } else {
        run().catch(() => {});
    }
})();
