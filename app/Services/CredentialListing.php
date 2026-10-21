<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Portal\PortalLogin;
use App\Services\Remote\TargetResolver;
use App\Services\Rotation\DriverRegistry;
use App\Services\Rotation\RotationLauncher;
use App\Services\Rotation\TopluTur;
use App\Support\GerekceKurali;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Liste ekranının verisini hazırlar.
 *
 * Blade'in işi göstermektir: daraltma, sıralama ve ALAN MASKELEME burada
 * biter. Görme yetkisi olmayan bir alanın değeri Blade'e HİÇ ULAŞMAZ —
 * "gönder ama ekranda gizle" güvenlik değildir.
 */
class CredentialListing
{
    /** Yetkisi olmayanın gördüğü işaret. Boş alandan ayırt edilebilsin diye. */
    private const MASKED = '•••';

    /*
     * Grup yerine geçen değer: "hepsi". Adreste görünür (?grup=hepsi) ve
     * grup kimlikleri uuid olduğu için bir grupla çakışamaz.
     */
    public const ALL_GROUPS = 'hepsi';

    public function __construct(
        private UserPermissionResolver $resolver,
        private DriverRegistry $rotation,
        private RotationLauncher $launcher,
    ) {}

    /** @return array<string, mixed> */
    public function forRequest(Request $request): array
    {
        $user = $request->user();
        $term = $request->string('q')->squish()->value() ?: null;
        $platform = $request->string('platform')->value() ?: null;

        /*
         * GRUP SAYILARI LİSTEYLE AYNI KURALI KULLANIR.
         *
         * Sayı yalnızca "etkin mi" diye sorsaydı kayıt bazında bir role
         * kapatılmış kayıtlar (credential_role_restrictions) sayıya katılır;
         * rol grupta "3" görür, açınca tek bir kayıt bulurdu.
         *
         * Tek kural: Credential::scopeVisibleTo. Listenin gösterdiği ne
         * ise sayı da odur; ayrışacak ikinci bir tanım yok.
         */
        $groups = CredentialGroup::query()
            ->visibleTo($user)
            ->withCount(['credentials' => fn ($query) => $query->visibleTo($user)])
            /*
             * ALT GRUPLAR DA AYNI KURALLA SAYILIR: grubun sayısı neyi
             * gösteriyorsa bölmenin sayısı da onu gösterir. Alt grup yetki
             * taşımadığı için görünürlük sorusu yine kayda sorulur.
             */
            ->with(['altGruplar' => fn ($query) => $query
                ->withCount(['credentials' => fn ($q) => $q->visibleTo($user)])])
            ->ordered()
            ->get();

        /*
         * Arama ya da platform süzgeci açıkken grup daraltması KALKAR.
         * Daraltmanın iki kaynağı olamaz.
         */
        $daraltmaKalksin = $term !== null || $platform !== null;

        $selectedGroup = $this->selectGroup(
            $groups,
            $request->string('grup')->value(),
            $daraltmaKalksin,
            $user,
            $request->string('kayit')->value(),
        );

        $credentials = $this->credentials($user, $selectedGroup, $term, $platform);
        $selected = $this->selectCredential($credentials, $request->string('kayit')->value());
        $rows = $credentials->map(fn (Credential $c): array => $this->row($user, $c, $selectedGroup === null));

        return [
            /*
             * LİSTE BÖLÜMLERİ: grup seçiliyken ve grubun bölmeleri varken
             * satırlar alt grup başlıkları altında dizilir. Bölmesiz kayıtlar
             * en sonda başlıksız durur. Başlık tıklanmaz ve liste bölmeye
             * daraltılmaz: bölme yalnızca listeleme içindir. Grubun bölmesi
             * yokken tek bölüm, başlıksız.
             */
            'bolumler' => $this->bolumler($rows, $selectedGroup),

            /*
             * SÜRÜKLE-BIRAK SIRASI — YALNIZCA YÖNETİCİ. Bir gruba
             * bakılıyorken, arama ve süzgeç kapalıyken:
             * süzülmüş listede sürüklemek görünmeyen satırların yerini
             * tahmin ettirirdi. Sunucu ayrıca denetler.
             *
             *   siralanabilir  bölüm içindeki kayıtlar (credentials.sirala)
             *   bolumSirasi    grubun alt grup bölümleri (groups.alt.sirala)
             */
            'siralanabilir' => $siralanabilir = $user->isAdmin() && $selectedGroup !== null && ! $daraltmaKalksin,
            'bolumSirasi' => $siralanabilir && $selectedGroup->altGruplar->isNotEmpty()
                ? route('groups.alt.sirala', $selectedGroup)
                : null,
            /*
             * KİŞİSEL ALAN BOŞKEN DE ÇİZİLİR.
             *
             * "Boş yer kaplamasın" diye gizlenmez: hiç kaydı olmayan
             * kullanıcı bölümü göremezse ilk kaydını nereye ekleyeceğini
             * bilemez. Boş bir başlık burada yer kaybı değil, YOL TARİFİ.
             */
            'groups' => $groups,
            'selectedGroup' => $selectedGroup,

            /*
             * Arama formunun TAŞIDIĞI grup. Form grubu yalnızca bir grup
             * seçiliyken taşısaydı "Hepsi"nde ve arama sürerken bilgi düşer,
             * arama silinince ilk grup açılırdı. Aramanın başlatıldığı yer
             * taşınır;
             * göremediği bir grubun kimliği adresten geri basılmaz.
             */
            'aramaGrubu' => $this->aramaGrubu($groups, $request->string('grup')->value(), $selectedGroup),

            /*
             * Listenin başındaki HEPSİ satırı. Sayı grupların toplamı
             * DEĞİL, kullanıcının gerçekten görebildiği kayıt sayısıdır:
             * ikisi tek bir kayıt gizlendiğinde ayrışır ve ekranda yanlış
             * sayı durur.
             */
            /*
             * "YENİ KAYIT" DÜĞMESİ BAKILAN GRUBA GÖRE ÇIKAR.
             *
             * Kural: BİR GRUBA BAKIYORSAN ve oraya yazabiliyorsan düğme var.
             *
             * "Hepsi" görünümünde ve aramada düğme YOK: orada hedef bir grup
             * yok, düğme "nereye ekleyeceğini sen bul" demek olurdu. Yeni
             * kayıt, eklenecek grubun içinden eklenir.
             */
            'eklenebilir' => $selectedGroup !== null
                && $this->resolver->allows(
                    $user, CredentialPolicy::RESOURCE, 'create', $selectedGroup->getKey(),
                ),

            'allGroups' => self::ALL_GROUPS,
            'allCount' => $this->visibleCount($user),
            'rows' => $rows,

            /*
             * KİMLİK SÜTUNLARI: yetkisi yoksa hiç çizilmez.
             *
             * Hücrelerinde yalnızca "•••" yazan sütunlar işe yaramaz:
             * kullanıcının yapabileceği bir şey yok — yetkiyi kendisi
             * veremiyor — ve panel zaten kimliği GÖSTERMEDEN erişim
             * sağlıyor (arayüz vekili, konsol). Boş bir sütun, ekranı
             * işe yaramayan iki kolonla daraltmaktan başka bir şey değil.
             *
             * Karar LİSTEYE göre: görünen satırlardan en az birinde kimlik
             * okunabiliyorsa sütunlar durur. Satır satır açıp kapamak
             * ızgarayı bozardı.
             */
            /*
             * SÜTUN, YETKİ VARSA ÇİZİLİR — "maske değilse" demek yetmez.
             *
             * Satırın BASILAN METNİNE bakılmaz: kullanıcı adı ve parolası hiç
             * olmayan bir kayıtta o metin "•••" değil boştur ve "demek ki
             * görebiliyor" diye okunursa yetkisi olmayan kullanıcı
             * "Kullanıcı / Parola" sütunlarını görür.
             *
             * Soru metne değil YETKİYE sorulur: bu listede kimliği gerçekten
             * okuyabildiği en az bir satır var mı?
             */
            /* Uplink sütunu yalnızca listede uplink'i dolu bir satır varsa çizilir (switch grupları). */
            'uplinkSutunu' => $rows->contains(fn (array $row): bool => $row['uplink'] !== null),

            'kimlikSutunlari' => $rows->contains(
                fn (array $row): bool => $row['kimlikGorunur'],
            ),

            /* "Kaçı döndürmeye hazır" — listeye bakan bunu görmek için kayıt açmasın. */
            'rotationReady' => $rows->whereNull('rotationBlocked')->count(),

            'selected' => $selected === null ? null : $this->detail($user, $selected),

            /*
             * Kullanıcının süren (ya da yeni biten) toplu turu. İlk çizim
             * sunucudan gelir, yoklama devralır (bkz. toplu-ilerleme.js).
             */
            'topluTur' => $this->topluTur($user),
            'query' => $term,
            'platform' => $platform,
            'platforms' => ['' => 'Tüm platformlar', ...OperatingSystem::options()],

            /* Ekleme/düzenleme penceresi listenin üstünde açılır; formun verisi de buradan gider. */
            'window' => $this->window($request, $user, $selected),
            ...$this->formData($user, $groups, $selectedGroup, $selected),
        ];
    }

