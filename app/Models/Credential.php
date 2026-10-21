<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Concerns\HasUuid;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\DeviceInterface\InterfaceType;
use App\Services\Portal\PortalLogin;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tek bir erişim kaydı: "srv-a → SSH", "Grup A → iLO" gibi.
 *
 * Parola hiçbir listede, hiçbir JSON yanıtında ve hiçbir hata ayıklama
 * çıktısında BASILMAZ ($hidden). Ekranda görünmesi tek bir uçtan geçer ve o
 * uç her seferinde kayda yazar (bkz. Ajax\CredentialPasswordController).
 */
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'credential_group_id',
        'alt_grup_id',
        'owner_id',
        'name',
        'access_type',
        'operating_system',
        'platform',
        'rotation_driver',
        'rotation_username',
        'rotation_password',
        'rotation_credential_id',
        'rotation_enabled',
        'host',
        'uplink',
        'port',
        'rdp_security',
        'url',
        'login_url',
        'login_username_field',
        'login_password_field',
        'login_extra_fields',
        'username',
        'password',
        'notes',
        'is_active',
        'is_critical',
        'position',
    ];

    /**
     * Güncelleme hesabının KAYDI.
     *
     * Kopya yerine bağ: parola her turda o kayıttan okunur, SSH parolası
     * döndüğünde burası kendiliğinden güncel kalır (bkz. MachineAccount).
     *
     * @return BelongsTo<Credential, $this>
     */
    public function rotationCredential(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotation_credential_id');
    }

    protected $hidden = [
        'password',
        'notes',
        'rotation_password',
        'rotation_credential_id',
    ];

    protected function casts(): array
    {
        return [
            'access_type' => AccessType::class,
            'operating_system' => OperatingSystem::class,
            'password' => 'encrypted',
            'notes' => 'encrypted',
            'rotation_password' => 'encrypted',
            'rotation_enabled' => 'boolean',
            'rotated_at' => 'datetime',
            'login_extra_fields' => 'array',
            'is_active' => 'boolean',
            'is_critical' => 'boolean',
            'port' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return HasMany<PasswordRotation, $this> */
    public function rotations(): HasMany
    {
        return $this->hasMany(PasswordRotation::class)->latestFirst();
    }

    /** @return BelongsTo<CredentialGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CredentialGroup::class, 'credential_group_id');
    }

    /**
     * Grubun içindeki bölme; olmayabilir.
     *
     * Yetkiye katılmaz: görünürlük yalnızca gruba ve kayda sorulur
     * (bkz. scopeVisibleTo). Alt grup yalnızca nerede listeleneceğini söyler.
     *
     * @return BelongsTo<SubGroup, $this>
     */
    public function altGrup(): BelongsTo
    {
        return $this->belongsTo(SubGroup::class, 'alt_grup_id');
    }

    /**
     * Parola kayıtlı mı? Ekranda "kayıtlı / tanımlı değil" yazmak için;
     * parolanın kendisini hiçbir yerde döndürmez.
     *
     * @return Attribute<bool, never>
     */
    protected function hasPassword(): Attribute
    {
        return Attribute::get(fn (): bool => filled($this->getAttributeValue('password')));
    }

    /**
     * Portal oturumu açılabilir mi?
     *
     * Üç şey birden gerekir: giriş adresi, alan adları ve kayıtlı parola.
     * Biri eksikse form kurulamaz.
     */
    protected function canOpenPortalSession(): Attribute
    {
        /*
         * Karar biçimi bilen yerde: kimi panel alan=değer ister, kimi JSON
         * (bkz. PortalLogin). Model yalnızca "kurulabiliyor mu" diye sorar.
         */
        return Attribute::get(fn (): bool => PortalLogin::hazirMi($this));
    }

    /**
     * Bu kaydın arayüzü panelin kökeninden vekillenebilir mi? Hangi tür?
     *
     * Bu arayüzlerin oturumu DIŞARIDAN açılamıyor (ESXi CSRF jetonunu
     * istemcide üretiyor, VAMI jetonu localStorage'da tutuyor); onun yerine
     * arayüzün kendisi panelden servis ediliyor. Gereken tek şey adres ve
     * kayıtlı kimlik — hangi kaydın hangi türe düştüğü InterfaceType'nde.
     *
     * @return Attribute<?InterfaceType, never>
     */
    protected function arayuzTuru(): Attribute
    {
        return Attribute::get(function (): ?InterfaceType {
            if (! $this->has_password || (blank($this->host) && blank($this->url))) {
                return null;
            }

            $tur = InterfaceType::icin($this);

            /*
             * KULLANICI ADI ŞARTI TÜRE GÖRE: neredeyse hepsi ister, Rspamd
             * istemez — hesap kavramı yok, tek bir parola var ve cihaz
             * kullanıcı adını hiç görmüyor. Şart genel tutulduğunda o kayıtta
             * arayüz sessizce açılmıyordu (bkz. InterfaceType::kullaniciAdiGerekir).
             */
            if ($tur !== null && $tur->kullaniciAdiGerekir() && blank($this->username)) {
                return null;
            }

            return $tur;
        });
    }

    /**
     * Bağlantı adresi — adres, port ve tür bir arada.
     *
     * @return Attribute<string, never>
     */
    protected function target(): Attribute
    {
        return Attribute::get(function (): string {
            if (filled($this->url)) {
                return $this->url;
            }

            if (blank($this->host)) {
                return '—';
            }

            return $this->port === null ? $this->host : "{$this->host}:{$this->port}";
        });
    }

    /**
     * Kullanıcının görebildiği kayıtlar.
     *
     * Daraltma GRUP üzerinden yapılır; kayıt seviyesinde ayrı bir izin listesi
     * yoktur — iki ayrı izin kaynağı olsaydı biri güncellenip diğeri
     * unutulurdu. Kararı çözümleyici verir (izin/yasak sırası orada).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        /*
         * İKİ AYRI EKSEN, tek sorgu.
         *
         *   SAHİPSİZ kayıt  → ekip sırrı; grup yetkisi karar verir.
         *   SAHİPLİ  kayıt  → eski kişisel alandan (alan kaldırıldı);
         *                     grup yetkisi açmaz, yalnız yönetici görür.
         *
         * Yönetici ayrı bir dal olarak eklenmiyor: resolver'ın kapsamı
         * yöneticide zaten her şeyi geçiriyor, sahipli kayıtlar da o daldan
         * geliyor (kasanın sahibi o ve kurtarma imkânı kalmalı).
         */
        $query->where(function (Builder $dis) use ($user): void {
            $dis->where(function (Builder $ekip) use ($user): void {
                /*
                 * SAHİPSİZ + KİŞİSEL GRUPTA = SAHİPSİZ KALMIŞ KİŞİSEL KAYIT.
                 *
                 * Sahibin hesabı silinince owner_id boşalıyor (nullOnDelete)
                 * ama kayıt kişisel taşıyıcı grupta duruyor. Yalnızca
                 * owner_id'ye bakılsaydı kayıt "ekip sırrı" sayılır, taşıyıcı
                 * grup herkeste görünür olduğu için de joker 'view' yetkili
                 * her rol onu (parolasıyla) görürdü. Burada ekip dalından
                 * dışlanır; yalnızca yönetici dalından görünür.
                 */
                $ekip->whereNull('owner_id')
                    ->whereHas('group', fn (Builder $inner) => $inner->where('is_personal', false))
                    ->where(fn (Builder $izin) => $izin
                        ->whereHas('group', fn (Builder $inner) => app(UserPermissionResolver::class)
                            ->scope($inner, $user, CredentialPolicy::RESOURCE, 'view'))
                        /* Kayıt bazlı görme izni (bkz. VERILEBILEN_AKSIYONLAR · view). */
                        ->orWhere(fn (Builder $kayit) => $kayit->rolunGormeIzniVar($user)));
            });

            if ($user->isAdmin()) {
                $dis->orWhereNotNull('owner_id')
                    ->orWhereHas('group', fn (Builder $kisisel) => $kisisel->where('is_personal', true));
            }
        });

        /*
         * KAYIT BAZINDA ROL KISITI — grup izninden SONRA uygulanır.
         *
         * Grubu görebilen bir rol, o grupta tek tek kayıtlardan dışlanmış
         * olabilir (bkz. credential_role_restrictions göçürmesi). Süzme
         * VERİTABANINDA yapılır: "hepsini getir, ekranda gizle" güvenlik
         * değildir — adresi bilen kayda yine ulaşırdı.
         *
         * Politika tarafı da aynı kısıtı ayrıca uygular (CredentialPolicy);
         * iki uygulama noktası var çünkü kayda iki yoldan varılıyor: listeden
         * ve doğrudan adresten.
         */
        $query->whereNotRestrictedFor($user);
    }

    /**
     * Kullanıcının ROLÜNE bu kayıtta tek tek görme izni verilmiş mi?
     *
     * Yalnız ekip kaydında: kişisel kayıt sahibinindir, izin onu açmaz.
     * Rol kısıtı ayrıca uygulanır (scopeVisibleTo sonunda), kısıt izni yener.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeRolunGormeIzniVar(Builder $query, User $user): void
    {
        if ($user->role_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereNull('owner_id')
            ->whereHas('group', fn (Builder $grup) => $grup->where('is_personal', false))
            ->whereHas('grantedRoles', fn (Builder $r) => $r
                ->whereKey($user->role_id)
                ->where('credential_role_grants.action', 'view'));
    }

    /**
     * OTOMASYONUN DOKUNABİLECEĞİ kayıtlar.
     *
     * Kişisel kayıtlar hariç. Gece koşan kasa denetimi, toplu döndürme ve
     * toplu çıktı bu kapsamı kullanır: panel kimsenin kendi parolasını
     * kendiliğinden değiştirmemeli, kimsenin kendi kaydı bir yöneticinin
     * aldığı Excel çıktısına düşmemeli.
     *
     * Politika tarafında da ayrıca kapalı (bkz. CredentialPolicy); iki
     * uygulama noktası var çünkü kayda iki yoldan varılıyor.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOtomasyon(Builder $query): void
    {
        /* Sahibi silinmiş kişisel kayıt da otomasyondan uzak durur (bkz. kisiselMi). */
        $query->whereNull('owner_id')
            ->whereHas('group', fn (Builder $grup) => $grup->where('is_personal', false));
    }

    /**
     * Kişisel kayıt mı?
     *
     * İKİ İŞARETTEN BİRİ yeter: sahibi var ya da kişisel taşıyıcı grupta.
     * Yalnızca owner_id'ye bakmak sahibi silinen kaydı "ekip kaydı"na
     * çeviriyordu (owner_id ON DELETE SET NULL); sahipsiz kişisel kayıt
     * artık kimsenin değil, yalnızca yöneticinin (Gate::before) olur.
     * Silinmiş hesabın kişisel sırrı başkasının eline geçmez; silme de
     * veriyi yok etmez.
     */
    public function kisiselMi(): bool
    {
        return $this->owner_id !== null || $this->tasiyiciGrupta();
    }

    /** Kayıt kişisel taşıyıcı grupta mı? Örnek başına bir kez sorulur. */
    private function tasiyiciGrupta(): bool
    {
        /*
         * İlişki yüklüyse ondan, değilse tek sorguyla: tembel yükleme bu
         * projede kapalı (N+1 koruması) ve politika satır başına çağrılıyor.
         */
        if ($this->relationLoaded('group')) {
            return $this->group?->is_personal === true;
        }

        return $this->tasiyiciGrupSonucu[$this->credential_group_id]
            ??= CredentialGroup::query()->whereKey($this->credential_group_id)->value('is_personal') === true;
    }

    /** @var array<int, bool> */
    private array $tasiyiciGrupSonucu = [];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Kullanıcının rolüne KAPATILMIŞ kayıtları eler.
     *
     * Yönetici elenmez: bütün kontrolleri zaten geçiyor.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWhereNotRestrictedFor(Builder $query, User $user): void
    {
        if ($user->isAdmin() || $user->role_id === null) {
            return;
        }

        $query->whereDoesntHave(
            'restrictedRoles',
            fn (Builder $inner) => $inner->whereKey($user->role_id),
        );
    }

    /**
     * Bu kaydı GÖREMEYECEK roller.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function restrictedRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'credential_role_restrictions')->withTimestamps();
    }

    /** Kayıt bu kullanıcının rolüne kapalı mı? */
    public function isRestrictedFor(User $user): bool
    {
        if ($user->isAdmin() || $user->role_id === null) {
            return false;
        }

        return $this->restrictedRoles()->whereKey($user->role_id)->exists();
    }

    /**
     * KAYIT BAZINDA verilebilecek yetenekler.
     *
     * İkisi de, kaydı ZATEN GÖREBİLEN bir rolün o kayıtta yapabileceğini
     * genişletir. 'view' buradan verilemez: liste süzmesi (scopeVisibleTo)
     * grup yetkisine bakıyor ve kayıt listede yine çıkmazdı — yarım çalışan
     * bir izin, hiç çalışmayandan daha kötüdür.
     *
     *   field.password  Parolayı (ve kullanıcı adını) açar. Rolün o grupta
     *                   'session' / 'rotate' / 'export' yetkisi varsa o
     *                   kayıtta onlar da çalışır hâle gelir; üçü de parolayı
     *                   görmeyi şart koşuyor. Amaçlanan da budur.
     *
     *   connect         Konsol (SSH / uzak masaüstü) açar. Parolayı GÖRMEYİ
     *                   gerektirmez ve vermez: konsolun amacı zaten parolayı
     *                   göstermeden bağlamak. Örnek: bir rol gruptaki
     *                   belirli makinelere bağlanabilsin ama aynı gruptaki
     *                   diğer makinelere bağlanamasın.
     *
     *   view            Kaydı görür: adres, port, bağlantı adresi ve not
     *                   (bkz. GORME_KAPSAMI). Parolayı VERMEZ. Grubu
     *                   göremeyen role tek bir kaydı açar; grup kenar
     *                   çubuğunda yalnız o kayıtla görünür. Örnek: bir kayıt
     *                   rolün göremediği bir gruba taşındığında rol o kaydı
     *                   görmeye devam etsin, grubun geri kalanı kapalı
     *                   kalsın.
     *
     *   session         Portal oturumu açar: parolayı göstermeden web
     *                   arayüzüne girer. Kaydı görmeyi şart koşar.
     *
     * @var list<string>
     */
    public const VERILEBILEN_AKSIYONLAR = ['field.password', 'connect', 'view', 'session'];

    /**
     * Kayıt bazlı 'view' izninin açtığı yetenekler: kaydın kendisi ve
     * parola dışındaki alanlar. Gruptaki 'view'ın alan izinleriyle
     * eşleştiği yaygın şablonun aynısı (bkz. config/permissions.php).
     *
     * @var list<string>
     */
    public const GORME_KAPSAMI = ['view', 'field.host', 'field.port', 'field.url', 'field.notes'];

    /**
     * Bu kayıtta tek tek yetki verilmiş roller.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function grantedRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'credential_role_grants')
            ->withPivot('action')
            ->withTimestamps();
    }

    /**
     * Bu kullanıcının ROLÜNE bu kayıtta tek tek verilmiş yetenekler.
     *
     * Yönetici için boş döner: zaten bütün kontrolleri geçiyor, satır
     * aramak boşuna sorgu olurdu.
     *
     * @return list<string>
     */
    public function grantedActionsFor(User $user): array
    {
        if ($user->isAdmin() || $user->role_id === null) {
            return [];
        }

        $verilen = $this->grantedRoles()
            ->whereKey($user->role_id)
            ->pluck('credential_role_grants.action')
            ->all();

        return in_array('view', $verilen, true)
            ? array_values(array_unique([...$verilen, ...self::GORME_KAPSAMI]))
            : $verilen;
    }

    /**
     * Bu KAYITTA kullanıcının görebildiği alanlar.
     *
     * Grup yetkisi taban, kayıt bazlı ek izin onun ÜSTÜNE yazar. Tek yerde
     * durması şart: liste, detay, düzenleme formu ve API yanıtı aynı soruyu
     * soruyor ve dördü ayrı hesaplasaydı biri gün gelir ötekilerden farklı
     * bir alanı açardı — maskeleme tam olarak böyle sessizce delinir.
     *
     * @return array<string, bool>
     */
    public function visibleFieldsFor(User $user): array
    {
        $fields = app(UserPermissionResolver::class)->visibleFields(
            $user, CredentialPolicy::RESOURCE, $this->credential_group_id,
        );

        $verilen = $this->grantedActionsFor($user);

        if ($verilen === []) {
            return $fields;
        }

        foreach ((array) config('permissions.fields', []) as $alan => $aksiyon) {
            if (in_array($aksiyon, $verilen, true)) {
                $fields[$alan] = true;
            }
        }

        return $fields;
    }

    /**
     * Sırrın GİDECEĞİ YERİ belirleyen alanlar.
     *
     * Kayıttaki parola (konsol, cihaz arayüzü, portal oturumu) ve döndürme
     * kimliği bu alanların gösterdiği yere sunucu tarafından gönderilir.
     * Parolayı göremeyen biri adresi değiştirebilseydi, sırrı hiç görmeden
     * kendi dinleyicisine gönderirdi.
     *
     * @var list<string>
     */
    public const HEDEF_ALANLARI = [
        'host', 'port', 'url', 'login_url', 'login_username_field', 'login_password_field',
    ];

    /** Kayıtta, bir hedefe gönderilebilecek sır (parola ya da döndürme kimliği) var mı? */
    public function sirBagliMi(): bool
    {
        return $this->has_password
            || filled($this->getAttributeValue('rotation_password'))
            || $this->rotation_credential_id !== null;
    }

    /**
     * Bu kullanıcının DEĞİŞTİREMEYECEĞİ hedef alanları.
     *
     * Kayıtta sır varsa hedef, parola yetkisi olana aittir (field.password):
     * onu zaten okuyabilen biri hedefi değiştirmekle yeni bir şey kazanmaz.
     * Sır yoksa kısıt yok — gönderilecek bir şey de yok. Form (salt okunur
     * gösterim) ve istek (alanı düşürme) AYNI listeyi kullanır.
     *
     * @return list<string>
     */
    public function kilitliHedefAlanlari(User $user): array
    {
        if (! $this->sirBagliMi() || ($this->visibleFieldsFor($user)['password'] ?? false)) {
            return [];
        }

        return self::HEDEF_ALANLARI;
    }

    /**
     * Platforma göre süzme.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfPlatform(Builder $query, ?string $os): void
    {
        $query->when(
            $os !== null && OperatingSystem::tryFrom($os) !== null,
            fn (Builder $q) => $q->where('operating_system', $os)
        );
    }

    /** @param  Builder<$this>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /**
     * Arama — YALNIZCA ARAYANIN GÖREBİLDİĞİ ALANLARDA.
     *
     * Listede adres, bağlantı ve kullanıcı adı yetkisiz kullanıcıya "•••"
     * gösterilir; ama arama bu alanlarda yetkiye bakmadan eşleşiyordu ve
     * "kayıt çıktı / çıkmadı" yanıtı maskelenmiş değeri parça parça
     * sorgulatıyordu. Ad ve grup adı her zaman aranır (zaten görünür); korumalı
     * alanlar yalnızca o alanı görebildiği kayıtlarda aranır. Görme kararı
     * visibleFieldsFor ile AYNI kaynaklardan gelir: grup yetkisi (kişi/rol),
     * kayıt bazlı ek parola izni, kişisel kayıtta sahiplik.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, ?string $term, User $user): void
    {
        $query->when($term, function (Builder $q, string $value) use ($user): void {
            $q->where(function (Builder $inner) use ($value, $user): void {
                $inner->where('name', 'like', "%{$value}%")
                    ->orWhere(fn (Builder $w) => $this->korumaliAlandaAra($w, 'host', 'field.host', $value, $user))
                    ->orWhere(fn (Builder $w) => $this->korumaliAlandaAra($w, 'uplink', 'field.host', $value, $user))
                    ->orWhere(fn (Builder $w) => $this->korumaliAlandaAra($w, 'url', 'field.url', $value, $user))
                    ->orWhere(fn (Builder $w) => $this->korumaliAlandaAra($w, 'username', 'field.password', $value, $user))
                    ->orWhereHas('group', fn (Builder $group) => $group->where('name', 'like', "%{$value}%"))
                    ->orWhereHas('altGrup', fn (Builder $alt) => $alt->where('name', 'like', "%{$value}%"));
            });
        });
    }

    /**
     * @param  Builder<Credential>  $w
     */
    private function korumaliAlandaAra(Builder $w, string $kolon, string $aksiyon, string $deger, User $user): void
    {
        $w->where($kolon, 'like', "%{$deger}%");

        if ($user->isAdmin()) {
            return;
        }

        $resolver = app(UserPermissionResolver::class);

        $w->where(function (Builder $izin) use ($user, $aksiyon, $resolver): void {
            $izin->where(function (Builder $ekip) use ($user, $aksiyon, $resolver): void {
                $ekip->whereNull('owner_id')->where(function (Builder $kosul) use ($user, $aksiyon, $resolver): void {
                    /* Alan izni 'view'ı da şart koşar (config requires_view). */
                    $kosul->whereHas('group', fn (Builder $g) => $resolver->scope(
                        $resolver->scope($g, $user, CredentialPolicy::RESOURCE, 'view'),
                        $user, CredentialPolicy::RESOURCE, $aksiyon,
                    ));

                    /* Kayıt bazlı ek parola izni (bkz. visibleFieldsFor). */
                    if ($aksiyon === 'field.password' && $user->role_id !== null) {
                        $kosul->orWhereHas('grantedRoles', fn (Builder $r) => $r
                            ->whereKey($user->role_id)
                            ->where('credential_role_grants.action', 'field.password'));
                    }

                    /* Kayıt bazlı görme izni parola dışı alanları açar (bkz. GORME_KAPSAMI). */
                    if (in_array($aksiyon, self::GORME_KAPSAMI, true)) {
                        $kosul->orWhere(fn (Builder $kayit) => $kayit->rolunGormeIzniVar($user));
                    }
                });
            });
        });
    }
}
