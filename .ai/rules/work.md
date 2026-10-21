---
paths:
  - '**'
---

# Çalışma Kuralları

Bu kurallar her dosya ve her komut için geçerlidir; kod yazarken de, elle komut
koştururken de uygulanır.

## Canlı sisteme izinsiz bağlanılmaz

Sunucu, switch, veritabanı ya da dış servis — hangisi olursa olsun, canlı bir
sisteme izin alınmadan bağlanılmaz. Geliştirme ve kütüphane denemeleri daima
sahte ya da kaydedilmiş veriyle yapılır. Bağlantı hatası senaryoları servis
olmayan adreslerle (127.0.0.1 gibi) sınanır.

Bir servise OKUMA izni verilmişse bu YAZMA iznini kapsamaz.

## Onay tek seferliktir

`CLAUDE.md` içindeki "Riskli İşlemler" listesi için alınan onay yalnızca o işlem
içindir; sonraki işlemi kapsamaz. Emin değilsen yapma, sor.

## Varsayım değil ölçüm

Bir dış sistemin (cihaz, API, veritabanı) neyi kabul ettiği TAHMİN EDİLMEZ,
ölçülür: "herhalde böyledir" diye yazılan her yapı, cihaz tarafından er ya da
geç yalanlanır. Ölçülen davranış bir kural dosyasına yazılır ki bir daha
ölçülmesin.

## Her düzeltme bir testle kilitlenir

Test, açığın kapandığını değil, BİR DAHA AÇILAMAYACAĞINI kanıtlar. Düzeltmeyi
geçici geri alıp testin gerçekten düştüğünü görmek, testin işe yaradığının tek
kanıtıdır.
