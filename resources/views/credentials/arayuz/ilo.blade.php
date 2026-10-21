/*
    iLO — "HTML5 Console" düğmesi.

    Düğme `iLO.startHtml5Irc()` çağırıyor, o da konsolu bir ÇERÇEVEDE arıyor
    (cihazın kendi kodu, js/iLO.js):

        iLOGlobal.topPage.appFrame.window.startHtml5Irc()

    O çerçevede işlev yoksa çağrı hata veriyor ve hata `catch` içinde
    YUTULUYOR: ekranda hiçbir şey olmuyor, uyarı bile çıkmıyor — düğmenin
    adresi `javascript:void(0)`, tıklayınca tepki yok.

    Konsolun KENDİ sayfası cihazda duruyor ve tek başına açılabiliyor;
    oturum anahtarını ya adresten ya çerezden okuyor (yine cihazın kendi
    kodu, irc.html):

        var token = iLO.getSearchValue(location.search, "token");
        ... jQuery.isValidString(token) || (token = iLO.getSessionKey())

    Anahtar tarayıcıda zaten var: iLO oturumun varlığını sunucuya sormuyor,
    `sessionKey` çerezine bakıyor ve panel o çerezi bilerek indiriyor
    (bkz. ArayuzTuru::tarayiciCerezleri).

    Yani düğmeyi cihazın KENDİ desteklediği yola bağlıyoruz; yeni bir konsol
    yazmıyor, yalnızca kırık olan adımı atlıyoruz.
*/
(() => {
    const bagla = () => {
        const ilo = window.iLO;

        /*
         * Betik cihazdan gelen her belgeye ekleniyor ve iLO'nun içi çerçeve
         * dolu; düğme hangi çerçevedeyse bağlanma orada olmalı. İşlevi
         * tanımayan çerçevede yapacak bir şey yok.
         */
        if (!ilo || typeof ilo.startHtml5Irc !== 'function' || ilo.eysKonsolBagli) {
            return true;
        }

        ilo.eysKonsolBagli = true;

        ilo.startHtml5Irc = function () {
            /* Cihazın kendi davranışı: son kullanılan konsolu hatırlıyor. */
            try {
                ilo.setCookie('irc', ['last', 'html5']);
            } catch (e) {
                /* Çerez yazılamadıysa konsol yine açılır; hatırlanmaz. */
            }

            const anahtar = typeof ilo.getSessionKey === 'function'
                ? (ilo.getSessionKey() || '')
                : '';

            window.open(
                '/irc.html' + (anahtar === '' ? '' : '?token=' + encodeURIComponent(anahtar)),
                '_blank',
            );
        };

        return true;
    };

    /*
     * Betik `</head>` içine giriyor ve iLO.js ondan önce yükleniyor; yine de
     * sırayı garanti saymıyoruz: nesne o an yoksa belge hazır olunca bir daha
     * denenir.
     */
    if (!window.iLO) {
        addEventListener('DOMContentLoaded', bagla);

        return;
    }

    bagla();
})();
