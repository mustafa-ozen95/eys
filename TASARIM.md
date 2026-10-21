# TASARIM SÖZLEŞMESİ — kardeş paneller, tek görünüm

**Bu dosya bir istek listesi değil, bir SINIRDIR.** Panellerde çalışan herkes
(insan ya da yapay zekâ asistanı) buradaki kuralları okumadan arayüze
dokunmamalıdır.

Paneller: **KYM** · **EYS** · **NYS** · **SYS** · **SYD**

---

## 1. Kaynak EYS'dir

Tasarımla ilgili bir soruda doğru cevap "EYS'de nasılsa öyle"dir. Yeni bir
ekran yazarken EYS'deki karşılığına bakılır; yoksa en yakın ekran örnek
alınır. **Tersi olmaz:** bir panelde "daha güzel" bir şey yapıp diğerlerini
ona benzetmek, panelleri ayrı yerlere götüren yoldur.

## 2. BİREBİR AYNI KALMASI GEREKEN DOSYALAR

Aşağıdaki dosyalar bütün panellerde **byte byte aynıdır**. Biri değişecekse
**hepsi birlikte** değişir:

```
resources/css/tokens.css            renk, boşluk, yazı ölçüleri
resources/css/base.css
resources/css/layout.css            sayfa kabuğu, giriş ekranı, üst çubuk
resources/css/components/*.css      düğme, tablo, pencere, rozet, uyarı…
resources/views/components/layout/app.blade.php
resources/views/components/layout/gate.blade.php
resources/views/components/nav/topbar.blade.php
resources/views/components/ui/*.blade.php
resources/views/auth/login.blade.php
```

Kontrol tek komuttur: her paneldeki kopyanın özeti aynı çıkmalıdır.

    sha1sum <panel-a>/<dosya> <panel-b>/<dosya> …

Fark çıkıyorsa ya bir düzeltme tek panele uygulanmıştır ya da biri şablonu
kendi zevkine göre değiştirmiştir. İkisi de düzeltilir.

## 3. Panele özel olan TEK şey: ad

Giriş ekranı bütün panellerde aynı dosyadır; panelin kimliği **config'ten** gelir:

```php
config('app.name')      // KYM · EYS · NYS · SYS · SYD
config('app.aciklama')  // "Erişim Yönetim Sistemi", "Switch Yedek Deposu"…
```

Bir metni doğrudan şablona yazmak, o dosyayı diğerlerinden ayırır. Panele
özel bir cümle gerekiyorsa **config anahtarı açılır**, şablon aynı kalır.

## 4. Yeni sınıf/renk/ölçü İCAT EDİLMEZ

Bir ekran yazarken ihtiyacınız olan sınıf zaten vardır:

| İhtiyaç | Kullanılacak |
|---|---|
| Sayfa başlığı | `.page-head`, `.page-head__text`, `.page-head__subtitle`, `.page-head__actions` |
| Kart/kutu | `.panel`, `.panel__head`, `.panel__title`, `.panel__body` |
| Tablo | `.table` (+ hücrelerde `data-label`), `.table-wrap`, `.table__cell--mono` |
| Satırın adı | `.satir-baslik` |
| Sayı özeti | `.figures`, `.figure`, `.figure__value`, `.figure__label` |
| Boş liste | `<x-ui.empty title="…" text="…" />` |
| Uyarı | `.alert alert--danger|warning|info|success` |
| Rozet | `.badge`, `.badge--red|yellow|green|blue|gray…` |
| Filtre/arama | `<form class="row row--wrap filter-bar">` + `.input.filter` |
| Boşluk | `var(--space-1…7)` — **piksel yazılmaz** |
| Renk | `var(--color-*)` — **hex yazılmaz** |

Gerçekten yeni bir şey gerekiyorsa `resources/css/components/panel-ozel.css`
içine, **gerekçesiyle** yazılır. Şablon dosyaları düzenlenmez; yoksa bir
sonraki eşitlemede kaybolur.

## 5. Giriş ekranı

- Birincil eylem **"Kimlik Yönetim Merkezi ile giriş"**tir.
- Parola formu **katlı** (`<details>`) durur ve **kaldırılmaz**: merkez
  erişilemez olduğu gün panele kimse giremezdi, yöneticisi dahil.
- Parola düğmesi yalnızca merkez kapalıyken birincil olur — ekranda tek bir
  birincil eylem bulunur.
- **Şifre sıfırlama YOKTUR.** Hesabı yönetici açar, parolayı yönetici verir.
- Hatalı giriş **doğrulama hatası** olarak döner (401 ile görünüm çizilmez);
  mesajı `<x-ui.flash />` gösterir.

## 6. Dokunulmayacak davranışlar

- **HSTS `max-age=0`.** Açılırsa sertifika düştüğü gün panele hiç
  girilemez: tarayıcı uyarıyı geçmeye izin vermez.
- **Tema çerezi** üst alan adında paylaşılır; `localStorage` kullanılmaz.
- **`no-store`** oturumlu sayfalarda durur.
- **`form-action`** kayıtlı istemci köklerini içerir (KYM) — liste elle
  tutulmaz, istemci kayıtlarından türer.
- Üst çubuktaki **hesap simgesi** ilk iki KELİMENİN baş harfidir
  (`App\Support\BasHarfler` / `Nav\Topbar::initialsFor`).

## 7. Değişiklik yapacaksan

1. Önce kopyaları karşılaştır (`sha1sum`): şu an ayrışmış bir şey var mı?
2. Değişikliği **EYS'de** yap.
3. Aynı dosyayı diğer panellere **kopyala** (elle düzenleme, kopyala).
4. Her panelin sınama paketini koştur.
5. Ölçerek doğrula: 320 / 390 / 820 / 1280 piksel, yatay taşma yok.
6. Commit iletisinde **neden** değiştiğini yaz.

## 8. Yapay zekâ asistanına

Bu depoda çalışıyorsan:

- Arayüzü "iyileştirmek" için kendiliğinden yeniden tasarlama. Ekranlar bütün
  panellerde aynı görünmek zorunda; tek panelde yapılan bir güzelleştirme,
  toplamda bir bozulmadır.
- Bir bileşeni kopyalayıp kendi sürümünü yazma; var olanı kullan.
- Kaldırdığın her şeyin bir sebebi vardı — sebebi dosyanın yorumunda yazar.
  Yorumu okumadan silme.
- Emin değilsen **sor**, tahmin etme.