    /**
     * Form pencerelerinin ortak verisi: kullanıcının yazabileceği gruplar.
     *
     * Ekleme ile düzenleme AYRI yetkilerdir, o yüzden ayrı listelerdir: bir
     * gruba kayıt ekleyebilmek oradaki kaydı değiştirebilmek demek değildir
     * (bkz. CredentialRequest::yazilabilirGruplar). Tek liste verilseydi
     * düzenleme kutusunda kaydın kendi grubu bulunmayabilir, kaydetmek onu
     * sessizce başka gruba taşırdı.
     *
     * Gruplar listeyle AYNI kümedir; ikinci kez sorulmaz.
     *
     * @param  Collection<int, CredentialGroup>  $groups
     * @return array<string, mixed>
     */
    private function formData(
        User $user,
        Collection $groups,
        ?CredentialGroup $selectedGroup = null,
        ?Credential $selected = null,
    ): array {
        return [
            'groupOptions' => $this->groupOptions($user, $groups, 'create'),
            'updateGroupOptions' => $this->groupOptions($user, $groups, 'update'),

            /*
             * Alt grup kutusu: bütün bölmeler tek listede, grup adıyla
             * başlıklı; ekranda seçili gruba göre daralır (data-bagli).
             * Kullanıcının göremediği grubun bölmesi listede yok — $groups
             * zaten görünenlerdir.
             */
            'altGrupOptions' => $this->altGrupOptions($groups),
            'altGrupHaritasi' => $groups
                ->mapWithKeys(fn (CredentialGroup $g): array => [
                    $g->uuid => ['', ...$g->altGruplar->pluck('uuid')->all()],
                ])
                ->all(),
            'accessTypes' => AccessType::options(),
            'operatingSystems' => OperatingSystem::options(),
            'rotationDrivers' => $this->rotation->options(),

            /*
             * GÜNCELLEME HESABI KASADAN SEÇİLEBİLİR.
             *
             * Plesk gibi sürücülerde parolayı değiştiren şey makinenin SSH
             * kimliğidir; o kimlik kasada zaten duruyor. Elle kopyalamak
             * yerine kayıt SEÇİLİR — parola her turda o kayıttan okunur ve
             * SSH parolası döndüğünde eskiyen bir kopya kalmaz
             * (bkz. MakineHesabi).
             *
             * Listede YALNIZCA KULLANICININ GÖREBİLDİĞİ SSH kayıtları var:
             * göremediği bir kaydı seçebilseydi, adını ve adresini oradan
             * öğrenirdi.
             *
             * İKİ AYRI LİSTE: ekleme penceresi bakılan grubu, düzenleme
             * penceresi KAYDIN KENDİ grubunu öne alır. Aramada ve "hepsi"
             * görünümünde bakılan grup yoktur ama kaydın grubu vardır;
             * tek listeyle bu ikisi ayrışırdı.
             */
            'rotationCredentialOptions' => $this->rotationCredentialOptions(
                $user, $selectedGroup, null, $this->rotation->makineErisimTurleri(),
            ),
            /*
             * LİSTE KAYITLI SÜRÜCÜYE GÖRE DARALTILMAZ.
             *
             * Daraltmak kısır döngü yaratır: kullanıcı sürücüyü
             * "Plesk · Windows" yapar ama liste KAYITLI sürücüye (Linux) göre
             * kurulursa yalnızca SSH kayıtlarını içerir — o makinede SSH yok,
             * liste boş. Hesabı seçmek için önce sürücüyü kaydetmek,
             * kaydetmek için de hesabı seçmek gerekirdi.
             *
             * Liste bir SEÇİM YARDIMCISIDIR; geçerlilik kararını sürücü
             * sunucuda veriyor (bkz. MakineHesabi::eksikSebep).
             */
            'updateRotationCredentialOptions' => $this->rotationCredentialOptions(
                $user, $selected?->group, $selected, $this->rotation->makineErisimTurleri(),
            ),

            /*
             * Hangi sürücülerin makinenin SSH kimliğini istediği: ekran
             * "kasadan seç" alanını yalnızca onlarda gösterir.
             */
            'makineHesabiSurucileri' => $this->rotation->makineHesabiIsteyenler(),

            /*
             * KAYIT BAZINDA ROL KISITI — yalnızca yöneticiye.
             *
             * Yetkiyi kısıtlayabilen kişi kısıtın kendisini de görebilmeli;
             * kısıtı göremeyen biri "neden bu kayıt listede yok" sorusunu
             * çözemezdi. Yönetici rolleri listede YOK: yönetici bütün
             * kontrolleri geçiyor, işaretlemek yanıltıcı olurdu.
             */
            'canRestrict' => $user->isAdmin(),
            'restrictableRoles' => $user->isAdmin()
                ? Role::query()->where('is_admin', false)->orderBy('position')->orderBy('label')->get()
                : collect(),
        ];
    }

