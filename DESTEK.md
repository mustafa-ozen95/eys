# Neler destekleniyor

Panelin **ne yapabildiği** ve **ne ölçüde** yapabildiği. Bir satırın burada
olması, o yolun kodda var ve testle kilitlenmiş olduğu anlamına gelir; sahada
hangi sürümlerle denendiği ayrıca yazılıdır.

## Parola döndürme — 16 sürücü

Hepsi aynı altı adımlı turu izler (bkz. `README.tr.md` → "Parola döndürmenin
sırası"): geçici kurtarma hesabı açılır, onunla bağlanılabildiği kanıtlanır,
asıl parola değiştirilir, yeni parola **yeni bir oturumla** denenir, kasa
ancak o zaman güncellenir, kurtarma hesabı silinir.

Tur kuyrukta çalışır ve cihaza göre birkaç saniyeden yarım dakikaya kadar sürer. Sağdaki panelde
**ilerleme çubuğu** o süre boyunca hangi adımda olunduğunu yazar; çubuk
sunucudaki aşamayı okur, tahmin etmez. Tur sürerken "Parolayı güncelle"
düğmesi görünmez: ikinci tur zaten kilide takılıp düşerdi.

| Sürücü | Bağlanma yolu | Ne ölçüde |
| --- | --- | --- |
| `ssh` — Linux | SSH | Tam tur; kurtarma hesabı açılır ve silinir |
| `windows` — Windows / OpenSSH | SSH | Tam tur |
| `winrm` — Windows / WinRM | WinRM | Tam tur |
| `winrm_kurtarmali` — Windows / WinRM | WinRM | Geçici hesaplı değişken; yerel yönetici gerektirir |
| `vsphere` — ESXi | SSH | Tam tur; ESXi kabuğu açık olmalı |
| `vcenter` — vCenter appliance (root) | SSH | Tam tur |
| `vcenter_sso` — vCenter SSO (`administrator@vsphere.local`) | API | Parola değiştirir; SSO ilkesi parolayı reddedebilir |
| `ilo` — HPE iLO | Redfish ya da RIBCL | Nesli **kendi bulur**, uygun protokole düşer |
| `redfish` — HPE iLO | Redfish (HTTPS) | Protokolü elle sabitlemek için |
| `ribcl` — HPE iLO | RIBCL (XML) | Redfish'i desteklemeyen eski nesiller için |
| `plesk` — Plesk paneli | SSH | Panel hesabının parolasını döndürür |
| `mailcow` — alan yöneticisi | API anahtarı | Mailcow API'si üzerinden |
| `mailcow_admin` — süper yönetici | API anahtarı | Mailcow API'si üzerinden |
| `rspamd` — Rspamd arayüzü | HTTP | Arayüz parolasını döndürür |
| `aten_cn8000` — ATEN CN8000A (KVM over IP) | HTTPS (cihazın kendi ucu) | Tam tur; kurtarma hesabı açılır ve silinir |
| `lantronix_spider` — Lantronix Spider / SpiderDuo | SSH (belgelenmiş CLI) | Tam tur; doğrulama web girişinden |

### KVM over IP cihazları

Her model **ayrı sürücüdür**: ikisi birbirine hiç benzemiyor, ortak bir
soyutlama zorlamak ikisini de kırılgan yapardı. Üçüncü bir model gelirse yine
ayrı sürücü yazılır; protokol notları `.ai/rules/kvm-cihazlari.md` altında.

| Cihaz | Sürücü | Yol | Doğrulama |
| --- | --- | --- | --- |
| ATEN CN8000A | `aten_cn8000` | Web `/Inquery` uçları (HTTPS) | Web girişi |
| Lantronix Spider / SpiderDuo (SLS) | `lantronix_spider` | Belgelenmiş SSH komut satırı | Web girişi |

İkisinde de **tam tur ölçüldü**: kurtarma hesabı açıldı, onunla bağlanıldı,
asıl parola değişti, yeni parola yeni bir oturumla doğrulandı, eski parolanın
reddedildiği görüldü, kurtarma hesabı silindi ve silindiği doğrulandı.

**ATEN CN8000A — neden SSH değil:** cihazda SSH ve Telnet açık ama ikisi de
cihaz yönetimi için DEĞİL, RS-232 seri konsol geçişi için (ATEN kılavuzu).
Ölçüldü: SSH girişi başarılı oluyor, oturum boş geliyor, yönetim komutu yok.
Parola yalnızca web arayüzünden değişir ve güncelleme kullanıcı adını değil
**UID'yi** ister — ad verilince cihaz "u" yanıtı verip hiçbir şey yapmıyor,
yani sessizce başarısız oluyor.

**Lantronix Spider — belgelenmiş CLI:** `set user add|password|delete`,
`show user`. Dört tuzağı var: satır sonu **CR** olmalı (`\n` ile komut
yankılanıyor ama çalışmıyor), silme **onay soruyor ve varsayılanı HAYIR**
(yanıtlanmazsa kurtarma hesabı cihazda kalır), ve doğrulama SSH'tan
yapılamaz — parolası döndürülen hesabın CLI yetkisi olmayabilir, o yüzden web
girişi kullanılır. Dördüncüsü en sinsisi: CLI'dan değişen parola web
arayüzüne **gecikmeli** geçiyor, yani değişimin hemen ardından yapılan web
girişi doğru parolayı reddedebiliyor. Doğrulama bu yüzden tek kanala
bağlanmaz — web reddederse SSH denenir, o da reddederse web'e dönülür.

**İkisinde de ortak:** cihazlar kendi imzaladıkları sertifikayı sunuyor ve
eski şifre paketleri konuşuyor; sürücüler bunu bilerek kabul ediyor. Bu
cihazın sınırı, sürücünün tercihi değil.

**Her cihaz için İKİ kayıt tutun:** yönetici hesabı ve kullanıcı hesabı.
Kullanıcının parolasını değiştiren şey yönetici hesabıdır, yani yöneticinin
parolası kullanıcı kaydının `rotation_password` alanında da bir **kopya**
olarak durur. Panel bu kopyayı kendisi tazeler: yönetici kaydı döndürülünce
**aynı adresteki** diğer kayıtların döndürme kimliği de yeni parolayla
güncellenir. (Adres şart: aynı kullanıcı adı — `administrator`, `root` —
onlarca cihazda bulunur.)

Yöneticinin **kendi** parolası da döndürülebilir; tur o zaman geçici bir
yönetici hesabı açar, onunla bağlanır, asıl parolayı değiştirir, sonucu
sınar ve geçici hesabı siler. Kasadaki döndürme kimliği de aynı anda
güncellenir, yoksa bir sonraki tur kendi kimliğiyle bağlanamazdı.

**Cihaz meşgulse tur düşmez, bekler.** İki KVM de aynı anda tek bağlantı
kaldırıyor ve bir tur sırasında cihaza altı kez gidiliyor: ATEN yanıt
vermeden kapatıyor (`Empty reply from server`), Spider 22'yi kısa süre
kapatıyor (`Connection refused`). Sürücüler bu iki durumu **ulaşılamama**
sayıp bekleyerek yeniden dener; "parola yanlış" ile karıştırmazlar. Bu ayrım
kasanın doğruluğu için kritiktir: ulaşılamama `false` sayılsaydı, parolası
cihazda değişmiş bir kayıt "doğrulanamadı" diye kasaya yazılmaz ve kasa ile
cihaz ayrışırdı. (Ölçülen davranış: geçici hatayla "doğrulanamadı" sayılan
aynı parola bir dakika sonra sorunsuz doğrulanıyor.)

**Ortak koşul:** sürücü, kayıtta **döndürme kimliği** (`rotation_username` /
`rotation_password`) yoksa çalışmaz ve panel sebebini ekranda yazar. Turu
yarıda kalan kayıt "onar" düğmesiyle kapatılır; kuyruk işi `tries = 1`, çünkü
yarım kalmış bir döndürmeyi körlemesine tekrarlamak cihazı **kimsenin
bilmediği** bir parolayla bırakır.

Her sürücünün en az bir test dosyası vardır. Testler sahte
uçlarla koşar: gerçek bir ESXi ya da iLO gerektirmez, bu yüzden **"testi var"
ile "sizin sürümünüzde denendi" aynı şey değildir.** Yeni bir cihaz sınıfını
üretime almadan önce `erisim:kasa-denetle` ile salt okuma denetimi yapın —
yalnızca giriş dener, hiçbir şey değiştirmez.

## Uzak oturum — tarayıcıdan

| Protokol | Nasıl | Koşul |
| --- | --- | --- |
| **SSH** | `ttyd` | Her zaman çalışır |
| **RDP** | Guacamole (guacd + Tomcat, json-auth) | Yalnızca `GUACAMOLE_SECRET` verilmişse |

`GUACAMOLE_SECRET` verilmezse 200 MB'lık JVM **hiç açılmaz**; SSH yine
çalışır, yalnızca RDP çalışmaz. İki proxy de nginx `auth_request` ile
korunur: oturumu olmayan doğrudan erişemez.

Guacamole'un kimlik doğrulaması **json-auth**'tur — panel imzalayıp
şifrelediği JSON'u POST eder. Bu yüzden Guacamole'un kendi veritabanı yoktur;
tek gereken paylaşılan sırdır.

### RDP açılmıyorsa: sertifikanın imzası

Panelde **"The remote desktop server is currently unreachable"**, guacd
günlüğünde **"SSL/TLS connection failed (untrusted/self-signed
certificate?)"** görülüyorsa sebep büyük olasılıkla GÜVEN DEĞİL, İMZA
ALGORİTMASIDIR.

