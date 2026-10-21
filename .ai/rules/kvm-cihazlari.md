# KVM over IP cihazları

Bu dosya, parola döndürülen KVM cihazlarının protokol notlarıdır. Amacı
**üçüncü bir model eklenirken neyin aranacağını** söylemek; sürücülerin kendisi
`app/Services/Rotation/Drivers/` altında.

## Kural: her model ayrı sürücü

İki cihaz ortak hiçbir şey konuşmuyor. Ortak bir "KVM sürücüsü" soyutlaması
ikisini de kırılgan yapardı: bir modelin tuhaflığı diğerinin yoluna girer,
birini düzeltmek öbürünü bozardı.

Yeni model gelirse **yeni sürücü** yazılır, var olanlara dokunulmaz.

## Önce şunu sor: yönetim nereden yapılıyor?

Açık port yanıltıcıdır. Her iki cihazda da SSH açıktı; birinde yönetim oradan
yapılıyor, diğerinde SSH yalnızca seri konsol geçişi.

Sıra şu:

1. **Üreticinin kılavuzunda komut satırı var mı?** Varsa en temiz yol odur —
   Lantronix'te tam bir komut referansı çıktı ve web arayüzünü çözmeye hiç
   gerek kalmadı.
2. Yoksa web arayüzünün kendi isteklerine bakılır. Uygulama kodu beklenmedik
   dosyalarda olabilir: ATEN'de mantık `sorttable.js` içindeydi, `ml.js` ise
   yalnızca dil dosyasıydı.
3. SSH'a "açık" diye güvenme: gir ve gerçekten komut kabul ediyor mu bak.

## Doğrulama SSH'tan yapılmaz

Parolası döndürülen hesabın komut satırı yetkisi olmak zorunda değil.
Lantronix'te `default` grubundaki kullanıcı SSH'a giremiyor ama web arayüzüne
giriyor. SSH ile doğrulanırsa yetkisi olmayan her hesapta tur "doğrulanamadı"
ile kapanır.

**Web girişi her kullanıcı için çalışır; doğrulama oradan yapılır.**

## Bilinen tuzaklar

Bunlar sessiz başarısızlıklardır: cihaz hata vermez, iş yapılmamış olur.

| Cihaz | Tuzak | Belirti |
| --- | --- | --- |
| ATEN CN8000A | Güncelleme kullanıcı adını değil **UID**'yi ister | Cihaz `u` döner, parola değişmez |
| ATEN CN8000A | Çerez bozuk: `sid=DEGER domain=...` (noktalı virgül yok) | Oturum tanınmaz, `com_close=` döner |
| ATEN CN8000A | Yanıtlar başlıksız gzip olabilir | İçerik binary görünür |
| ATEN CN8000A | Eşzamanlı oturum sevmez | Araya istek girerse oturum kapanır |
| Lantronix SLS | Satır sonu **CR** olmalı | Komut yankılanır ama çalışmaz, istem dönmez |
| Lantronix SLS | Silme onay sorar, varsayılan **HAYIR** | Kurtarma hesabı cihazda kalır |
| ATEN CN8000A | Meşgulken bağlantıyı yanıtsız kapatır | `Empty reply from server` |
| Lantronix SLS | Kapanan oturumun ardından 22'yi kısa süre kapatır | `Connection refused` / `Error reading SSH identification string` |
| Lantronix SLS | CLI'da değişen parola web arayüzüne gecikmeli geçer | Web girişi reddeder, aynı parola SSH'ta çalışır |

Silme satırı en tehlikelisi: geride tam yetkili, parolası kimsede olmayan bir
hesap bırakır. Silme çağrısı "başarılı" dönmedikçe tur başarılı sayılmamalı.

## Ulaşılamamak, reddedilmek DEĞİLDİR

Son iki satırın sonucu daha sinsi. İkisi de **geçici** meşguliyet; bir tur
içinde cihaza altı kez gidiliyor ve panelden art arda başlatılan turlarda
kaçınılmaz olarak denk geliniyor.

`verify()` bir boole döndürür; sürücü her hatayı `false`a çevirirse o `false`
çağıran yerde **"parola yanlış"** diye okunur.

Örnek (Spider): parola CİHAZDA değişir, doğrulama isteği bağlantı hatası alır,
sürücü `false` döndürür ve tur "yeni parola çalışmıyor" diyerek kasayı
güncellemez. **Kasada eski, cihazda yeni parola kalır** — bir parola kasası
için en kötü sonuç, üstelik fark ancak bir sonraki girişte anlaşılır; aynı
parola biraz sonra sorunsuz doğrulanır.

Kural: ulaşılamama `DeviceUnreachableException` ile yukarı fırlatılır ve
taşıma katmanında (her isteğin geçtiği yerde, tek tek çağıranlarda değil)
bekleyerek yeniden denenir. Yanlış parola tekrarlanmaz: cihaz birkaç hatalı
denemeden sonra hesabı kilitler ve kurtarılabilir bir hata kalıcı bir soruna
dönüşürdü.

## Tek bir "hayır" da kesin değildir

Spider'da parola **CLI'dan** değişiyor, doğrulama **web arayüzünden**
yapılıyor: iki ayrı alt sistem. CLI "successfully changed" dedikten hemen
sonraki web girişi REDDEDİLEBİLİR; aynı parola o sırada SSH'ta çalışır ve kısa
süre sonra web de kabul eder. Tur o "hayır"ı gerçek sanarsa kasayı güncellemez;
cihazda yeni, kasada eski parola kalır.

Doğrulama bu yüzden tek kanala bağlanmaz: **web → SSH → web**. SSH'taki ret
tek başına bir şey kanıtlamaz (CLI yetkisi olmayan hesap olabilir), ama
SSH'taki KABUL parolayı kanıtlar. Deneme sayısı üçte tutulur — cihaz art
arda hatalı girişten sonra hesabı kilitler.

Genel kural: parolayı YAZDIĞIN kanal ile DOĞRULADIĞIN kanal farklıysa,
aralarında gecikme olabileceğini varsay.

## Kayıt düzeni: yönetici + kullanıcı

Her cihaz için panelde **iki kayıt** tutulur. Kullanıcının parolasını
değiştiren şey yönetici hesabıdır, yani yöneticinin parolası kullanıcı
kaydının `rotation_password` alanında bir KOPYA olarak da durur.

Yönetici döndürülünce o kopya bayatlar ve hata ancak kullanıcı kaydı
döndürülmeye çalışılınca görünür. Bu yüzden `PasswordRotator::spreadIdentity()`
aynı **adresteki** kayıtların döndürme kimliğini de tazeler — adres şart,
çünkü `administrator` onlarca cihazda bulunur.

Yöneticinin kendi parolası döndürülürken tur geçici bir yönetici hesabı açar;
kimlik ile döndürülen hesap aynı olduğu için kasadaki `rotation_password` da
aynı anda güncellenir.

## Sertifikalar

İki cihaz da kendi imzaladığı sertifikayı sunuyor ve eski şifre paketleri
konuşuyor. Sürücüler bunu bilerek kabul ediyor (`SSL_VERIFYPEER` kapalı,
`SECLEVEL=0`). Bu **cihazın sınırı**, sürücünün tercihi değil — ve bu yüzden
KVM'ler doğrudan internete açılmamalı.