    /**
     * Kasanın tamamında döndürmeye hazır kayıt sayısı.
     *
     * Liste sorgusundan AYRI durur: liste seçili grubu gösterir, bu sayı
     * kasanın tamamını anlatır. İkisi karıştırılırsa düğme "3 kaydı
     * döndüreceğim" deyip kasanın tamamını döndürür.
     */
    /** Kullanıcının görebildiği etkin kayıt sayısı — HEPSİ satırının sayısı. */
    private function visibleCount(User $user): int
    {
        return Credential::query()->visibleTo($user)->count();
    }

    /**
     * Güncelleme hesabı olarak seçilebilecek kayıtlar.
     *
     * ÖNCE KENDİ GRUBU. Kasadaki bütün SSH kayıtları düz bir sırayla
     * listelenirse, kayıtların çoğunun adı yalnızca "SSH" olduğunda ekranda
     * aynı ad tekrar tekrar yazar, hangisinin hangi makine olduğu
     * anlaşılmaz. Makineyi çoğu zaman GRUP söyler ("srv-a", "Grup A");
     * bu yüzden önce kaydın kendi grubundaki SSH kayıtları, grup adıyla
     * listelenir.
     *
     * DİĞER GRUPLAR YİNE DURUYOR, ama ayrı bir başlık altında ve grup adıyla
     * yazılı: makinenin SSH kaydı başka bir grupta tutuluyorsa seçilemez
     * duruma düşmesin.
     *
     * @return array<string, string|array<string, string>>
     */
    private function rotationCredentialOptions(
        User $user,
        ?CredentialGroup $group = null,
        ?Credential $haric = null,
        array $turler = [],
    ): array {
        $kayitlar = Credential::query()
            ->visibleTo($user)
            /* Kişisel kayıt güncelleme hesabı olamaz (bkz. CredentialRequest::bagliHesapKurali). */
            ->otomasyon()
            ->whereIn('access_type', $turler === [] ? [AccessType::Ssh->value] : $turler)
            ->where('is_active', true)
            ->whereNotNull('username')
            /* KAYIT KENDİNİ SEÇEMEZ: kendi parolasıyla kendini döndürmek olurdu. */
            ->when($haric !== null, fn ($sorgu) => $sorgu->whereKeyNot($haric->getKey()))
            ->with('group')
            ->orderBy('name')
            ->get()
            /*
             * Parolası kullanıcıya AÇIK olmayan kayıt seçilemez: seçilebilse
             * tur o kaydın kimliğini kullanıcının göremediği hâlde
             * kullanırdı. Yönetici için süzme yok (hepsini açabilir).
             */
            ->filter(fn (Credential $k): bool => $user->isAdmin() || $user->can('reveal', $k));

        [$kendi, $digerleri] = $kayitlar->partition(
            fn (Credential $k): bool => $group !== null && $k->credential_group_id === $group->getKey(),
        );

        $secenekler = ['' => 'Seçilmedi — hesabı aşağıya elle yazın'];

        if ($kendi->isNotEmpty()) {
            $secenekler['Bu grup — '.$group->name] = $this->rotationCredentialLabels($kendi);
        }

        if ($digerleri->isNotEmpty()) {
            $secenekler['Diğer gruplar'] = $this->rotationCredentialLabels($digerleri);
        }

        return $secenekler;
    }

