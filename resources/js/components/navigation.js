import { on } from '../lib/dom.js';

/**
 * Sayfa geçişleri — tarayıcı yenilemeden.
 *
 * Sunucu yine tam sayfa üretir; değişen tek şey, tarayıcının o sayfayı
 * baştan çizmek yerine gövdeyi yerinde değiştirmesi. Böylece tema, yazı
 * tipleri ve betikler yeniden yüklenmez, ekran beyaza düşmez.
 *
 * NEDEN KÜTÜPHANE DEĞİL: bileşenlerin neredeyse tamamı olayları zaten
 * document üzerinde dinliyor (bkz. lib/dom.js `on`), yani gövde değişince
 * kendiliğinden çalışmaya devam ediyorlar. Geriye kalan iş bir istek, bir
 * DOM değişimi ve geçmiş kaydı; bunun için paket eklemek, davranışını
 * denetlemediğimiz bir katmanı kasanın önüne koymak olurdu.
 *
 * SUNUCU TARAFINDA HİÇBİR ŞEY DEĞİŞMEZ. Yetki, doğrulama ve yönlendirme
 * aynı yerlerde; bu dosya yalnızca gösterimle ilgilenir. İstek başarısız
 * olursa ya da HTML dönmezse tarayıcının kendi gezinmesine düşülür — yani
 * en kötü durumda eski davranış.
 *
 * POST FORMLARI DOKUNULMAZ. Onlar onay penceresinden geçiyor, yeni sekme
 * açabiliyor ve yönlendirmeyle bitiyor; araya girmek kazanılan şeye değmez.
 */

const EVENT = 'eys:page';

/** Devam eden istek: hızlı tıklamada eskisi iptal edilir. */
let req = null;

function sameOrigin(url) {
    return url.origin === window.location.origin;
}

/** Bu bağlantı bizim işimiz mi? */
function takenOver(link) {
    const href = link.getAttribute('href') ?? '';

    return href !== ''
        && ! href.startsWith('#')
        && ! link.target
        && ! link.hasAttribute('download')
        && link.dataset.fullReload === undefined
        && sameOrigin(new URL(link.href, window.location.href));
}

/**
 * Sayfanın DERLEME dosyaları (biçem ve betik).
 *
 * <head> geçişlerde yenilenmediği için güncellemeden önce açılmış bir sekme
 * eski biçem ve betiklerle çalışmaya devam ediyordu: yeni gelen bir
 * bileşen (toplu tur göstergesi) ne biçemini ne de yoklamasını alıyor,
 * ancak başka bir kayda tıklanınca sunucudan yeniden çiziliyordu; gösterge
 * bir kayıtta takılı kalmış gibi duruyordu. Dosya adları derlemenin özetini
 * taşıyor; değiştiyse sürüm değişmiştir.
 */
function buildSignature(doc) {
    return [...doc.querySelectorAll('head link[rel="stylesheet"][href*="/build/"], head script[src*="/build/"]')]
        .map((node) => node.getAttribute('href') ?? node.getAttribute('src'))
        .sort()
        .join('|');
}

function render(text, url, historyMode) {
    const fresh = new DOMParser().parseFromString(text, 'text/html');

    /*
     * Panel güncellenmişse gövdeyi yerinde değiştirmek eski betiklerle yeni
     * HTML'i buluşturur. Bir kez tam yenileme yapılır; sonraki geçişler yine
     * yerinde.
     */
    const newSignature = buildSignature(fresh);
    if (newSignature !== '' && newSignature !== buildSignature(document)) {
        if (historyMode === 'push') {
            window.location.assign(url);
        } else {
            window.location.replace(url);
        }

        return;
    }

    document.title = fresh.title;

    /*
     * <head> yerinde kalır (biçem ve betikler yeniden yüklenmesin diye) ama
     * CSRF simgesi ORADADIR: oturum tazelenirse eski simge DOM'da kalır ve
     * AJAX istekleri 419 alırdı. Sunucunun yeni sayfada verdiği simge
     * alınır.
     */
    const icon = fresh.querySelector('meta[name="csrf-token"]')?.content;
    const existing = document.querySelector('meta[name="csrf-token"]');

    if (icon && existing) {
        existing.content = icon;
    }

    document.body.innerHTML = fresh.body.innerHTML;

    if (historyMode === 'push') {
        window.history.pushState({ eys: true }, '', url);
        window.scrollTo(0, 0);
    }

    /*
     * Form gönderiminden sonra adres YÖNLENDİRMENİN VARDIĞI yer olur.
     * Eski adres kalsaydı `?pencere=ekle` ile açılan pencereden kayıt
     * eklenince adres çubuğunda o yazı durur, F5 pencereyi yeniden açardı.
     * Geçmişe yeni satır eklenmez.
     */
    if (historyMode === 'replace' && url !== window.location.href) {
        window.history.replaceState({ eys: true }, '', url);
    }

    /* Düğüme bağlı çalışan bileşenler kendilerini burada tazeler. */
    document.dispatchEvent(new CustomEvent(EVENT));
}

