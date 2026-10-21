# eys — konteyner kurulumu

Kurulum deponun kökündeki `docker-compose.yml` ile yapılır. Dosya Compose
standardındadır (compose-spec.io): aynı dosya `docker compose` ile de, NineDeploy,
Coolify, Dokploy, Portainer gibi bir panelin "Git deposundan compose" kurulumuyla da
çalışır. Ayarların hepsi ortamdan gelir; liste ve açıklamalar `.env.example`'da.

    cp .env.example .env          # en az APP_URL, DB_PASSWORD, ADMIN_EMAIL; chmod 600 .env
    docker compose up -d --build

Bir platformda: depoyu "compose" türünde ekleyin (dosya `docker-compose.yml`), ana
servis `panel`, port 80, sağlık yolu `/up`; `.env.example`'daki adları platformun
değişken ekranına girin.


## Vekil: alan adı ve TLS

**HTTP portu dışarı açılmaz.** Alan adını ve TLS'i önündeki vekil verir (platformun
kendisi ya da Caddy/nginx); panel isteğin HTTPS olduğunu `X-Forwarded-Proto`'dan bilir.
Kendi Caddy'nizle kuracaksanız örnek kural `docker/proxy.caddy`'de. Vekilsiz deneme:
`cp docker-compose.override.example.yml docker-compose.override.yml` (düz HTTP, port 8080).

Tarayıcıdan SSH/RDP oturumları **WebSocket**'tir ve saatlerce açık kalabilir. Vekilin okuma
süresi kısaysa oturum sessizce düşer: vekilde bu panel için uzun (ör. 3600 sn) okuma/yazma
süresi verin.

**Cihaz arayüzleri** (ESXi, VAMI) panelle aynı adın ayrı portlarında açılır: ortak köken
9443, oturum başına 9450-9479 (`ARAYUZ_ALANI`, `ARAYUZ_PORT_ILK/SON`). Platform vekilleri
yalnız 80/443 dinlediği için bu portları compose'taki `arayuz-vekili` karşılar
(varsayılan kapalı):

    ARAYUZ_ALANI=eys.ornek.test:9443
    ARAYUZ_VEKILI_KOPYA=1

ve `eys_interface-certificate` hacmine panelin sertifikası `tls.crt` + `tls.key` (ad aynı
olduğu için panelin sertifikası geçerli). Vekil TLS'i bitirip panelin 9080'ine verir;
ACME yapmaz, sertifikayı kurulum koyar ve yeniler. Port aralığını değiştirmek üç yeri
birden değiştirir: `docker/interface-proxy.Caddyfile`, compose'taki yayın aralığı ve
`ARAYUZ_PORT_ILK/SON`.


## Servisler ve hacimler

| Servis | Ne |
|---|---|
| `panel` | nginx, php-fpm, kuyruk işçisi, zamanlayıcı, ttyd, (sır verilmişse) guacd + Tomcat |
| `db` | MariaDB 11.4; `VT_KOPYA=0` ile kalkmaz (dışarıdaki veritabanı) |
| `redis` | önbellek, oturum, kuyruk; kalıcılık kapalı (yeniden başlatmada oturumlar düşer, veri düşmez) |
| `arayuz-vekili` | cihaz arayüzü portları (yukarıda); varsayılan kapalı |

Adlar `ONEK` ile başlar (varsayılan `eys`): `eys_storage` (`storage/app`: APP_KEY
verilmediyse, ilk parola, Ayarlar → Veritabanı bağlantısı), `eys_database`,
`eys_logs`, `eys_interface-certificate`. Aynı sunucuda ikinci kurulum `ONEK`'i değiştirir.

`AG_YALITIMI=true` konteynerleri cihazlardan, KYM'den ve dışarıdan yalıtır (deneme ya da
taşıma kurulumu canlı cihazların parolasını döndürmesin; ayrıca `ROTATION_ENABLED=false`).


## Neden panel tek konteyner

Uygulama her şeyin **localhost'ta** olduğunu varsayıyor ve bu varsayım koda
gömülü:

- `ttyd` uzak SSH için `127.0.0.1:7500-7699` aralığında süreç açar;
- nginx o portlara vekillik ederken `auth_request` ile **yine localhost'taki**
  php-fpm'e "bu oturum bu portu açabilir mi" diye sorar;
- Guacamole (RDP) `127.0.0.1:8090` ve `guacd` `127.0.0.1:4822` üzerinden
  konuşur.