    /**
     * Seçeneklerin etiketi: GRUP ADI + KAYIT ADI.
     *
     * Grup adı yalnızca "Diğer gruplar" başlığındakilere yazılmaz; kendi
     * grubundakilere de yazılır. KUTU KAPALIYKEN BAŞLIK GÖRÜNMEZ: ekranda
     * seçili değer olarak yalnızca "#1 · SSH" kalırdı ve SSH kayıtlarının
     * adı çoğu zaman düpedüz "SSH"; tek başına kayıt adı hangi makine
     * olduğunu söylemiyor.
     *
     * ETİKETTE AD VE GRUP VAR, KİMLİK YOK. Kullanıcı adı ve adres kayıt
     * bazında ayrı yetkilerle korunuyor; listeye yazmak onları görme yetkisi
     * olmayan birine göstermenin yolu olurdu (bkz.
     * CredentialWriteTest::goremedigi_alan_duzenleme_penceresinde_basilmaz).
     * Grup adı korunan bir alan değil: formdaki grup kutusunda zaten yazıyor.
     *
     * @param  Collection<int, Credential>  $kayitlar
     * @return array<string, string>
     */
    private function rotationCredentialLabels(Collection $kayitlar): array
    {
        return $kayitlar
            ->mapWithKeys(fn (Credential $k): array => [
                (string) $k->getKey() => '#'.$k->getKey().' · '
                    .($k->group === null ? '' : $k->group->name.' · ')
                    .$k->name,
            ])
            ->all();
    }

    /**
     * Kullanıcının verilen işlemi yapabildiği gruplar.
     *
     * @param  Collection<int, CredentialGroup>  $groups
     * @return array<string, string>
     */
    private function groupOptions(User $user, Collection $groups, string $action): array
    {
        return $groups
            ->filter(fn (CredentialGroup $g): bool => $this->resolver->allows(
                $user, CredentialPolicy::RESOURCE, $action, $g->getKey(),
            ))
            ->pluck('name', 'uuid')
            ->all();
    }

    /**
     * Alt grup seçenekleri: "Yok" + grup başlıkları altında bölmeler.
     *
     * @param  Collection<int, CredentialGroup>  $groups
     * @return array<string, string|array<string, string>>
     */
    private function altGrupOptions(Collection $groups): array
    {
        $secenekler = ['' => 'Yok'];

        foreach ($groups as $group) {
            if ($group->altGruplar->isNotEmpty()) {
                $secenekler[$group->name] = $group->altGruplar->pluck('name', 'uuid')->all();
            }
        }

        return $secenekler;
    }

    /**
     * Satırları alt grup başlıkları altında böler.
     *
     * Sıra bölmelerin kendi sırasıdır (position, ad); bölmesiz kayıtlar en
     * sonda, başlık null. Boş bölüm çizilmez: boş başlık "burada bir şey
     * yok" demekten başka bir şey söylemiyor.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{baslik: ?string, uuid: ?string, rows: Collection<int, array<string, mixed>>}>
     */
    private function bolumler(Collection $rows, ?CredentialGroup $group): array
    {
        if ($group === null || $group->altGruplar->isEmpty()) {
            return [['baslik' => null, 'uuid' => null, 'rows' => $rows]];
        }

        $bolumler = [];

        foreach ([...$group->altGruplar->all(), null] as $bolme) {
            $satirlar = $rows->filter(fn (array $row): bool => $row['altGrupUuid'] === $bolme?->uuid)->values();

            if ($satirlar->isNotEmpty()) {
                $bolumler[] = ['baslik' => $bolme?->name, 'uuid' => $bolme?->uuid, 'rows' => $satirlar];
            }
        }

        return $bolumler;
    }

    /**
     * Açık gelecek pencere: 'ekle', 'duzenle' ya da null.
     *
     * Doğrulama hatasından sonra tarayıcı listeye geri döner ve adres çubuğu
     * DEĞİŞMEZ; hangi formun hata verdiğini yalnızca eski girdi bilir, bu
     * yüzden adresten önce ona bakılır.
     *
     * Karar Blade'de değil burada verilir: pencere yetkisi olmayana açılmaz.
     */
    private function window(Request $request, User $user, ?Credential $selected): ?string
    {
        $window = old('_pencere') ?? $request->string('pencere')->value();

        return match ($window) {
            'ekle' => $user->can('create', Credential::class) ? 'ekle' : null,
            'duzenle' => $selected !== null && $user->can('update', $selected) ? 'duzenle' : null,
            default => null,
        };
    }

