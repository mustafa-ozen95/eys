# EYS — Erişim Yönetim Sistemi

[English](README.md) · **Türkçe**

Kurum içi sistemlerin erişim bilgilerini şifreli tutan, parolayı ekrana hiç
basmadan kullandıran ve **uzaktaki makinede parolayı gerçekten değiştirebilen**
kasa paneli.

Kardeş projeler: NYS (ağ paneli) ve Kimlik Yönetim Merkezi (kimlik sağlayıcı).

Neyin **ne ölçüde** desteklendiği: [`DESTEK.md`](DESTEK.md).

## Üç iş

- **Saklar.** Parola, not ve döndürme kimliği `encrypted` cast ile saklanır ve
  `$hidden`'dadır: hiçbir listede, JSON yanıtında ya da hata çıktısında
  basılmaz. Parolayı ekrana getiren tek uç `POST erisim/{kayit}/parola`; ayrı
  yetki ister, hız sınırlıdır ve her gösterim denetim kaydına düşer.
- **Kullandırır.** Portal oturumu, alanları doldurulmuş tek seferlik bir form
  sayfası üretir ve sayfa kendini hedef siteye gönderir. Parola kullanıcının
  ekranına ve panosuna hiç uğramaz.
- **Değiştirir.** On altı sürücü (Linux, Windows/OpenSSH, WinRM, ESXi, vCenter
  OS ve SSO, HPE iLO Redfish/RIBCL, Plesk, mailcow, Rspamd, ATEN ve Lantronix KVM) uzaktaki parolayı
  döndürür.

## Parola döndürmenin sırası

Sıra güvenliğin kendisidir; her adım bir sonrakinin geri dönüşünü mümkün kılar.

1. Uzak makinede tam yetkili, geçici bir **kurtarma hesabı** açılır.
2. O hesapla gerçekten bağlanılabildiği kanıtlanır — kanıtlanamazsa hesap
   silinir ve tur durur, asıl parolaya dokunulmaz.
3. Asıl parola kurtarma hesabıyla değiştirilir.
4. Yeni parola **yeni bir oturumla** denenir.
5. Kasa ancak şimdi güncellenir.
6. Kurtarma hesabı yeni parolayla silinir; silme işlemi ikinci kanıttır.

Takılırsa kurtarma hesabı makinede kalır, parolası panelde şifreli durur ve
"onar" düğmesi turu kapatır. Kuyruk işi `tries = 1`: yarım kalmış bir
döndürmeyi körlemesine tekrarlamak cihazı kimsenin bilmediği bir parolayla
bırakır.

## Yetki

Rol **canlı** okunur; kullanıcıya kopyalanmaz. Kişinin satırları yalnızca
istisnadır. Karar sırası:

1. Kişinin kayda özel satırı
2. Kişinin genel satırı
3. Rolün kayda özel yetkisi
4. Rolün genel yetkisi
5. Reddet (varsayılan kapalı)

Yetki grup bazında verilir; alan bazlı ayrım vardır: grubu görmek listeyi
görmeye yeter, parolayı yalnızca `field.password` verilmiş olan açar.

## Denetim kaydı

Her satır bir öncekinin özetini taşır ve özet **HMAC-SHA256** ile üretilir.
Model düzeyinde güncelleme ve silme kapalıdır; silmenin tek kapısı
`erisim:kayit-buda` komutudur ve o da gerekçe isteyip kendini zincire yazar.

## Kurulum

```bash
composer setup          # bağımlılıklar, .env, anahtar, göçler, derleme
php artisan erisim:yonetici-ekle --ad="Ad Soyad" --eposta=ad@ornek.test
```

Docker imajında zamanlayıcı zaten konteynerin içinde çalışır. Konteyner
dışında zamanlanmış işler için sunucuda tek bir cron satırı gerekir (site
kullanıcısı ile, root ile **değil**; kurulum dizinini kendinize göre yazın):

```
* * * * * cd /kurulum/dizini/eys && php artisan schedule:run >> /dev/null 2>&1
```

## Komutlar

| Komut | İş |
| --- | --- |
| `erisim:kasa-denetle` | Salt okuma denetim: döndürmeye hazır kayıtların kimliklerini yalnızca giriş deneyerek sınar |
| `erisim:kimlik-esitle` | Merkezdeki rol ve durum değişikliklerini yerel hesaplara uygular |
| `erisim:kayit-buda` | Denetim kaydını budar; gerekçe ister |
| `erisim:yonetici-ekle` | İlk yöneticiyi açar; parola komut satırına yazılmaz |
| `erisim:gorsel-hazirla` | Görsel testler için sqlite'ı sıfırdan kurar |

## Testler

```bash
composer test                                   # tam takım
php artisan erisim:gorsel-hazirla && npx playwright test
```

## Kurallar

Kod standartları ve güvenlik denetimi `CLAUDE.md`'de; çalışma kuralları
`.ai/rules/` altında. Özetle: her controller yetki taşır, sıralı birincil
anahtar dışarı çıkmaz (uuid), süzme veritabanında yapılır, her düzeltme bir
testle kilitlenir.