OpenSSL 3, SHA-1 imzalı sertifikaları güvenlik düzeyi 1 ve üstünde
reddediyor. FreeRDP bu düzeyi kendi içinde sabitliyor, yani panel tarafında
bir ayarla aşılamıyor — `OPENSSL_CONF` ile denendi ve `SSL_CTX_set_security_level`
çağrısı yapılandırmayı eziyor (ölçüldü).

Windows 7 / Server 2008 R2 kendi ürettiği RDP sertifikasını **her zaman**
SHA-1 imzalar; sertifikayı silip yeniden ürettirmek işe yaramaz. Çözüm
makinede SHA-256 imzalı bir sertifika üretip RDP'ye bağlamaktır
(`certreq`, her Windows sürümünde var). Sertifika yine kendi imzalıdır —
panel zaten güveni doğrulamıyor — değişen yalnızca imza algoritmasıdır.

Ölçmek için: makinenin 3389'una TLS ile bağlanıp sertifikanın
`Signature Algorithm` alanına bakılır. Windows 8.1 / Server 2012 R2 ve
sonrası zaten SHA-256 üretiyor.

## Cihaz arayüzü vekili

Bazı yönetim arayüzlerinin oturumu **dışarıdan açılamıyor**: ESXi Host Client
her SOAP çağrısına istemcinin ürettiği bir CSRF jetonu koyuyor, VAMI oturum
jetonunu `localStorage`'da tutuyor. Panelin dışarıdan attığı bir form girişi
ikisini de kuramaz. Çözüm arayüzü **panelin kendi kökeninden** vermek.

