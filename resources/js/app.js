import * as bulk from './components/bulk.js';
import * as columns from './components/columns.js';
import * as confirmDialog from './components/confirm.js';
import * as copy from './components/copy.js';
import * as rotationProgress from './components/rotation-progress.js';
import * as dialog from './components/dialog.js';
import * as keyboard from './components/keyboard.js';
import * as conditionalField from './components/conditional-field.js';
import * as userMenu from './components/user-menu.js';
import * as navigation from './components/navigation.js';
import * as sessionLock from './components/session-lock.js';
import * as portal from './components/portal.js';
import * as remoteSession from './components/remote-session.js';
import * as rotationAccount from './components/rotation-account.js';
import * as secretReveal from './components/secret-reveal.js';
import * as ordering from './components/sorting.js';
import * as theme from './components/theme.js';
import * as toast from './components/toast.js';
import * as bulkRoundProgress from './components/bulk-progress.js';

/**
 * Uygulama girişi.
 *
 * Buradaki modüller yalnızca DOM, olay ve arayüz işleriyle ilgilenir.
 * Yetki ve doğrulama sunucuda yapılır; burada tekrar edilmez.
 *
 * İKİ AYRI KURULUM var, çünkü sayfa geçişleri tarayıcıyı yenilemiyor
 * (bkz. navigation.js):
 *
 *  - BİR KEZ: olayları document üzerinde dinleyen bileşenler. İkinci kez
 *    kurulsalardı her tıklama iki kez işlenirdi.
 *  - HER ÇİZİMDE: düğümün kendisine bakan işler (yeni gelen içerikteki
 *    bildirim, satırların odaklanabilirliği).
 *
 * navigation EN SONA kurulur: bağlantıyı önce pencere ve onay bileşenleri
 * görmeli, geçiş yalnızca onlar karışmadıysa devralınmalı.
 */
theme.init();

function everyRender() {
    dialog.openPending();
    toast.init();
    keyboard.prepare();

    /* Dar ekranda şerit doğru bölmeye iner; geniş ekranda hiçbir şey yapmaz. */
    columns.init();

    /*
     * Her çizimde: panel sayfa yenilenmeden değişiyor ve çubuk yeni gelen
     * içerikte duruyor. Bir kez kurulsaydı ikinci kaydın turu izlenmezdi.
     */
    rotationProgress.init();

    /* Toplu turun şeridi de yeni gelen içerikte duruyor; aynı sebeple her çizimde. */
    bulkRoundProgress.init();

    /*
     * Kayıt penceresindeki alanlar her çizimde yeniden geliyor; olay da
     * alanın kendisine bağlanıyor, bir kez kurulsa ikinci kayıtta çalışmazdı.
     */
    rotationAccount.init();

    /* Kayıt formundaki koşullu alanlar da her çizimde yeni gelen formda. */
    conditionalField.init();
}

document.addEventListener('DOMContentLoaded', () => {
    dialog.init();
    confirmDialog.init();
    bulk.init();
    copy.init();
    portal.init();
    remoteSession.init();
    secretReveal.init();
    ordering.init();
    keyboard.init();
    userMenu.init();
    navigation.init();
    /* Ekran kilidi: hareketsiz sekme oturumu kapatır (bkz. session-lock.js). Bir kez. */
    sessionLock.init();

    everyRender();
});

document.addEventListener('eys:page', everyRender);
