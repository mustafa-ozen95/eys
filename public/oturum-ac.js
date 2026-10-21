/*
 * Fırlatma sayfasının otomatik gönderimi.
 *
 * AYRI DOSYA, satır içi değil: içerik CSP'si satır içi betikleri engelliyor
 * ve bu sayfanın tek işi formu göndermek. Vite paketine de girmiyor — sayfa
 * panelin düzenini kullanmayan, tek kullanımlık bir ara duraktır; oraya
 * bütün panel paketini yüklemek anlamsız olurdu.
 *
 * Betik çalışmazsa sayfa ÖLMEZ: <noscript> içindeki düğmeyle kullanıcı kendi
 * gönderir (bkz. portal-session.blade.php).
 */
(() => {
    const form = document.getElementById('oturum');

    if (form) {
        form.submit();
    }
})();
