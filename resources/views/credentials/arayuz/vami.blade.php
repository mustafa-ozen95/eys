/*
    Appliance yönetim arayüzünün (VAMI) açılış betiği.

    ESXi'den farkı: burada GİRİŞ YAPILMAZ, oturum jetonu doğrudan yerleştirilir.
    Arayüz jetonu localStorage'da tutuyor ve sayfa panelin kökeninde olduğu
    için oraya biz yazabiliyoruz. Jetonu sunucu alıyor — PAROLA TARAYICIYA HİÇ
    UĞRAMIYOR.
*/
(() => {
    const ADRES = @json($kimlikAdresi);

    /* Arayüzün beklediği anahtarlar; adları ONUN belirlediği adlardır. */
    const JETON = 'auth_token';
    const KULLANICI = 'username';

    const ac = async () => {
        /* Zaten yerleştirdiysek tekrar yazmayız: uç tek kullanımlık. */
        if (localStorage.getItem(JETON)) return;

        const yanit = await fetch(ADRES, { credentials: 'same-origin' });

        /*
         * Uç tek kullanımlık: sayfa yenilenirse 410 döner. O zaman hiçbir şey
         * yapılmaz ve kullanıcı arayüzün kendi giriş ekranında kalır.
         */
        if (!yanit.ok) return;

        const kimlik = await yanit.json();

        localStorage.setItem(JETON, kimlik.jeton);
        localStorage.setItem(KULLANICI, kimlik.kullanici);

        /*
         * Arayüz jetonu AÇILIŞTA okuyor; biz ondan sonra yazdık. Sayfayı
         * yeniden yüklemek, oturumu görmesinin tek yolu.
         */
        window.location.reload();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ac().catch(() => {}));
    } else {
        ac().catch(() => {});
    }
})();