Vekil arkasında açılan arayüzler (`ARAYUZ_TURLERI`):

| Arayüz | Giriş nerede yapılıyor | Parola tarayıcıya gider mi |
| --- | --- | --- |
| **ESXi Host Client** | Tarayıcıda, arayüzün kendi akışıyla | Hayır — tek kullanımlık uçtan alınıp Angular modeline yazılır, form alanına yazılmaz |
| **VAMI** (vCenter 5480) | Sunucuda; yalnızca jeton tarayıcıya iner | Hayır |
| **Plesk** | Sunucuda | Hayır |
| **iLO 4** | Sunucuda | Hayır — yalnızca `sessionKey` çerezi iner |
| **vSphere Client** | Sunucuda (SAML üç adımı) | Hayır |
| **DirectAdmin müşteri portalı** | Sunucuda | Hayır |
| **QNAP (QTS)** | Sunucuda | Hayır — giriş ucu XML döndürüyor, çerezi panel kuruyor |

**vSphere Client** tarayıcı yolunda "ikinci açılışta SAML sağlayıcısı 401
veriyor" sorunu yaşar — tarayıcıda duran eski vCenter çerezi yüzünden. Vekilde
oturumu sunucu açıyor ve çerez panelde duruyor: arka arkaya iki giriş de 0,6
saniyede geçiyor, `/ui/` vekilden 200 dönüyor. Kazancı, "arayüz açma" yetkisi
olan bir rolün parolayı GÖRMEDEN girebilmesi — portal fırlatma parolayı
tarayıcıya gönderdiği için parolayı görme yetkisi isterdi.
**VM konsolu** da kapsamda; iki koşula dayanır: (1) vekil `Connection`
başlığını sabit boş gönderirse websocket yükseltmesi cihaza hiç ulaşmaz —
vCenter `426 Upgrade Required` der; (2) vCenter 8 konsolu kendi üzerinden
geçiriyor ve ROTASI cihazın kökünde duruyor (`/webconsole-ticket/<n>/<hash>`),
taban dizinine çevrilirse arayüzün bilet ucuna düşüp `"An error occurred while
retrieving the web console URL."` döner. İkisi de karşılanıyor; ölçüldü: el
sıkışma **101**, konsol açılıyor.