    /**
     * Liste satırı — yalnızca görülebilen alanlar.
     *
     * @return array<string, mixed>
     */
    private function row(User $user, Credential $credential, bool $showGroup): array
    {
        $fields = $credential->visibleFieldsFor($user);

        return [
            'uuid' => $credential->uuid,
            'name' => $credential->name,

            /*
             * "Hepsi" ve aramada satır nereden geldiğini söyler: bölmesi
             * varsa "Grup A · Bölme 1". Grup görünümünde bölme BAŞLIK olarak
             * zaten yazıyor (bkz. bolumler), satırda tekrar etmez.
             */
            'group' => $showGroup ? $this->grupEtiketi($credential) : null,
            'altGrupUuid' => $credential->altGrup?->uuid,

            /*
             * Satırın bağlantısı KENDİ grubunu taşır.
             *
             * Grupsuz istek varsayılan olarak İLK gruba düşer; "Hepsi"
             * görünümünde bağlantı grupsuz üretilirse kullanıcı hangi kayda
             * basarsa bassın ilk gruba gider. Kaydın grubu
             * bağlantıda yazınca hem doğru sayfa açılır hem de kayıt seçili
             * gelir.
             */
            'groupUuid' => $credential->group->uuid,
            'type' => $credential->access_type,
            'platform' => $credential->platform ?: $credential->operating_system->label(),
            'target' => $this->target($credential, $fields),
            'uplink' => $this->mask($credential->uplink, $fields['uplink']),
            /*
             * Sütunların çizilip çizilmeyeceği buradan okunuyor: alanı
             * GÖRME YETKİSİ. Kaydın o alanı dolu mu boş mu, ayrı bir soru.
             */
            'kimlikGorunur' => (bool) $fields['username'] && (bool) $fields['password'],
            'username' => $this->mask($credential->username, $fields['username']),
            'password' => $this->passwordLabel($credential, $fields['password']),
            'isActive' => $credential->is_active,

            /*
             * Toplu seçim kutusu yalnızca GERÇEKTEN döndürülebilen satırda
             * çalışır. Sunucu bunu ayrıca kontrol eder; bu yalnızca ekran.
             *
             * Neden çalışmadığı da satırda durur: "kutu neden yok" sorusunun
             * cevabı için kaydı tek tek açmak gerekmesin.
             */
            /*
             * TOPLU kural: kutu, toplu turda gerçekten çalıştırılacak
             * satırlarda çıkar. Tek kaydı elle güncellemek "otomatik
             * güncellemeye açık" kutusuna bağlı değildir (bkz.
             * RotationLauncher::reasonToSkip).
             */
            'rotationBlocked' => $this->launcher->reasonToSkip($credential, $user, toplu: true),
        ];
    }

    /**
     * Detay paneli.
     *
     * @return array<string, mixed>
     */
    /**
     * Konsol açılabilir mi?
     *
     * Yetkiden AYRI bir soru: yetkisi olan biri, adresi ya da kimliği
     * yazılmamış bir kayıt için de düğmeyi görürdü ve tıklayınca hata alırdı.
     */
    private function consoleReady(Credential $credential): bool
    {
        /*
         * VNC'DE KULLANICI ADI ARANMAZ: RFB yalnızca parola soruyor, sunucu
         * kullanıcı adını hiç görmüyor. Burada da şart koşulsaydı, çözümleyici
         * kaydı kabul ettiği hâlde düğme görünmez ve sebebi de yazılmazdı —
         * "neden yok" sorusunun cevabı yine kullanıcıda kalırdı.
         */
        return in_array($credential->access_type, TargetResolver::PROTOKOLLER, true)
            && filled($credential->host)
            && ($credential->access_type === AccessType::Vnc || filled($credential->username))
            && $credential->has_password;
    }

