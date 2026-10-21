# Laravel Development Standards

## ⛔ TASARIMA DOKUNMADAN ÖNCE: DESIGN.md

Bu panelin arayüzü KARDEŞ PANELLERLE ORTAKTIR. CSS dosyaları, sayfa kabuğu,
üst çubuk, giriş ekranı ve `ui` bileşenleri byte byte aynıdır; biri
değişecekse hepsi birlikte değişir.

**Arayüzde bir şey değiştirmeden önce `DESIGN.md` dosyasını oku.** Yeni
sınıf/renk/ölçü icat etme, var olan bileşenleri kullan, tek panelde
"iyileştirme" yapma.


## Genel Prensipler

- Laravel Best Practices takip edilmeli.
- SOLID prensiplerine uyulmalı.
- DRY (Don't Repeat Yourself) uygulanmalı.
- KISS prensibi uygulanmalı.
- Gereksiz abstraction oluşturulmamalı.
- Laravel'in kendi özellikleri tercih edilmeli, custom çözüm son seçenek olmalı.

## Controller Kuralları

Controller sadece request'i yönetmeli. Inline işlem olmamalı.

Controller içinde **bulunmamalı**:

- Business logic
- SQL sorgusu
- Büyük foreach blokları
- Validation (Form Request kullanılmalı)

## Form Request

- Tüm validation'lar Form Request içinde olmalı.

## Policy

- Authorization asla Controller içinde yazılmamalı.

## Model Kuralları

Model içinde **bulunabilir**:

- Relationships
- Scopes
- Accessors
- Casts

Model içinde **bulunmamalı**:

- Mail gönderme
- PDF üretme
- API çağırma
- Büyük business logic

## Eloquent

Raw SQL son seçenek olmalı. Tercih sırası:

1. Relationship
2. Scope
3. Query Builder
4. Raw SQL

N+1 sorgularına izin verilmemeli. Gerekli yerlerde her zaman:

- `with()`
- `load()`
- `loadMissing()`

kullanılmalı.

## Route

Route dosyasında business logic olmayacak. Route şu şekilde tanımlanmalı:

```php
Route::resource(...);
// veya
Route::controller(...);
```

## Blade

Blade içinde PHP kodu yazılmamalı.

Yanlış:

```blade
@php
@endphp
```

Yanlış:

```blade
<?php
```

Yanlış: `@if(...)` / `@foreach(...)` içinde uzun hesaplamalar.

Blade sadece görüntü oluşturmalı ve hazır veri göstermeli.

## ViewModel / Data

View'a gönderilen veri Controller veya Service tarafından hazırlanmalı.

Blade içinde yapılmamalı:

- Filtreleme
- Sıralama
- Hesaplama
- Collection işlemleri

## JavaScript

Business logic JS içinde olmamalı. JS sadece şu işleri yapmalı:

- DOM
- AJAX
- Event
- UI

Laravel tarafındaki kuralları JS tekrar etmemeli.

## AJAX

AJAX endpointleri JSON döndürmeli:

```php
return response()->json(...);
```

## API

API Resource kullanılmalı. Controller içinde:

```php
return UserResource::collection(...);
```

tercih edilmeli.

## Mass Assignment

- `fillable` veya `guarded` doğru kullanılmalı.
- Hiçbir model korumasız bırakılmamalı.

## Transactions

Birden fazla tablo güncelleniyorsa `DB::transaction()` kullanılmalı.

## Queue

Queue'ya alınmalı:

- Mail
- Bildirim
- Büyük import
- Export
- PDF
- Uzun süren işlemler

## Events

Bir işlem başka işlemleri tetikliyorsa Event kullanılmalı.

Örneğin:

```
OrderCreated
  ↓
  SendMail
  CreateInvoice
  NotifyAdmin
```

## Config

Magic string kullanılmamalı. Şunlar config/env dosyasında olmalı:

- API URL
- API Key
- Timeout
- Limit
- Dosya yolları

## Exception

- try/catch gereksiz kullanılmamalı.
- Laravel Exception Handler tercih edilmeli.

## Logging

- `dd()`, `dump()`, `var_dump()` production kodunda bulunmamalı.
- Log gerekiyorsa `Log::info()` / `Log::error()` kullanılmalı.

## Kod Stili

- PSR-12 standardı uygulanmalı.
- Anlamlı değişken isimleri kullanılmalı.
- Fonksiyonlar tek sorumluluğa sahip olmalı.
- Bir metod mümkün olduğunca 20-30 satırı geçmemeli.
- Tekrarlayan kodlar ortaklaştırılmalı.
- Vite kullanılmalı, responsive olmalı.

## Naming Convention

| Tür | Örnek |
| --- | --- |
| Controller | `PostController` |
| Service | `PostService` |
| Policy | `PostPolicy` |
| Request | `StorePostRequest` |
| Resource | `PostResource` |
| Event | `PostCreated` |
| Listener | `SendPostNotification` |
| Job | `ProcessPostJob` |

Laravel isimlendirme standartları korunmalı.

## Frontend

- Blade sadece render işlemi yapmalı.
- PHP kodu Blade içinde yazılmamalı.
- Inline JavaScript mümkün olduğunca kullanılmamalı.
- Inline CSS kullanılmamalı.
- Assetler Vite üzerinden yönetilmeli.

### Vite Yapısı

- Entrypoint'ler: `resources/css/app.css` ve `resources/js/app.js`, layout'ta `@vite([...])` ile yüklenir.
- `resources/css/app.css` sadece import barındırır; kurallar `base.css` ve `components/*.css` içinde.
- Yeni bir CSS bileşeni `resources/css/components/` altına eklenir ve `app.css`'ten import edilir.
- Geliştirme: `npm run dev` (veya `composer run dev`), production: `npm run build`.
- Blade içine `<style>` veya `<script>` blokları eklenmez; ilgili CSS/JS dosyasına taşınır.

## Güvenlik (Öncelikli)

Güvenlik bu projede diğer başlıklardan önce gelir. Bir özellik güvenli değilse
tamamlanmış sayılmaz. Aşağıdaki açık sınıfları her yeni ekranda tekrar
kontrol edilir.

### Kimlik ve yetki

- Her rota ya `auth` altında olacak ya da neden olmadığı yorumla açıklanacak.
- Her controller `HasMiddleware` ile `can:` tanımlar veya `authorize()` çağırır.
  Yetkisiz bırakılmış tek bir controller bile kabul edilmez.
- Dış dünyaya etki eden işlemler (e-posta gönderme, uzak oturum açma, ödeme)
  ayrı bir yetki ister; "görüntüle" yetkisi bunları kapsamaz.
- Kullanıcı rolünü yalnızca yönetici değiştirebilir. "Kullanıcıları düzenle"
  yetkisi, kendini yönetici yapma yetkisi değildir.

### Nesne erişimi (IDOR)

- İç içe rotalarda alt kayıt üst kayıt üzerinden çözülür: `scopeBindings()`.
  Bunsuz A kaydında yetkisi olan biri B kaydının alt satırını değiştirebilir.
- Liste sorguları kullanıcıya göre daraltılır (`visibleTo($user)` gibi bir
  scope ile); "hepsini getir sonra ekranda gizle" güvenlik değildir.
- Sıralı birincil anahtar dışarı ÇIKMAZ; URL, sorgu parametresi, form değeri ve
  DOM kimliği uuid taşır.

### Aynı satıra giden iki yol

Bir veriyi değiştirmenin iki yolu varsa İKİSİ DE korunmalı. Aynı alanı yazan
iki ekrandan yalnızca birinde doğrulama varsa ikinci yol korumayı sessizce
atlar.
Yeni bir ekran eklerken sorulacak soru: "bu alanı yazan başka bir yol var mı,
o da aynı kuralı uyguluyor mu?" Kural kopyalanmaz, PAYLAŞTIRILIR.

### Veri

- Parola, jeton ve anahtar alanları `encrypted` cast ile saklanır ve `$hidden`
  içindedir; hiçbir ekranda ve JSON yanıtında basılmaz.
- Toplu atama `$fillable` ile sınırlıdır; `$request->all()` kullanılmaz.
- Oturum çerezi `Secure` işaretlidir.

### Denetim

Yeni bir ekran veya uç eklendiğinde şunlar çalıştırılır ve çıktısı boş olmalıdır:

```
# Yetkisiz controller. İstisna bilinçliyse sınıf docblock'una
# "Yetkilendirme: <neden>" satırı yazılır; denetim onu kabul eder.
for f in app/Http/Controllers/*.php app/Http/Controllers/*/*.php; do
  grep -q "middleware()\|authorize(\|Yetkilendirme:" $f || echo "$f"
done

# auth dışı rota. API uçları "auth:api" taşır; ikisi de kabul edilir.
php artisan route:list --except-vendor --json | python3 -c "
import json,sys
for r in json.load(sys.stdin):
    if not any(m.startswith('auth') for m in (r.get('middleware') or [])):
        print(r['uri'], r.get('name'))
"
```

Her düzeltme bir regresyon testiyle kilitlenir: açığın kapandığını değil,
bir daha açılamayacağını kanıtlarız.

## Riskli İşlemler (Onay Zorunlu)

Riskli işlemler **açıkça söylenmediği sürece asla yapılmaz**; her durumda önce onay alınır.

Onay gerektiren işlemler:

- Veritabanı değişiklikleri: `migrate`, `migrate:fresh`, `migrate:rollback`, `db:wipe`, `db:seed`
- Veri silme veya toplu güncelleme (`delete`, `truncate`, toplu `update`)
- Dosya/dizin silme veya üzerine yazma
- `.env`, config ve deploy ayarlarında değişiklik
- Git işlemleri: `commit`, `push`, `reset --hard`, `checkout -f`, branch silme
- Composer/NPM paket ekleme, kaldırma veya güncelleme
- Cache/queue/storage temizleme komutları (`optimize:clear`, `queue:flush` vb.)
- Production ortamına yönelik her türlü işlem
- Dış servislere gerçek istek gönderen işlemler (mail, ödeme, SMS, webhook)

Kural: Emin değilsen **yapma, sor**. Onay tek bir işlem için geçerlidir, sonraki işlemleri kapsamaz.

## Kod Üretim Kuralları (Claude Code İçin)

- Laravel'in yerleşik özelliklerini yeniden icat etme.
- Yeni bir dosya oluşturmadan önce mevcut yapıyı incele ve yeniden kullan.
- Gereksiz paket önerme.
- Gereksiz helper fonksiyonları oluşturma.
- Her değişiklik mevcut mimariyle uyumlu olsun.
- Kod üretmeden önce mevcut proje yapısını analiz et.
- Oluşturulan kod PSR-12 uyumlu olsun.
- Her yeni özellikte güvenlik (Policy, Validation, CSRF, Mass Assignment) kontrolü yap.
- Performans açısından N+1 sorgularını önle ve gereksiz veritabanı sorgularından kaçın.
- Gerekli durumlarda Feature ve Unit testleri de üret.
- Görsel testleri atlama; Playwright + Chromium ile görsel testleri de yap.

