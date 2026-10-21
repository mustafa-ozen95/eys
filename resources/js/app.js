import * as bulk from './components/bulk.js';
import * as columns from './components/columns.js';
import * as confirmDialog from './components/confirm.js';
import * as copy from './components/copy.js';
import * as dondurmeIlerleme from './components/dondurme-ilerleme.js';
import * as dialog from './components/dialog.js';
import * as keyboard from './components/keyboard.js';
import * as kosulluAlan from './components/kosullu-alan.js';
import * as kullaniciMenu from './components/kullanici-menu.js';
import * as navigation from './components/navigation.js';
import * as oturumKilidi from './components/oturum-kilidi.js';
import * as portal from './components/portal.js';
import * as remoteSession from './components/remote-session.js';
import * as rotationHesabi from './components/rotation-hesabi.js';
import * as secretReveal from './components/secret-reveal.js';
import * as siralama from './components/siralama.js';
import * as theme from './components/theme.js';
import * as toast from './components/toast.js';
import * as topluIlerleme from './components/toplu-ilerleme.js';

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

function herCizimde() {
    dialog.acilacaklariAc();
    toast.init();
    keyboard.prepare();

    /* Dar ekranda şerit doğru bölmeye iner; geniş ekranda hiçbir şey yapmaz. */
    columns.init();

    /*
     * Her çizimde: panel sayfa yenilenmeden değişiyor ve çubuk yeni gelen
     * içerikte duruyor. Bir kez kurulsaydı ikinci kaydın turu izlenmezdi.
     */
    dondurmeIlerleme.init();

    /* Toplu turun şeridi de yeni gelen içerikte duruyor; aynı sebeple her çizimde. */
    topluIlerleme.init();

    /*
     * Kayıt penceresindeki alanlar her çizimde yeniden geliyor; olay da
     * alanın kendisine bağlanıyor, bir kez kurulsa ikinci kayıtta çalışmazdı.
     */
    rotationHesabi.init();

    /* Kayıt formundaki koşullu alanlar da her çizimde yeni gelen formda. */
    kosulluAlan.init();
}

document.addEventListener('DOMContentLoaded', () => {
    dialog.init();
    confirmDialog.init();
    bulk.init();
    copy.init();
    portal.init();
    remoteSession.init();
    secretReveal.init();
    siralama.init();
    keyboard.init();
    kullaniciMenu.init();
    navigation.init();
    /* Ekran kilidi: hareketsiz sekme oturumu kapatır (bkz. oturum-kilidi.js). Bir kez. */
    oturumKilidi.init();

    herCizimde();
});

document.addEventListener('eys:sayfa', herCizimde);