    private function detail(User $user, Credential $credential): array
    {
        $fields = $credential->visibleFieldsFor($user);

        $facts = [
            'Tür' => $credential->access_type->label(),
            'Platform' => $credential->platform ?: $credential->operating_system->label(),
            'Adres' => $this->mask($credential->host, $fields['host']),
            'Uplink' => $this->mask($credential->uplink, $fields['uplink']),
            'Port' => $this->mask($credential->port === null ? null : (string) $credential->port, $fields['port']),
            'Bağlantı' => $this->mask($credential->url, $fields['url']),
            'Durum' => $credential->is_active ? 'Etkin' : 'Pasif',

            /*
             * Kritik olmayan kayıtta satır HİÇ ÇIZILMEZ (null, array_filter
             * eler): "Kritik: hayır" satırı kayıtların hepsinde durup hiçbir
             * şey anlatmazdı. İşaret bir istisnadır, görünmesi gereken de o.
             */
            'Gerekçe' => $credential->is_critical ? 'İşlemlerde sorulur' : null,
        ];

        return [
            'model' => $credential,
            'uuid' => $credential->uuid,
            'name' => $credential->name,
            'groupName' => $this->grupEtiketi($credential),
            'altGrupUuid' => $credential->altGrup?->uuid,

            /* Bu kayıt hangi rollere kapalı (düzenleme formu işaretli gelsin). */
            'restrictedRoleUuids' => $user->isAdmin()
                ? $credential->restrictedRoles()->pluck('uuid')->all()
                : [],

            /* Bu kayıtta hangi rollere ayrıca parola açılmış. */
            'grantedRoleUuids' => $user->isAdmin()
                ? $credential->grantedRoles()->wherePivot('action', 'field.password')->pluck('uuid')->all()
                : [],

            /* Bu kayıtta hangi rollere ayrıca KONSOL açılmış. */
            'konsolRoleUuids' => $user->isAdmin()
                ? $credential->grantedRoles()->wherePivot('action', 'connect')->pluck('uuid')->all()
                : [],

            /* Bu kaydı hangi roller ayrıca GÖREBİLİYOR / PORTAL açabiliyor. */
            'gormeRoleUuids' => $user->isAdmin()
                ? $credential->grantedRoles()->wherePivot('action', 'view')->pluck('uuid')->all()
                : [],

            /* Rol istisnası tablosunun seçimi: uuid => gizli | gorur (yoksa gruba göre). Kısıt önce gelir. */
            'rolGorunum' => $user->isAdmin()
                ? [
                    ...array_fill_keys($credential->grantedRoles()->wherePivot('action', 'view')->pluck('uuid')->all(), 'gorur'),
                    ...array_fill_keys($credential->restrictedRoles()->pluck('uuid')->all(), 'gizli'),
                ]
                : [],
            'portalRoleUuids' => $user->isAdmin()
                ? $credential->grantedRoles()->wherePivot('action', 'session')->pluck('uuid')->all()
                : [],

            /*
             * Yazılı onayda ne yazılacağı.
             *
             * Kayıt adı uzun olabiliyor ("vCenter Appliance Yönetimi
             * 192.0.2.10:5480"); tehlikeli bir işlemi onaylamak için onu
             * harfi harfine yazmak insanı yıldırır. ADRES hem kısa hem
             * de kullanıcının zaten bildiği şey. Adresi görme yetkisi yoksa
             * ya da kayıtta adres yoksa ada düşülür: maskelenmiş bir değeri
             * yazdırmak imkânsız olurdu.
             */
            'confirmVerify' => $fields['host'] && filled($credential->host)
                ? (string) $credential->host
                : $credential->name,

            /*
             * Onay penceresi GEREKÇE soracak mı?
             *
             * Yanıt kaydın "kritik" işaretinden gelir ve aynı yanıt sunucuda
             * da kullanılır (bkz. GerekceKurali). Burada ikinci bir koşul
             * yazılsaydı ekran ile sunucu bir gün ayrışır, kullanıcı
             * doldurmadığı bir alan yüzünden reddedilirdi.
             */
            'gerekceIster' => GerekceKurali::ister($credential),

            /*
             * "Parola güncelleme" başlığındaki soru işaretinin metni: bu
             * kaydın türü için cihazda neyin açık olması gerektiği. Sürücüye
             * göre değişir, o yüzden kayıttan geliyor (bkz. DriverRegistry).
             */
            'dondurmeGereksinimi' => $this->rotation->gereksinim($credential),
            'facts' => array_filter($facts, fn (?string $value): bool => filled($value)),

            /*
             * Düzenleme penceresi de aynı maskeyi kullanır: detayda "•••"
             * gösterilen alan formda açık açık yazılırsa maskelemenin
             * anlamı kalmaz.
             */
            'fields' => $fields,

            /*
             * Görünen ama DEĞİŞTİRİLEMEYEN hedef alanları: kayıtta parola ya
             * da döndürme kimliği var ve düzenleyenin parola yetkisi yok
             * (bkz. Credential::kilitliHedefAlanlari). Form bunları salt
             * okunur çizer; sunucu zaten düşürür.
             */
            'kilitliAlanlar' => $credential->kilitliHedefAlanlari($user),

            /* Not yetkisi yoksa metin Blade'e hiç gitmez. */
            'notes' => $fields['notes'] ? $credential->notes : null,
            'notesHidden' => ! $fields['notes'] && filled($credential->notes),

            /*
             * KULLANICI ADI PAROLAYLA AYNI YERDE.
             *
             * Künye satırlarının arasında kopyalanamaz; oysa oturum açan
             * kişi ikisini birlikte kullanıyor. Parola gibi
             * kendi kutusunda, kopyalama düğmesiyle duruyor — ama maskeli
             * değil: kullanıcı adı sır değil, yalnızca yetkiyle görünür bir
             * alan (field.username).
             */
            'hasUsername' => filled($credential->username),
            'username' => $fields['username'] ? $credential->username : null,

            'hasPassword' => $credential->has_password,
            /* Kullanıcı adı ya da parola var mı: "kayıtlı değil" satırlarının ön koşulu. */
            'kimlikVar' => filled($credential->username) || $credential->has_password,
            'canReveal' => $fields['password'] && $credential->has_password,
            'canUpdate' => $user->can('update', $credential),
            'canDelete' => $user->can('delete', $credential),

            /*
             * Döndürme düğmesi yalnızca gerçekten çalışacaksa görünür;
             * çalışmayacaksa NEDENİ yazılır. "Düğme neden yok" sorusunun
             * cevabı kullanıcıda kalmasın.
             */
            /*
             * KONSOL: parolayı göstermeden uzaktaki makineye oturum açar.
             *
             * Düğme yalnızca gerçekten çalışacaksa görünür; çalışmayacaksa
             * NEDENİ yazılır — "düğme neden yok" sorusunun cevabı
             * kullanıcıda kalmasın.
             */
            'canConnect' => $user->can('connect', $credential),
            'consoleReady' => $this->consoleReady($credential),
            'consoleProtocol' => in_array($credential->access_type, TargetResolver::PROTOKOLLER, true)
                ? $credential->access_type->label()
                : null,

            /*
             * İKİ AYRI YETENEK, iki ayrı yol.
             *
             * canSession  portal fırlatma: parola tarayıcıdan gönderiliyor,
             *             bu yüzden parolayı görme yetkisi de aranıyor.
             * canArayuz   cihaz arayüzü vekili: oturum sunucuda açılıyor,
             *             parola tarayıcıya hiç gitmiyor.
             *
             * Tek yetenekle sorulsaydı, vekilden açılabilecek bir arayüz
             * yalnızca parolayı görebilenlere görünürdü — var olmayan bir
             * sızıntıya karşı gereksiz bir önlem.
             */
            'canSession' => $user->can('session', $credential),
            'canArayuz' => $user->can('arayuz', $credential),
            'portalReady' => $credential->can_open_portal_session,
            'arayuzTuru' => $credential->arayuz_turu,
            'portalOnceki' => $this->portalOnceki($credential, $fields),
            'portalLanding' => $this->portalLanding($credential, $fields),
            'portalLandingBekle' => PortalLogin::inisGecikmesi($credential),

            /*
             * Arayüz adresi: portal oturumu kurulamayan kayıtlarda bile
             * "aç" demek mümkün olsun. ESXi gibi form girişi olmayan
             * arayüzlerde tek tık giriş yapılamıyor ama adresi elle
             * kopyalatmanın da anlamı yok.
             */
            'webUrl' => $this->webUrl($credential, $fields),
            'canRotate' => $user->can('rotate', $credential),
            'rotationBlocked' => $this->rotation->blockedReason($credential),
            'rotatedAt' => $credential->rotated_at,
            /*
             * Detayda YALNIZCA son tur durur.
             *
             * Birden çok satır listelemek paneli geçmiş dökümüne çevirir; geçmişin
             * yeri kayıtlar ekranı. Oraya kayda göre süzülmüş bir bağlantı
             * verilir (bkz. "Tümü").
             */
            'rotations' => $user->can('rotate', $credential)
                ? $credential->rotations()->limit(1)->get()
                : collect(),

            /*
             * Makinede kurtarma hesabı DURUYOR mu? Açıkta duran fazladan bir
             * yetki, kapatılana kadar bir açıktır; ekran bunu susarak
             * geçmemeli.
             */
            'stuckRotation' => $user->can('rotate', $credential)
                ? $credential->rotations()->where('recovery_active', true)->first()
                : null,

            /*
             * SÜREN tur. Kuyruğa alınan iş cihaza göre yarım dakikayı
             * bulabilir; o süre boyunca ekranda çubuk durur ve aşamayı
             * yoklar. İlk çizim sunucudan gelir, yoklama devralır — çubuğun
             * bir kare boş görünmesi bile "bir şey olmuyor" diye okunurdu.
             */
            'runningRotation' => $user->can('rotate', $credential)
                ? $credential->rotations()->running()->latestFirst()->first()
                : null,

            /*
             * Kuyrukta BEKLEYEN tur. Tur kaydı işçi başlayınca açılıyor;
             * arada kalan saniyelerde çubuk yine de görünmeli, yoksa düğmeye
             * basan kullanıcı hiçbir şey olmadığını sanıp tekrar basar.
             */
            /* Sıradaki turun adım sayısı: "0/3" mü "0/6" mı, sürücü söyler. */
            'pendingSteps' => $this->rotation->adimSayisi($credential),

            'pendingRotation' => $user->can('rotate', $credential)
                && RotationLauncher::waiting($credential, $credential->rotations()->latestFirst()->first()),
        ];
    }