Konsol ayrı bir pencerede değil **sekmede** açılır: vSphere pencereye boyut
veriyor ve boyut gören tarayıcı açılır pencere açıyor; vekilin enjekte ettiği
betik yalnızca boyutu düşürüyor.

**DirectAdmin müşteri portalı** vekilleniyor çünkü portal
giriş isteğinde `Referer` denetliyor ve o başlığı tarayıcı yazıyor: panelden
gönderilen bir form asla geçemiyor. Vekil, Referer ve Origin başlıklarını
cihazın kökeniyle yeniden kuruyor. Bedeli — üçüncü tarafın sayfalarının
panelin kökeninde çalışması — bilinerek kabul edilmiş bir ödündür.

Vekil **ayrı bir portta** (varsayılan 9443) durur. Bu asıl güvenlik
kazancıdır: panelin oturum çerezi konak-özeldir, oraya hiç gitmez ve cihazın
arayüzünden çıkan betik panele oturum sahibi adına istek atamaz. Kimlik oraya
**anlık bir devir jetonuyla** taşınır.

### Bağlantı havuzları

Vekil hedefi bir değişkenden okuyor; nginx böyle bir hedefe bağlantı havuzu
tutmuyor ve **her isteğe yeni bir TLS el sıkışması** yapıyor. Zayıf işlemcili
bir BMC'de bu tek başına yarım saniyeyi bulabilir; havuzdan alınan bağlantı
kat kat hızlıdır.

Arayüz onlarca varlık istiyor; havuzsuz sayfanın açılması çok uzar ve iLO'nun
çerçevesi `load` olayını o zamana kadar vermediği için **ekran boş kalır**.
Havuzlar kasadan üretiliyor:

```
php artisan erisim:arayuz-havuzlari        # yapılandırmayı yazdırır
/usr/local/bin/arayuz-havuzlari yaz        # /etc/nginx/conf.d altına yazar
```

Açılışta `eys-entrypoint` yazıyor, `arayuz-havuzlari izle` süreci beş dakikada
bir tazeleyip değiştiyse nginx'i yeniliyor. Listede olmayan bir cihaz eskisi
gibi (havuzsuz, yavaş ama **çalışarak**) bağlanır.

### Oturumlar cihazda da kapanır

Oturumu panel açıyor, dolayısıyla kapatmak da ona düşüyor. Kapatan olmazsa
cihaz kendi zaman aşımını bekliyor ve o süre boyunca oturum listede duruyor;
art arda birkaç açılışta liste doluyor ve cihaz yeni girişi reddediyor (iLO 4
bunu `Invalid login attempt` diye söylüyor).

Üç yol da aynı kapanışa çıkar (`ArayuzOturumu::kapat`): cihazda çıkış çağrısı,
önbelleğin temizlenmesi, izin kapatılması ve çerezin satırdan silinmesi.

* **Sekme kapandı:** sayfaya eklenen betik `pagehide` olayında `sendBeacon`
  ile haber veriyor. Bu haber oturumu **kapatmaz**, "sesi kesildi" diye
  işaretler — aynı olay sayfa yenilenirken de tetikleniyor ve tek bir F5
  oturumu kapatmamalı.
* **Süre doldu:** `erisim:konsol-temizle` dakikada bir koşuyor ve sesi kesileli
  `REMOTE_SESSION_TTL + 120` saniye geçmiş oturumları kapatıyor.

## Toplu parola çıktısı

Ekrandaki listenin ya da kasanın tamamının parolaları `.xlsx` olarak
indirilebilir. Parolayı panelin **dışına** çıkaran tek uç budur ve kuralları
ona göredir:

* Ayrı bir yetki ister (`export`). **"Parolayı görüntüle" bunu kapsamaz:**
  ekranda görülen parola orada kalır, dosyaya yazılan parola gittiği yerde
  panelin denetiminde değildir.