Bu servisleri ayrı konteynerlere bölmek bu üç yeri de ağ üzerinden konuşur hâle
getirmek, yani kodu değiştirmek demekti; panel konteynerinde varsayım aynen
korunur. Veritabanı ve redis ise koda localhost olarak gömülü değil: onlar
compose'ta ayrı servis. Yatay ölçekleme **yoktur ve istenmez**: parola döndürme işleri kayıt
başına zaten sıraya sokulmuş durumda.

Süreçleri `supervisord` yönetir. Hangilerinin kalkacağı sabit değildir —
kararı `eys-entrypoint` verir:

| Servis | Ne zaman kalkar |
| --- | --- |
| nginx, php-fpm | her zaman |
| kuyruk işçisi, zamanlayıcı | her zaman |
| guacd + Tomcat | `GUACAMOLE_SECRET` verilmişse |

`GUACAMOLE_SECRET` verilmezse 200 MB'lık JVM hiç açılmaz; tarayıcıdan SSH
(ttyd) yine çalışır, yalnızca RDP çalışmaz.

## Durdurma — varsayılan süre YETMEZ

    docker compose down                          # süre compose'da tanımlı
    docker stop -t 3600 eys                   # düz docker ile

Kapanış **ne kadar sürüyorsa o kadar** beklenmelidir; verilen sayı bir hedef
değil, asla devreye girmemesi gereken bir tavandır. Konteyner işi bitince
hemen çıkar.

`docker stop` varsayılan olarak **10 saniye** bekler ve süre dolunca SIGKILL
gönderir (çıkış kodu 137). Uzak bir sunucuya parola yazan bir döndürme sürerken kapanış daha
da uzar; kesilmiş bir döndürme, panelin **bilmediği bir parola** demektir.

## İlk açılışta ne olur

Eksik olan her şey üretilir ve **hacimde saklanır**:

| Üretilen | Nerede durur | Not |
| --- | --- | --- |
| `APP_KEY` | `storage/app/app-key.txt` | Yedekleyin |
| Yönetici parolası | `storage/app/initial-password.txt` | Giriş yapıp değiştirin, sonra silin |

İlk yönetici `ADMIN_EMAIL` ile ve **yalnızca kullanıcı tablosu boşken** açılır; adres
verilmezse hesap hiç açılmaz. Parola sabit **değildir**: verilmezse rastgele üretilir
(imaj herkeste aynı olduğu için sabit parola herkeste aynı parola demek olurdu).
`CredentialGroupSeeder` bilerek çağrılmaz: örnek gruplarını yeni bir kuruluma yazmak
yanlış olurdu.

## ⚠ APP_KEY'i yedekleyin

**Kasadaki bütün parolalar** bu anahtarla şifrelenir. Anahtar kaybolursa
veritabanı duruyor olsa bile hiçbiri okunamaz ve geri getirilemez — panelin
tek varlık sebebi o sütunlardır.

Anahtar hacimde saklandığı için yeniden başlatmada korunur. `APP_KEY` ile
veritabanı **birlikte** yedeklenmelidir; biri olmadan öbürü işe yaramaz.

## Zamanlanmış işler

Konteyner dışı kurulumda zamanlayıcı tek bir cron satırına bağlıdır ve o satır
**unutulabilir**; unutulursa kimlik eşitleme hiç koşmaz.
Konteynerde `schedule:work` supervisord altında durur; unutulacak bir yer yok.

| İş | Sıklık | Ne yapar |
| --- | --- | --- |
| `erisim:kimlik-esitle` | 5 dakika | Merkezde erişimi kalkan burada da kaybetsin |
| `erisim:kasa-denetle` | zamanlanmaz, elle | Döndürmeye hazır görünen kayıtların kimliklerini sınar (yalnızca okur; bkz. routes/console.php) |
| `erisim:konsol-temizle` | 1 dakika | Tarayıcısı çöken oturumun kapanışını yazar |

## Güncelleme kontrolü

Ayarlar → Güncelleme (yalnız yönetici) çalışan sürümü GitHub'da main dalının **son başarılı
imaj derlemesiyle** karşılaştırır: kaç commit geride, hangi commit'ler yeni. Yön de ölçülür;
çalışan sürüm son derlemeden yeniyse "güncelleme" denmez. Kontrol her sabah 06:50'de kendiliğinden
yapılır (`guncelleme:denetle`). Yeni sürüm varsa yöneticiler panelin üstünde uyarı görür.
Ekran **yalnızca bakar**; güncelleme aşağıdaki yoldan, yedekle yapılır.