    /**
     * VMware arayüzünün yolu.
     *
     * vSphere Client /ui/ altındadır; appliance YÖNETİM arayüzü (5480) ise
     * AYRI BİR UYGULAMADIR ve kökten açılır: https://<adres>:5480/ui/
     * 404 veriyor, kök ise "VMware Appliance
     * Management" sayfasını açıp kendini #/login'e yolluyor. Koşulsuz /ui/
     * eklemek kullanıcıyı 404'e gönderir.
     *
     * ESXi'de kök yeterli değil: karşılama sayfası çıkıyor, istemci değil.
     */
    private function vmwarePath(Credential $credential): string
    {
        return $credential->port === config('rotation.vcenter.vami_port', 5480) ? '/' : '/ui/';
    }

    /**
     * Girişten sonra gidilecek adres — yalnızca adresi görebilene.
     *
     * Maskelenmiş bir alanı sayfa kaynağına data özniteliği olarak yazmak
     * maskeyi delerdi.
     *
     * @param  array<string, bool>  $fields
     */
    /**
     * Gönderimden önce gidilecek adres — yalnızca adresi görebilene.
     *
     * @param  array<string, bool>  $fields
     */
    private function portalOnceki(Credential $credential, array $fields): ?string
    {
        if (! ($fields['host'] ?? false) && ! ($fields['url'] ?? false)) {
            return null;
        }

        return PortalLogin::oncekiAdres($credential);
    }

    private function portalLanding(Credential $credential, array $fields): ?string
    {
        if (! ($fields['host'] ?? false) && ! ($fields['url'] ?? false)) {
            return null;
        }

        /* Ucuz olmak zorunda: vCenter'ın adresi ancak fırlatmada alınır. */
        return PortalLogin::inisAdresi($credential);
    }

    /**
     * Tarayıcıda açılabilecek adres.
     *
     * Kaydın kendi adresi varsa o; yoksa yalnızca web arayüzü olduğunu
     * bildiğimiz platformlarda adresten türetilir. Adresi görme yetkisi
     * yoksa bağlantı da verilmez — maskelenmiş bir alanı bağlantı olarak
     * sunmak maskeyi delerdi.
     *
     * @param  array<string, bool>  $fields
     */
    private function webUrl(Credential $credential, array $fields): ?string
    {
        if (filled($credential->url)) {
            return $fields['url'] ? (string) $credential->url : null;
        }

        if (blank($credential->host) || ! $fields['host']) {
            return null;
        }

        /* Adres ve şema tek yerden gelir (bkz. DeviceAddress): port 443 olmak zorunda değil. */
        $taban = DeviceAddress::for($credential);

        return match ($credential->operating_system) {
            OperatingSystem::Esxi, OperatingSystem::Vcenter => $taban.$this->vmwarePath($credential),
            OperatingSystem::Bmc => $taban.'/',
            default => null,
        };
    }

    /** @param  array<string, bool>  $fields */
    private function target(Credential $credential, array $fields): string
    {
        if (filled($credential->url)) {
            return $fields['url'] ? $credential->url : self::MASKED;
        }

        if (blank($credential->host)) {
            return '—';
        }

        if (! $fields['host']) {
            return self::MASKED;
        }

        if ($credential->port === null) {
            return $credential->host;
        }

        return $fields['port']
            ? "{$credential->host}:{$credential->port}"
            : $credential->host.':'.self::MASKED;
    }