* Satır başına yetki ayrıca sorulur; göremediği kayıt dosyaya girmez.
* Gerekçe zorunludur ve **her kayıt için ayrı bir denetim satırı** yazılır —
  özet satır "hangi parola dışarı çıktı" sorusunu cevaplamazdı.
* Dakikada üç istek (`LIMIT_EXPORT`).

## Kayıt türleri

Kasadaki her kayıt bir türe bağlıdır: `ssh`, `rdp`, `remote_tool`, `panel`,
`web`, `ilo`, `esxi`, `vcenter`, `backup`, `database`. Tür, hangi sürücünün
önerileceğini ve portal oturumunun nasıl açılacağını belirler.

## Kimlik sağlayıcı ile giriş

Kimlik Yönetim Merkezi ile tek oturum (OAuth 2.0, Authorization Code + PKCE).
Kapalıyken panelin kendi giriş formu çalışır — **merkez zorunlu değildir.**

## Tarayıcıya gönderilen güvenlik başlıkları

Panelin her yanıtı `X-Frame-Options`, `Content-Security-Policy`
(`frame-ancestors`), `X-Content-Type-Options`, `Referrer-Policy`,
`X-Permitted-Cross-Domain-Policies`, `Strict-Transport-Security` ve
`Permissions-Policy` taşır. Değerler `config/security.php` içindedir.

Önemlisi çerçeve kilidi: burası bir parola kasası. Kilit olmasaydı herhangi
bir site paneli görünmez bir çerçeveye alıp üstüne kendi düğmesini koyabilir,
kullanıcı ona bastığında tıklama paneldeki "parolayı göster" düğmesine
gidebilirdi.

**Cihaz arayüzü vekili bu kilitten muaftır.** O uçlar paneli değil cihazın
kendi arayüzünü veriyor ve panel onu bilerek çerçeveye alıyor; üstelik vekil
ayrı kökende durabiliyor (ayrı port da ayrı kökendir). Kilidi oraya da
koysaydık bütün cihaz arayüzleri kapanırdı. Muafiyet rotanın kendisinden
okunur — o uçlar zaten `cihaz-arayuzu` grubunda — yani yeni bir uç eklendiğinde
ayrıca bir listeye yazmak gerekmez.

Bir yanıt kendi başlığını yazdıysa **ezilmez**: portal fırlatma sayfası bilerek
`Referrer-Policy: no-referrer` koyuyor, parolayı POST ettiği hedef bizim
adresimizi öğrenmesin diye.

Başlıklar nginx'te değil uygulamada durur — vekil değişince kaybolmasınlar
diye — ve genel ara katman yığınındadır, "web" grubunda değil: grupta en içte
kalsaydı giriş yönlendirmesi ve hata sayfaları çıplak kalırdı.

İçerik CSP'si `script-src 'self'` taşır (bkz. `config/security.php`): satır
içi `<script>` bloğu sessizce çalışmaz, bu yüzden her betik dosyadan
yüklenir.

## Girişte sınır — varsayılan kapalı

İki ayrı fren var, ikisi de varsayılan olarak **kapalı**:

| Ayar | Ne sayar | Varsayılan |
|---|---|---|
| `LOGIN_THROTTLE` | Giriş ucuna dakikada kaç istek | 0 (kapalı) |
| `LOGIN_MAX_ATTEMPTS` | Art arda kaç yanlış parola (e-posta+IP başına) | 0 (kapalı) |

İkincisinin anahtarı `config/auth.php`'dedir; okuyan taraf kendi
varsayılanına (5) düşmez.

**Sıfır "sınır yok" demektir ve bu kendiliğinden olmaz.** `throttle:0,1`
yazmak dakikada sıfır isteğe iner, `tooManyAttempts($k, 0)` ise ilk yanlış
paroladan sonra kilitler — ikisi de kapatmak isterken en sert hâli üretir. Bu
yüzden her iki yerde de sıfır ayrıca kontrol edilir ve katman hiç eklenmez.

İkisi birden kapalıyken bu panelde parola denemesinin önünde **hiçbir fren
kalmaz**; koruma dışarıdan gelmelidir (ters vekil, güvenlik duvarı, ağın
kendisi). Dışarı açılacaksa ikisine de bir sayı verin — önerilen değerler 10
ve 5.
