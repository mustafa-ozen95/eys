# Uzak makinede komut çalıştırmak

Bu dosya, SSH üzerinden **parola isteyen** komutları çalıştıran kodun kuralı.
Uygulaması `app/Services/Rotation/Drivers/Concerns/ConnectsOverSsh.php`
içindedir ve iki sürücü onu paylaşır (Linux, Plesk).

## Kural: yazmadan önce İSTEMİ gör

Uzak tarafa bir şey yazmadan önce, karşı tarafın onu **okumaya hazır olduğunu
gösteren işareti** beklemek gerekir. Zamanlamaya ("önce parolayı yazayım,
nasılsa okur") güvenen her akış er geç kırılır.

Sebep tek bir davranış: `sudo`, parolayı okumadan hemen önce uçbirim girdi
tamponunu **temizliyor** (`tcflush`). Erken yazılan parola siliniyor.

## Bilinen üç davranış

| Makine | Belirti |
| --- | --- |
| Ubuntu 26.04, sudo-rs 0.2.13 | Yükseltmeden sonra yazılan KOMUT GİRDİSİ siliniyor; `chpasswd` sonsuza kadar bekliyor |
| AlmaLinux 9, sudo 1.9.5 | Aynı temizleme; iki satır peş peşe yazılınca ikincisi kayboluyor |
| AlmaLinux 9.4, sudo 1.9.5 | Yükseltme PAROLASININ kendisi siliniyor → `Sorry, try again` → komut girdiyi bekliyor |

Üçüncüsü kolay gözden kaçar, çünkü yalnızca **yeni açılan kurtarma
hesaplarında** ortaya çıkıyor: o hesap `sudo`yu ilk kez kullandığı için sudo
önce uzun "lecture" metnini basıyor, temizleme ondan sonra geliyor ve yarış
her seferinde kaybediliyor. Aynı makinede daha önce sudo kullanmış bir hesapla
akış sorunsuz çalışır.

Belirti her üçünde aynı: tur zaman aşımında **"Komut tamamlanmadı ya da zaman
aşımına uğradı"** ile düşüyor ve makinede tam yetkili bir kurtarma hesabı
kalıyor.

## Uygulanan çözüm

Akış üç işarete dayanır, hiçbir yerde beklemeye:

1. `sudo -S -p 'EYS-SUDO-PAROLA:' -v` — **boş istem kullanılmaz**, çünkü
   görülemeyen bir istem beklenemez.
2. İstem **ya da** kapı işareti okunur. İstem geldiyse parola yazılır; kapı
   geldiyse makine parola sormuyordur (NOPASSWD) ve boşuna beklenmez.
3. Kapı işareti okunur; komutun kendi girdisi ancak ondan sonra yazılır.

Girdi tüketen komut ayrıca **boruyla** beslenir
(`sh -c 'read -r satir; printf ... | komut'`): sudo-rs çocuğuna dosya-sonunu
iletmiyor, borunun kapanması ise girdinin bittiğini söylüyor.

## Yeni bir komut eklerken

- Parola, gizli anahtar ya da onay bekleyen her komut için **önce işaret, sonra
  yazma**. "Genelde çalışıyor" bir ölçüt değildir: bu hatanın üçü de makine,
  hesap ve dağıtıma göre ortaya çıkıp kayboluyor.
- Çıkış kodu komutun kendisinden okunur (`printf "$?"`), `getExitStatus()`'tan
  değil — PTY açıkken o değer henüz gelmemiş olabiliyor ve `false` ile `0`
  ayırt edilmiyor.
- Hata metnine komut çıktısı **koyulmaz**: PTY açıkken yazdığımız her şey geri
  yankılanıyor ve o yankıda parola var.