    /** "Grup" ya da "Grup · Alt grup" — satırda ve detay başlığında aynı. */
    private function grupEtiketi(Credential $credential): string
    {
        return $credential->group->name
            .($credential->altGrup === null ? '' : ' · '.$credential->altGrup->name);
    }

    private function passwordLabel(Credential $credential, bool $visible): string
    {
        return match (true) {
            ! $credential->has_password => '—',
            $visible => 'kayıtlı',
            default => self::MASKED,
        };
    }

    private function mask(?string $value, bool $visible): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $visible ? $value : self::MASKED;
    }

    /**
     * Arama yapılıyorsa kayıtlar bütün gruplardan gelir.
     *
     * @return Collection<int, Credential>
     */
    private function credentials(
        User $user,
        ?CredentialGroup $group,
        ?string $term,
        ?string $platform,
    ): Collection {
        return Credential::query()
            ->visibleTo($user)
            /*
             * GÜNCELLEME HESABI DA ÖNDEN YÜKLENİR: listedeki her satır için
             * "bu kayıt döndürülebilir mi" sorusu soruluyor ve cevap seçilen
             * kimliğe bakıyor (bkz. MakineHesabi). Yüklenmeseydi satır
             * başına bir sorgu daha koşardı.
             */
            ->with(['group', 'altGrup', 'rotationCredential'])
            ->when($group !== null, fn ($query) => $query->whereBelongsTo($group, 'group'))
            ->search($term, $user)
            ->ofPlatform($platform)
            ->ordered()
            ->get();
    }

    /**
     * Ekranda hangi grup duruyor.
     *
     * Null dönmesi "grup daraltması yok" demektir; üç yoldan olur: arama
     * veya platform süzgeci açıktır, kullanıcı listenin başındaki HEPSİ'yi
     * seçmiştir, ya da hiç grubu yoktur.
     *
     * @param  Collection<int, CredentialGroup>  $groups
     */
    private function selectGroup(
        Collection $groups,
        ?string $uuid,
        bool $daraltmaKalksin,
        User $user,
        ?string $kayit,
    ): ?CredentialGroup {
        if ($daraltmaKalksin || $uuid === self::ALL_GROUPS) {
            return null;
        }

        /*
         * GRUP YOKSA KAYDIN KENDİ GRUBU.
         *
         * Kaydetme ve güncelleme yalnızca ?kayit= ile döner. Grup adreste
         * olmayınca ilk grup açılır ve kayıt orada bulunamazsa detay boş
         * kalır (güncellemeden sonra kullanıcı kaydı yerine ilk grubu
         * görürdü). Kaydı buradan çözmek, ?kayit=
         * ile gelen HER bağlantıyı düzeltir; yönlendirmelere tek tek grup
         * yazmak ise yenisi eklendiğinde yine unutulurdu.
         *
         * Kayıt kullanıcının göremediği bir kayıtsa sorgu boş döner ve eski
         * davranışa düşülür — başkasının kaydının grubu adresten sızmaz.
         */
        if (blank($uuid) && filled($kayit)) {
            $grupId = Credential::query()->visibleTo($user)->where('uuid', $kayit)->value('credential_group_id');
            $kayitGrubu = $grupId === null ? null : $groups->firstWhere('id', $grupId);

            if ($kayitGrubu !== null) {
                return $kayitGrubu;
            }
        }

        return $groups->firstWhere('uuid', $uuid) ?? $groups->first();
    }

    /** @param  Collection<int, CredentialGroup>  $groups */
    private function aramaGrubu(Collection $groups, ?string $istenen, ?CredentialGroup $secili): ?string
    {
        if ($istenen === self::ALL_GROUPS || ($istenen !== null && $groups->contains('uuid', $istenen))) {
            return $istenen;
        }

        return $secili?->uuid;
    }

    /**
     * Toplu tur göstergesinin hazır verisi: sonuç sınıfı, durum etiketleri ve
     * yalnızca sayısı sıfırdan büyük olan lejant satırları.
     *
     * @return array{parti: string, durum: array<string, mixed>, sonuc: string, sorunSayisi: int, etiketler: array<string, string>, lejant: array<int, array{anahtar: string, etiket: string, sayi: int}>}|null
     */
    private function topluTur(User $user): ?array
    {
        $parti = TopluTur::son($user);
        $durum = $parti === null ? null : TopluTur::durum($user, $parti);

        if ($durum === null) {
            return null;
        }

        $sorunSayisi = $durum['basarisiz'] + $durum['dogrulanamadi'] + $durum['baslamadi'];
        $lejant = [];

        foreach (TopluTur::ETIKETLER as $anahtar => $etiket) {
            $sayi = collect($durum['kayitlar'])->where('durum', $anahtar)->count();

            if ($sayi > 0) {
                $lejant[] = ['anahtar' => $anahtar, 'etiket' => $etiket, 'sayi' => $sayi];
            }
        }

        return [
            'parti' => $parti,
            'durum' => $durum,
            'sorunSayisi' => $sorunSayisi,
            'sonuc' => $durum['calisiyor'] ? 'suruyor' : ($sorunSayisi === 0 ? 'basarili' : 'sorunlu'),
            'etiketler' => TopluTur::ETIKETLER,
            'lejant' => $lejant,
        ];
    }

    /** @param  Collection<int, Credential>  $credentials */
    private function selectCredential(Collection $credentials, ?string $uuid): ?Credential
    {
        return $uuid === null ? null : $credentials->firstWhere('uuid', $uuid);
    }
}