async function goTo(url, historyMode = 'push') {
    req?.abort();
    req = new AbortController();

    document.body.dataset.transition = '';

    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
            signal: req.signal,
        });

        /*
         * HTML değilse (dosya indirme, beklenmedik bir yanıt) araya
         * girmeyiz: tarayıcı kendi bildiğini yapsın.
         */
        if (! response.ok || ! (response.headers.get('content-type') ?? '').includes('text/html')) {
            window.location.href = url;

            return;
        }

        /* Yönlendirme olduysa adres çubuğunda VARILAN yer yazmalı (örn. giriş ekranı). */
        render(await response.text(), response.url, historyMode);
    } catch (failure) {
        if (failure.name !== 'AbortError') {
            window.location.href = url;
        }
    } finally {
        delete document.body.dataset.transition;
    }
}

/**
 * Formu YERİNDE gönderir: sayfa yenilenmez.
 *
 * POST formlarına genel olarak dokunulmuyor (bkz. dosya başı) ama uzun süren
 * bir işi BAŞLATAN form istisnadır: gönderim tam sayfa yenilemesi yapıyor,
 * ekran beyaza düşüyor ve kullanıcı işin başladığını ancak yeni sayfa
 * çizilince görüyordu — tam da ilerlemeyi izlemek istediği anda.
 *
 * Sunucu tarafında hiçbir şey değişmez: aynı uç, aynı yönlendirme, aynı
 * bildirim. Yalnızca dönen sayfa tarayıcıyı yenilemek yerine gövdeye
 * çizilir; böylece bildirim ve az önce açılan panel yerinde kalır.
 *
 * İstek başarısız olursa ya da HTML dönmezse formun KENDİ gönderimine
 * düşülür — en kötü durumda eski davranış.
 */
export async function submitForm(form) {
    const url = form.getAttribute('action') || window.location.href;

    document.body.dataset.transition = '';

    try {
        const response = await fetch(url, {
            method: (form.getAttribute('method') || 'post').toUpperCase(),
            body: new FormData(form),
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        });

        if (! response.ok || ! (response.headers.get('content-type') ?? '').includes('text/html')) {
            form.submit();

            return;
        }

        /* Yönlendirme sonrası VARILAN adres yazılır; geçmişe kayıt eklenmez. */
        render(await response.text(), response.url, 'replace');
    } catch {
        form.submit();
    } finally {
        delete document.body.dataset.transition;
    }
}

/**
 * Bulunulan sayfayı YERİNDE tazeler.
 *
 * `location.reload()` ile aynı sonucu vermez: tarayıcı yenilemesi ekranı
 * beyaza düşürür, tema ve betikleri baştan yükler, açık olan sağ paneli ve
 * kaydırma konumunu kaybettirir. Uzun süren bir iş bittiğinde kullanıcıyı
 * bulunduğu yerden koparmanın bir sebebi yok — sunucu yine tam sayfa
 * üretir, yalnızca gövde yerinde değişir.
 *
 * Geçmişe kayıt EKLENMEZ: tazeleme bir gezinme değil, aynı sayfanın yeni
 * hâli. Eklenseydi "geri" tuşu aynı adrese tekrar tekrar düşerdi.
 */
export function refresh() {
    return goTo(window.location.href, 'yok');
}

export function init() {
    on('click', 'a[href]', (event, link) => {
        /*
         * Başka bir bileşen (pencere açma, onay penceresi) tıklamayı zaten
         * karşıladıysa karışmayız.
         */
        if (event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        if (! takenOver(link)) {
            return;
        }

        event.preventDefault();
        goTo(link.href);
    });

    /* Arama ve süzgeç de birer sayfa geçişidir. */
    on('submit', 'form', (event, form) => {
        if (event.defaultPrevented || form.target
            || (form.getAttribute('method') ?? 'get').toLowerCase() !== 'get') {
            return;
        }

        const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);

        if (! sameOrigin(url)) {
            return;
        }

        url.search = new URLSearchParams(new FormData(form)).toString();

        event.preventDefault();
        goTo(url.href);
    });

    /* Geri/ileri: adres zaten değişti, yalnızca içerik getirilir. */
    window.addEventListener('popstate', () => {
        goTo(window.location.href, 'yok');
    });

    /*
     * İlk sayfanın da bir geçmiş kaydı olmalı; yoksa ilk "geri" tuşu
     * boş duruma düşerdi.
     */
    window.history.replaceState({ eys: true }, '', window.location.href);
}