- Çalışan sürüm imaja derlemede yazılır (`ARG SURUM` → `UYGULAMA_SURUMU`; iş akışı commit'i verir).
- Depo açık: jeton gerekmez, GitHub'a jetonsuz sorulur (sınır IP başına saatte 60 istek). Depo
  özelse `GUNCELLEME_JETONU` salt okunur bir jeton olmalı (fine-grained, yalnız panel depoları,
  Contents ve Actions: read). Erişilemezse kontrol "hata" der, panelin başka yeri etkilenmez.
- Çalışan sürüm derlemede imaja yazılır (`ARG SURUM`, iş akışı commit'i verir). Derlemeye commit
  vermeyen platformlarda (NineDeploy gibi) bağlamdaki `.git`'ten okunur (`docker/version-read.sh`;
  `.dockerignore` yalnız `HEAD` ve ref'leri alır).
- Panel GitHub'a çıkamıyorsa (kapalı ağ) kontrol "erişilemedi" der.

## Kimlik Yönetim Merkezi ile giriş

`KIMLIK_ACIK=true` ise panel kendi giriş formu yerine merkeze yönlendirir.
İstemci kimliği ve sırrı merkezdeki "İstemciler" ekranından alınır; dönüş
adresi oradaki kayıtla **birebir** aynı olmalıdır.

Merkez şirket içi bir adresteyse ve sertifikası genel bir otoriteden değilse
`KIMLIK_KOK` ile merkezin kök sertifikası gösterilir. Doğrulamayı kapatma
seçeneği **yoktur**: jeton takası sırasında araya giren biri istemci sırrını
okurdu.

## Veritabanı

Panel veritabanını ortamdan alır (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`). Varsayılan compose'taki `db` servisi; parolası `DB_PASSWORD`, ilk
açılışta kullanıcı ve veritabanı bununla kurulur. Açılışta panel veritabanını en çok
üç dakika bekler, gelmezse açılmaz (konteyner yeniden dener).

Dışarıdaki ya da platformun yönettiği bir MariaDB/MySQL: `DB_*`'ı ona çevirin ve
`VT_KOPYA=0` deyin. Göçler yine açılışta koşar: verilen kullanıcının şema
değiştirme yetkisi olmalı.

Oturumun saat dilimi konteynerin saatidir (`DB_TIMEZONE`, varsayılan `+03:00`): UTC'de
çalışan bir sunucuda TIMESTAMP sütunları 3 saat kaymış okunmasın.

### Panelden taşıma (Ayarlar → Veritabanı)

Yalnız yönetici. Panel hedefi sınar: bağlantı, sürüm, hedefin boş ve şu anki
veritabanından farklı olması, göçlerin ihtiyaç duyduğu yetkiler (tablo, dizin, yabancı
anahtar, değişiklik, yazma, silme). Sınama geçerse **Taşı** konteyneri yeniden başlatır.
Açılışta panel kapalıyken şu anki veritabanı hedefe kopyalanır ve her tablonun satır
sayısı iki tarafta karşılaştırılır. Bir adım tutmazsa panel şu anki veritabanıyla açılır
ve sebep sekmede görünür.

Panelden tanımlanan bağlantı `storage/app/database/connection.json` dosyasında APP_KEY ile
şifreli durur ve ortamdaki `DB_*` değerlerinin yerine geçer. `state.json` taşımanın
aşamasını tutar ve sır içermez. Yeniden başlatmayı konteyner içindeki `dc-restart`
yapar; bunun için yeniden başlatma politikası **`unless-stopped`** olmalı (compose'ta öyle).

Taşıma bittikten sonra eski veritabanına **kendiliğinden dönülmez**; taşıma anındaki
hâliyle durur. Bilerek dönmek için: uzak veritabanının dökümünü eskisine yükleyin,
sonra `storage/app/database` altındaki `connection.json` ve `state.json`'u silip
konteyneri yeniden başlatın. Dökümü yüklemezseniz taşımadan sonraki her şey kaybolur.

APP_KEY değişirse bağlantı dosyası çözülemez ve panel açılmaz. Kasadaki
parolalar da zaten bu anahtara bağlı: APP_KEY'i yedekleyin.


## Güncelleme

    git pull && docker compose up -d --build

Bir platformda: yeniden dağıtın (platform depoyu çekip derler). Göçler her açılışta
`--isolated` ile koşar; iki örnek aynı anda kalksa bile bir kez çalışır.

GitHub Actions her main'de imajı derleyip `ghcr.io/mustafa-ozen95/eys` altına da
yükler: hazır imaj isteyen kurulum derlemek yerine onu kullanabilir.
