<?php

declare(strict_types=1);

namespace App\Services\DeviceInterface;

use App\Enums\AccessType;
use App\Models\ConsoleSession;
use App\Models\Credential;
use App\Services\DeviceAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cihaz arayüzü için vekil oturumu.
 *
 * NEDEN VEKİL — kısaca: ESXi Host Client her SOAP çağrısına KENDİ ürettiği
 * `VMware-CSRF-Token` başlığını koyuyor ve ESXi oturumu girişteki jetona
 * bağlıyor. Panelin dışarıdan attığı bir form girişi o başlığı koyamaz, başka
 * bir kökenin localStorage'ına da yazamaz; jetonlar tutmaz ve arayüz giriş
 * ekranında kalır (ayrıntısı PortalLogin'deki "ESXİ NEDEN YOK" notunda).
 *
 * Çözüm arayüzü PANELİN KENDİ KÖKENİNDEN servis etmek. O zaman çerez de
 * localStorage da bizim tarafımızda olur, jetonu Host Client'ın kendisi üretir
 * ve her şey tutar. Host Client bunun için zaten hazır: kendi konağından
 * BAŞKA bir yerde çalıştığını anlayınca SOAP'ı vekile yollayıp hedefi
 * `x-vsphere-proxy` başlığında taşıyor (vCenter, host arayüzlerini böyle
 * gömüyor). Hedefi de `vmware_esx_host` ÇEREZİNDEN okuyor — o çerez bizim
 * kökenimizde olduğu için panel kurabiliyor.
 *
 * ESXi 8'de vekil arkasında giriş tamamlanıyor, /sdk 200 dönüyor, arayüz #/host ekranına düşüyor.
 *
 * Oturum kaydı ÖNBELLEKTE durur ve nginx bekçisi her istekte buraya sorar:
 * hangi kullanıcı, hangi cihaz, hâlâ geçerli mi.
 */
final class InterfaceSession
{
    /** Oturumun tarayıcıya verilen kimliği; adres ve çerez bununla. */
    public const COOKIE = 'eys-arayuz-oturum';

    /** ESXi Host Client hedefi bu çerezden okur; adı ONUN belirlediği addır. */
    public const KONAK_COOKIE = 'vmware_esx_host';

    /**
     * Adres önekinin KAYNAĞI.
     *
     * Sabit olarak burada durur çünkü ara katman yapılandırması (bootstrap/
     * app.php) ayarlar YÜKLENMEDEN önce çalışıyor ve orada config() çağrısı
     * uygulamayı hiç açtırmıyor. İki yere ayrı ayrı yazmamak için ayarın
     * kendisi de buraya bakar (bkz. config/remote.php).
     */
    public const PREFIX = '/device-session';

    /**
     * CİHAZ ADRESİNİN ÖNEKİ — panelin kendi uçlarından AYRI.
     *
     * İkisi aynı önekte olursa panelin uçları (devir, ayrılış haberi) cihaza
     * gidebilir: nginx'te önek "^~" olmaktan çıkınca sondaki genel kural
     * onları da yutar ve ekranda cihazın 404'ü çıkar. Ayrı önek bu
     * karışıklığı kökünden kaldırıyor.
     */
    public const DEVICE_PREFIX = '/cihaz';

    /**
     * Kaydın adres parçası: "ornek-panel-2".
     *
     * Adres oturum jetonu değil KAYDIN ADINI taşıyor; kullanıcı hangi
     * cihazda olduğunu adres çubuğundan görüyor. Sonundaki kimlik şart: iki
     * kayıt aynı adı taşıyabiliyor (ör. iki ayrı makinede "Plesk Panel").
     */
    public static function kayitSlug(Credential $credential): string
    {
        return Str::slug((string) $credential->name).'-'.$credential->getKey();
    }

    /** Cihazın adres öneki: /cihaz/<kaydın-adı>-<kimlik> */
    public static function devicePrefix(Credential $credential): string
    {
        return self::DEVICE_PREFIX.'/'.self::kayitSlug($credential);
    }

    /**
     * Adres parçasından kaydı bulur.
     *
     * Kimlik sondan okunur ve ad YENİDEN ÜRETİLİP karşılaştırılır: böylece
     * uydurulmuş bir ad başka bir kaydı açamaz.
     */
    public static function slugdanKayit(string $slug): ?Credential
    {
        if (preg_match('/-(\d+)$/', $slug, $eslesme) !== 1) {
            return null;
        }

        $credential = Credential::query()->find((int) $eslesme[1]);

        return $credential !== null && self::kayitSlug($credential) === $slug ? $credential : null;
    }

    /** Vekillenen arayüzlerin adres öneki. */
    /** Devri oturumu açan tarayıcıya bağlayan kısa ömürlü çerez. */
    public const DEVIR_BAG_COOKIE = 'eys-devir-bag';

    public static function prefix(): string
    {
        return rtrim((string) config('remote.interface.prefix'), '/');
    }

    /**
     * Vekilin TAM adresi.
     *
     * Ayrı bir alan adı verilmişse oraya, verilmemişse panelin kendi adresine.
     * Ayrı alan adı asıl güvenlik kazancıdır: panel oturum çerezi konak-özel
     * olduğu için oraya hiç gitmez ve cihazın arayüzü panele erişemez.
     */
    public static function taban(int $port = 0): string
    {
        $alan = trim((string) config('remote.interface.domain'));

        if ($alan === '') {
            return rtrim(url('/'), '/');
        }

        /*
         * OTURUMUN KENDİ PORTU VARSA ADRES ONDAN KURULUR.
         *
         * Ortak portta oturumu çerez taşıyordu ve çerez tarayıcıya ait: aynı
         * anda iki arayüz açan kullanıcının istekleri son açılana gidiyordu.
         * Port, cihazın MUTLAK adreslerinde bile korunuyor.
         */
        if ($port > 0) {
            return 'https://'.preg_replace('/:\d+$/', '', $alan).':'.$port;
        }

        return 'https://'.$alan;
    }

    /** Vekil ayrı bir kökende mi duruyor? */
    public static function ayriKoken(): bool
    {
        return trim((string) config('remote.interface.domain')) !== '';
    }

    /**
     * Arayüzün adresi.
     *
     * ADRES OTURUM ÖNEKİYLE AÇILIR: /device-session/<oturum>/<cihazın yolu>.
     *
     * Önek olmadan oturumu yalnızca ÇEREZ taşır; çerez sekmeye değil
     * TARAYICIYA ait olduğu için aynı anda iki arayüz açan kullanıcının
     * istekleri son açılan cihaza gider. İki Plesk'te bunu ayıracak hiçbir
     * ipucu kalmaz (ör. bir Plesk ve bir vCenter birlikte açıkken Plesk'te
     * sayfa değişince hata).
     *
     * Önek altında GÖRELİ adresler kendiliğinden doğru çözülür. Cihazın
     * MUTLAK adresleri (Plesk'in "/cp/javascript/…" gibi) yine köke düşer;
     * orada oturumu Referer söylüyor (bkz. InterfaceProxyWatcher) ve enjekte
     * edilen betik adres çubuğunu öneke geri yazıyor (bkz. ayrilis).
     *
     * Panelin kendi kökeninde adres zaten önekliydi: orada kök panelindir.
     */
    public static function pageUrl(string $token, InterfaceType $tur, ?Credential $credential = null, int $port = 0): string
    {
        if (! self::ayriKoken()) {
            return self::taban().self::prefix().'/'.$token.'/';
        }

        /*
         * Açılış betiği GEREKEN arayüzlerde sayfayı PANEL veriyor: cihazdan
         * alıp betiği ekliyor. Ötekilerde gerek yok — oturum sunucuda açıldı,
         * çerez vekilde.
         */
        if ($tur->acilisGorunumu() !== null) {
            return self::taban().self::prefix().'/page';
        }

        return self::taban($port).$tur->inisYolu();
    }

    /** Sayfaya yazılan taban adres (<base href>). */
    public static function tabanYolu(string $token): string
    {
        return self::ayriKoken() ? '/' : self::prefix().'/'.$token.'/';
    }

    public static function identityUrl(string $token): string
    {
        return self::ayriKoken()
            ? self::prefix().'/identity'
            : self::prefix().'/'.$token.'/identity';
    }

    public static function bootUrl(string $token): string
    {
        return self::ayriKoken()
            ? self::prefix().'/boot.js'
            : self::prefix().'/'.$token.'/boot.js';
    }

    /**
     * "Ayrıldım" haberinin adresi.
     *
     * Kimliği ÇEREZ taşır, adres değil: haber sayfa kapanırken atılıyor ve o
     * anda betiğin elinde jeton tutmak gerekmesin. Her iki kökende de aynı yol.
     */
    public static function leaveUrl(): string
    {
        return self::prefix().'/leave';
    }

    private function devirAnahtari(string $devir): string
    {
        return 'eys:interface-handoff:'.$devir;
    }

    /**
     * ANLIK devir jetonu üretir.
     *
     * Kimlik vekil kökenine bu jetonla taşınır: panelin çerezi oraya
     * gitmiyor, gitse de gitmemeli. Jeton TEK KULLANIMLIKTIR — adres
     * çubuğunda, tarayıcı geçmişinde ya da bir kayıt satırında kalsa bile
     * ikinci kez işe yaramaz.
     */
    public function devirJetonuUret(string $token, ?string $bag = null): string
    {
        $devir = Str::uuid()->toString();

        Cache::put($this->devirAnahtari($devir), [
            'token' => $token,
            'bag' => $bag === null ? null : hash('sha256', $bag),
        ], (int) config('remote.interface.handoff_ttl'));

        return $devir;
    }

    /**
     * Devir jetonunu HARCAR ve oturumu döndürür.
     *
     * Okuma ile silme arasında pencere bırakılmaz: `pull` ikisini birlikte
     * yapar, yoksa aynı jeton iki sekmede birden kullanılabilirdi.
     *
     * @return array<string, mixed>|null
     */
    public function devriHarca(string $devir, mixed $bag = null): ?array
    {
        $kayit = Cache::pull($this->devirAnahtari($devir));

        /* Eski biçim (yalnızca jeton) bağsız devirdir. */
        if (is_string($kayit)) {
            $kayit = ['token' => $kayit, 'bag' => null];
        }

        if (! is_array($kayit) || ! is_string($kayit['token'] ?? null)) {
            return null;
        }

        /*
         * Bağlı devir, yalnızca oturumu açan tarayıcının çerezini taşıyanla
         * harcanır. Jeton BAŞARISIZ denemede de yanmış olur (pull): bağsız
         * bir tarayıcıda açılan bağlantı bir daha kullanılamaz.
         */
        if ($kayit['bag'] !== null
            && (! is_string($bag) || ! hash_equals($kayit['bag'], hash('sha256', $bag)))) {
            return null;
        }

        return $this->bul($kayit['token']);
    }

    private function anahtar(string $token): string
    {
        return 'eys:interface-session:'.$token;
    }

    private function kullaniciAnahtari(int $userId, string $konak): string
    {
        return 'eys:interface-host:'.$userId.':'.mb_strtolower($konak);
    }

    public function omur(): int
    {
        return (int) config('remote.session.ttl');
    }

    /**
     * Oturumu açar ve kimliğini döndürür. Cihaza HİÇ bağlanılmaz: giriş
     * tarayıcıda, Host Client'ın kendi akışıyla yapılacak.
     *
     * @return array{token: string, konak: string, hedef: string, adres: string}
     */
    public function ac(Credential $credential, int $userId): array
    {
        $tur = InterfaceType::icin($credential);

        if ($tur === null) {
            throw new RuntimeException('Bu kayıt için vekillenecek bir arayüz yok.');
        }

        $taban = DeviceAddress::for($credential, $tur->varsayilanPort());
        $parca = parse_url($taban);
        $konak = (string) ($parca['host'] ?? $credential->host);
        $port = (int) ($parca['port'] ?? $tur->varsayilanPort());

        $token = Str::uuid()->toString();

        $kayit = [
            'token' => $token,
            'tur' => $tur->value,
            'user_id' => $userId,
            'credential_id' => $credential->getKey(),
            'label' => $credential->name,
            /* Host Client'ın çerezde göreceği ad — port 443 ise portsuz. */
            'konak' => $port === 443 ? $konak : $konak.':'.$port,
            /* nginx'in proxy_pass'e koyacağı hedef. */
            'hedef' => $konak.':'.$port,

            /* Sunucuda açılan oturumun cihaz çerezi; ötekilerde boş. */
            'cerez' => '',

            /*
             * OTURUMUN KENDİ PORTU.
             *
             * Arayüz kendi portunda açılır ve oturumu ADRES taşır; çerez
             * yalnızca "kim" sorusunu cevaplar. Cihazların MUTLAK adresleri
             * (Plesk'in "/admin/domain/list" gibi) portun dışına çıkamıyor —
             * yol önekinden çıkabildikleri gibi. İzolasyonu sağlam kılan fark
             * bu: arayüzler tek ortak portta toplanmaz, ayrı portlara dağılır.
             */
            'port' => $this->portAyir($userId, (int) $credential->getKey(), $token),
        ];

        /*
         * Oturumu SUNUCU açan arayüzlerde giriş BURADA yapılır ve cihazın
         * çerezi kayıtta durur; vekil her isteğe onu ekler. Parola böylece
         * kullanıcının tarayıcısına hiç uğramaz.
         */
        if ($tur->sunucudaGiris()) {
            $kayit['cerez'] = app(InterfaceLogin::class)->ac($tur, $credential, $kayit['hedef']);
        }

        /*
         * SIRRI ÇEREZ DEĞİL BAŞLIK TAŞIYAN ARAYÜZLER (Rspamd).
         *
         * Orada açılacak bir oturum yok: cihaz her istekte parolanın kendisini
         * bekliyor. Parola kayıtta durur ve vekil onu başlığa koyar; tarayıcı
         * yine görmez (bkz. InterfaceType::gizliBasligi).
         */
        if ($tur->gizliBasligi() !== null) {
            $kayit['gizli'] = (string) $credential->password;
        }

        Cache::put($this->anahtar($token), $kayit, $this->omur());

        /*
         * Konak dizini AYRI tutulur: Host Client SOAP'ı köke (/sdk) atıyor ve
         * o adreste oturum kimliği YOK. Bekçi orada "bu kullanıcının bu konağa
         * açık bir oturumu var mı" diye sorabilmeli. Aynı kullanıcı iki ayrı
         * ESXi'yi iki sekmede açtığında da doğru çalışsın diye anahtar
         * kullanıcı+konaktır, tek bir "son açılan" değil.
         */
        Cache::put($this->kullaniciAnahtari($userId, $kayit['hedef']), $token, $this->omur());

        /*
         * DENETİM İZİ önbellekten AYRI durur: önbellekteki kayıt kısa ömürlü
         * ve sessizce düşüyor; geriye "kapandı" satırı olmayan bir oturum
         * kalırdı. Konsol oturumlarıyla aynı tablo, aynı zamanlayıcı: sesi
         * kesilen oturumu `console:prune` kapatıp kayda geçiriyor.
         */
        ConsoleSession::create([
            'token' => $token,
            'user_id' => $userId,
            'credential_id' => $credential->getKey(),
            'label' => $credential->name,
            'protocol' => $tur === InterfaceType::Esxi ? AccessType::Esxi : AccessType::Panel,
            'port' => null,
            /*
             * Cihazın çerezi ÖNBELLEKTE DE durur ama orası kapanış için
             * yetmiyor: önbellekteki kayıt oturumun ömrü dolunca siliniyor ve
             * temizlik sırası geldiğinde elde çıkış yapacak bir şey kalmıyor.
             * Cihazda açtığımız oturumu cihazda kapatabilmek için çerez
             * DAYANIKLI yerde, şifreli olarak durur; kapanışta NULL'lanır.
             */
            'cihaz_cerezi' => $kayit['cerez'] !== '' ? $kayit['cerez'] : null,
            'opened_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [
            'token' => $token,
            'tur' => $tur,
            'konak' => $kayit['konak'],
            'hedef' => $kayit['hedef'],
            'adres' => self::prefix().'/'.$token.'/',
        ];
    }

    /** @return array<string, mixed>|null */
    public function bul(string $token): ?array
    {
        $kayit = Cache::get($this->anahtar($token));

        return is_array($kayit) ? $kayit : null;
    }

    /**
     * Host Client'ın istediği konağa, bu kullanıcının açık oturumu var mı?
     *
     * SSRF'e kapıyı burası kapatıyor: hedef, isteğin başlığından DEĞİL kendi
     * kaydımızdan geliyor. Başlık yalnızca "hangisini istiyorsun" sorusunun
     * cevabı; doğrulanmadan hiçbir yere bağlanılmaz.
     *
     * @return array<string, mixed>|null
     */
    public function konakIcin(int $userId, string $konak): ?array
    {
        $token = Cache::get($this->kullaniciAnahtari($userId, $konak));

        return is_string($token) ? $this->bul($token) : null;
    }

    /**
     * Kullanıcının BU TÜRLERDEN açık oturumu.
     *
     * NEDEN GEREKLİ: vekil kökeninde oturumu ÇEREZ taşıyor ve çerez sekmeye
     * değil TARAYICIYA ait. Kullanıcı iki arayüzü iki sekmede açtığında son
     * açılan kazanıyor; eski sekmenin istekleri yanlış cihaza gidiyor.
     *
     * Örnek: aynı anda Plesk ve vCenter açıkken vSphere'in "New Virtual
     * Machine" sihirbazı `/apigw` isteğini Plesk'e gönderir ve cevap olarak
     * Plesk'in 404 sayfasını alır. `/sdk` bu tuzağa düşmüyor çünkü
     * vSphere orada hedefi `x-vsphere-proxy` başlığında söylüyor; `/apigw`
     * ise ne o başlığı ne de Referer'ı taşıyor (erişim günlüğünde referer
     * alanı boş).
     *
     * Yol bir türe ÖZGÜYSE doğru oturum buradan bulunur: kullanıcının açık
     * oturumları taranır, türü tutan ilki döner. Çerezdeki oturum yine
     * sahibinin mi diye sorulur — başka kullanıcının oturumuna geçilmez.
     *
     * @param  list<InterfaceType>  $turler
     * @return array<string, mixed>|null
     */
    /** Havuzun ilk portu. */
    public static function ilkPort(): int
    {
        return (int) config('remote.interface.port_start', 0);
    }

    /** Havuzun son portu. */
    public static function sonPort(): int
    {
        return (int) config('remote.interface.port_end', 0);
    }

    private function portAnahtari(int $port): string
    {
        return 'eys:interface-port:'.$port;
    }

    /**
     * OTURUMA BİR PORT AYIRIR.
     *
     * Aynı kullanıcının aynı kayda ait açık oturumu varsa PORTU KORUNUR:
     * kullanıcı aynı cihazı ikinci kez açtığında adres değişmesin.
     *
     * Havuz boşsa 0 döner ve arayüz eski gibi ortak portta açılır — yer
     * kalmadı diye arayüzü hiç açmamak, çakışma riskinden kötüdür.
     */
    private function portAyir(int $userId, int $credentialId, string $token): int
    {
        $ilk = self::ilkPort();
        $son = self::sonPort();

        if ($ilk <= 0 || $son < $ilk) {
            return 0;
        }

        /*
         * HER AÇILIŞ KENDİ PORTUNU ALIR.
         *
         * Aynı kaydın portu korunmaz; her açılışta port farklı olabilir. Eski
         * oturum kapanınca portu havuza döndüğü için bu havuzu tüketmiyor.
         */

        /*
         * SIRAYLA DEĞİL RASTGELE.
         *
         * Sırayla verilince portlar tahmin edilebilir oluyor ve kapanan bir
         * oturumun portu hemen bir sonrakine geçiyor — kullanıcı aynı adreste
         * başka bir cihaz bulabilirdi. Yetki her istekte ayrıca sorulduğu
         * için bu bir güvenlik katmanı değil, karışıklık önlemi.
         */
        $portlar = range($ilk, $son);
        shuffle($portlar);

        foreach ($portlar as $port) {
            /* add() ATOMİK: iki istek aynı portu kapamaz. */
            if (Cache::add($this->portAnahtari($port), $token, $this->omur())) {
                return $port;
            }

            /*
             * DOLU GÖRÜNEN PORT ÖLÜ OLABİLİR: oturum düşmüş ama anahtar
             * süresini doldurmamış. Sahibi gerçekten yaşamıyorsa port geri
             * alınır; yoksa havuz zamanla kendiliğinden tükenirdi.
             */
            $sahibi = Cache::get($this->portAnahtari($port));

            if (is_string($sahibi) && $this->bul($sahibi) === null) {
                Cache::put($this->portAnahtari($port), $token, $this->omur());

                return $port;
            }
        }

        return 0;
    }

    /**
     * Portun oturumu.
     *
     * Adres cihazı, çerez kişiyi söylüyordu; portlu kipte ADRESİN KENDİSİ
     * portla geliyor ve çereze hiç gerek kalmıyor.
     *
     * @return array<string, mixed>|null
     */
    public function portIcin(int $port): ?array
    {
        if ($port <= 0) {
            return null;
        }

        $token = Cache::get($this->portAnahtari($port));

        return is_string($token) ? $this->bul($token) : null;
    }

    /**
     * Kullanıcının BU KAYDA ait açık oturumu.
     *
     * Adres önekinde oturum jetonu değil KAYDIN ADI duruyor; kullanıcı
     * cihazı adresten tanır. Ad kimin oturumu olduğunu söylemez; onu çerez
     * söylüyor.
     * Yani adres CİHAZI, çerez KİŞİYİ belirliyor — ikisi birlikte tek bir
     * oturuma işaret ediyor.
     *
     * Aynı kaydı iki sekmede açmak aynı oturumu paylaşır; zaten aynı cihaz,
     * aynı hesap.
     *
     * @return array<string, mixed>|null
     */
    public function kayitIcin(int $userId, int $credentialId): ?array
    {
        $acik = ConsoleSession::query()
            ->where('user_id', $userId)
            ->where('credential_id', $credentialId)
            ->whereNull('closed_at')
            ->orderByDesc('id')
            ->pluck('token');

        foreach ($acik as $token) {
            $kayit = $this->bul((string) $token);

            if ($kayit !== null) {
                return $kayit;
            }
        }

        return null;
    }

    public function turIcin(int $userId, array $turler): ?array
    {
        $aranan = array_map(static fn (InterfaceType $t): string => $t->value, $turler);

        $acik = ConsoleSession::query()
            ->where('user_id', $userId)
            ->whereNull('closed_at')
            ->orderByDesc('id')
            ->pluck('token');

        foreach ($acik as $token) {
            $kayit = $this->bul((string) $token);

            if ($kayit !== null && in_array((string) $kayit['tur'], $aranan, true)) {
                return $kayit;
            }
        }

        return null;
    }

    /**
     * Oturum yaşıyor: kullanıcı ekranda durduğu sürece düşmesin.
     *
     * Bekçi HER istekte çağrılıyor ve arayüz bir açılışta yüzlerce varlık
     * indiriyor; denetim satırına her seferinde yazmak veritabanını boşuna
     * döverdi. Önbellek ucuz, satır ise kısılarak tazelenir.
     */
    public function canlandir(array $kayit): void
    {
        Cache::put($this->anahtar($kayit['token']), $kayit, $this->omur());
        Cache::put($this->kullaniciAnahtari((int) $kayit['user_id'], (string) $kayit['hedef']), $kayit['token'], $this->omur());

        $kisit = 'eys:interface-session-limit:'.$kayit['token'];

        if (Cache::has($kisit)) {
            return;
        }

        Cache::put($kisit, true, 30);

        ConsoleSession::query()
            ->where('token', $kayit['token'])
            ->whereNull('closed_at')
            ->update(['last_seen_at' => now()]);
    }

    /**
     * "Sayfadan ayrıldım" haberi: oturumu sessiz sayar.
     *
     * KAPATMAZ. Aynı olay (pagehide) sayfa yenilenirken de tetikleniyor ve tek
     * bir F5 oturumu kapatsaydı kullanıcı panele geri dönmek zorunda kalırdı.
     * Bunun yerine son ses zamanı geriye alınır: gerçekten gidildiyse geri ses
     * gelmez ve dakikalık temizlik oturumu kapatır (cihazda çıkış dahil);
     * sayfa geri geldiyse ilk istek zamanı tazeler ve hiçbir şey olmaz.
     *
     * Ses KISITI da silinir: tazeleme otuz saniyede bire kısılıyor ve kısıt
     * durursa geri dönen sayfanın ilk isteği satırı tazeleyemezdi.
     */
    public function ayrildi(array $kayit): void
    {
        $token = (string) $kayit['token'];

        Cache::forget('eys:interface-session-limit:'.$token);

        ConsoleSession::query()
            ->where('token', $token)
            ->whereNull('closed_at')
            ->update(['last_seen_at' => now()->subSeconds($this->omur() + 180)]);
    }

    /**
     * Oturumu kapatır: cihazda çıkış yapar, izi ve önbelleği temizler.
     *
     * TEK KAPANIŞ YOLU BURASIDIR. Kapanış üç ayrı yerden tetikleniyor
     * (kullanıcı kapattı, tarayıcı gitti, süre doldu) ve üçünde de aynı üç
     * işin yapılması gerekiyor; ayrı ayrı yazılsaydı biri cihazda çıkış
     * yapmayı unutur ve cihazın oturum listesi dolardı.
     *
     * @return bool Cihazda oturum kapatılabildi mi?
     */
    public function kapat(ConsoleSession $oturum, string $sebep): bool
    {
        $token = (string) $oturum->token;
        $kayit = $this->bul($token);

        /*
         * Çerez önce SATIRDAN okunur: önbellekteki kayıt çoktan düşmüş
         * olabilir ve zaten en sık kapanış sebebi süre dolması.
         */
        $cerez = (string) ($oturum->cihaz_cerezi ?? ($kayit['cerez'] ?? ''));
        $tur = InterfaceType::tryFrom((string) ($kayit['tur'] ?? ''))
            ?? ($oturum->credential !== null ? InterfaceType::icin($oturum->credential) : null);

        $kapandi = false;

        if ($tur !== null && $cerez !== '') {
            $hedef = (string) ($kayit['hedef'] ?? $this->hedefi($oturum, $tur));

            $kapandi = $hedef !== '' && app(InterfaceLogout::class)->kapat($tur, $hedef, $cerez);
        }

        Cache::forget($this->anahtar($token));

        if ($kayit !== null) {
            Cache::forget($this->kullaniciAnahtari((int) $kayit['user_id'], (string) $kayit['hedef']));

            /*
             * PORT HAVUZA DÖNER.
             *
             * Dönmezse havuz yalnızca zaman aşımıyla boşalırdı ve sınırlı bir
             * port havuzu kısa sürede dolardı; sonraki arayüzler ortak
             * porta düşüp çakışmaya geri dönerdi.
             */
            $port = (int) ($kayit['port'] ?? 0);

            if ($port > 0) {
                Cache::forget($this->portAnahtari($port));
            }
        }

        /*
         * Çerez satırdan SİLİNİR: kapanmış bir oturumun anahtarı elimizde
         * durmamalı. Satırın kendisi denetim izi olarak kalır.
         */
        $oturum->update([
            'closed_at' => now(),
            'closed_reason' => $sebep,
            'cihaz_cerezi' => null,
        ]);

        return $kapandi;
    }

    /** Kaydı düşmüş oturumun cihaz adresini kasadan yeniden kurar. */
    private function hedefi(ConsoleSession $oturum, InterfaceType $tur): string
    {
        if ($oturum->credential === null) {
            return '';
        }

        $parca = parse_url(DeviceAddress::for($oturum->credential, $tur->varsayilanPort()));
        $konak = (string) ($parca['host'] ?? '');

        return $konak === '' ? '' : $konak.':'.((int) ($parca['port'] ?? $tur->varsayilanPort()));
    }

    /**
     * Tarayıcıdan gelen çerezlerden CİHAZA İLETİLECEKLERİ süzer.
     *
     * Panelin ve vekilin çerezleri cihaza GİTMEZ. nginx isteğin "Cookie"
     * başlığını olduğu gibi iletirse içinde panelin oturum çerezi de olur ve
     * onun cihazda işi yok. Süzme nginx'te yapılamıyor (çerez
     * filtreleyen bir yönerge yok), bu yüzden bekçi süzüp iletiyor.
     *
     * Sunucuda giriş yapılan arayüzlerde tarayıcıdan HİÇBİR çerez geçmez:
     * cihazın oturumu bizde duruyor.
     */
    public function cerezSuz(array $kayit, ?string $baslik): string
    {
        $tur = InterfaceType::tryFrom((string) ($kayit['tur'] ?? ''));

        $bizim = (string) ($kayit['cerez'] ?? '');

        if ($tur === null || $tur->sunucudaGiris()) {
            return $bizim;
        }

        $izinli = $tur->iletilecekCerezler();
        $gecen = [];

        foreach (explode(';', (string) $baslik) as $parca) {
            $parca = trim($parca);
            $ad = trim(explode('=', $parca, 2)[0] ?? '');

            if ($ad !== '' && in_array($ad, $izinli, true)) {
                $gecen[] = $parca;
            }
        }

        if ($bizim !== '') {
            array_unshift($gecen, $bizim);
        }

        return implode('; ', $gecen);
    }

    /**
     * `x-vsphere-proxy` başlığındaki adresten konak:port çıkarır.
     *
     * Host Client "https://192.0.2.10:443/sdk" biçiminde yolluyor.
     */
    public function basliktanKonak(?string $baslik): ?string
    {
        if ($baslik === null || $baslik === '') {
            return null;
        }

        $parca = parse_url($baslik);

        if (! isset($parca['host'])) {
            return null;
        }

        return $parca['host'].':'.($parca['port'] ?? 443);
    }
}
