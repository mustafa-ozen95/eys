<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\SubGroup;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Support\Host;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ekleme ve düzenleme AYNI kuralları kullanır.
 *
 * En pahalı hata sınıflarından biri: aynı alanı yazan iki ekrandan yalnızca
 * birinde doğrulama olması. Kural kopyalanmaz, paylaştırılır;
 * bu yüzden iki istek sınıfı da buradan türer.
 */
abstract class CredentialRequest extends FormRequest
{
    /**
     * Doğrulama hatasında oturuma FLAŞLANMAYACAK alanlar.
     *
     * Laravel'in listesi yalnızca "password" ile başlayan üç adı tanır;
     * döndürme parolası da bir sırdır ve eski girdi olarak hem oturumda
     * saklanır hem de formda geri basılırdı. Form artık listenin üstünde
     * açıldığı için o girdi bir sonraki sayfada da duruyor olurdu.
     *
     * @var array<int, string>
     */
    protected $dontFlash = ['current_password', 'password', 'password_confirmation', 'rotation_password'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * Grup, KULLANICININ YETKİLİ OLDUĞU gruplarla sınırlıdır. Policy
             * "kayıt ekleyebilir mi" sorusunu yanıtlar, "bu gruba ekleyebilir
             * mi" sorusunu değil — yetkisi olmayan bir grubun uuid'si forma
             * elle yazılabilir.
             */
            'group' => [
                'required',
                'uuid',
                /*
                 * Grup, kullanıcının O GRUPTA yazma yetkisi olanlarla
                 * sınırlıdır. Policy "kayıt ekleyebilir mi" sorusunu
                 * yanıtlar, "bu gruba ekleyebilir mi" sorusunu değil —
                 * yetkisi olmayan bir grubun uuid'si forma elle yazılabilir.
                 */
                Rule::in($this->yazilabilirGruplar()),
            ],

            /*
             * ALT GRUP SEÇİLEN GRUBUN İÇİNDEN OLMALI.
             *
             * Formdaki liste gruba göre daralıyor (conditional-field.js ·
             * data-linked) ama karar burada: başka grubun bölmesi elle
             * gönderilebilir, o zaman kayıt bir grupta durup öbür grubun
             * başlığı altında listelenirdi.
             */
            'alt_grup' => [
                'nullable',
                'uuid',
                Rule::exists('alt_gruplar', 'uuid')->where(
                    'credential_group_id',
                    CredentialGroup::query()->where('uuid', $this->string('group'))->value('id') ?? 0,
                ),
            ],
            'name' => ['required', 'string', 'max:120'],
            'access_type' => ['required', Rule::enum(AccessType::class)],
            'operating_system' => ['required', Rule::enum(OperatingSystem::class)],
            'platform' => ['nullable', 'string', 'max:60'],
            /*
             * KAYIT BAZINDA ROL KISITI.
             *
             * Yalnızca YÖNETİCİ yazabilir; payload() bunu ayrıca denetler.
             * Doğrulama burada yalnızca biçimi sorar.
             */
            'restricted_roles' => ['sometimes', 'array'],
            'restricted_roles.*' => ['string', 'uuid'],

            /*
             * KAYIT BAZINDA EK PAROLA İZNİ — kısıtın aynası.
             *
             * Yine yalnızca YÖNETİCİ yazabilir; grantedRoleIds() bunu ayrıca
             * denetler. Doğrulama burada yalnızca biçimi sorar.
             */
            'granted_roles' => ['sometimes', 'array'],
            'granted_roles.*' => ['string', 'uuid'],
            'konsol_roles' => ['sometimes', 'array'],
            'konsol_roles.*' => ['string', 'uuid'],
            'gorme_roles' => ['sometimes', 'array'],

            /*
             * Rol başına TEK SEÇİM: gruba göre / gizli / görür. Kısıt ile
             * görme izni iki ayrı kutu listesiyken ikisi birden işaretlenebiliyordu
             * ve çelişkili bir seçim kaydedilebiliyordu. Anahtar rolün uuid'i.
             */
            'rol_gorunum' => ['sometimes', 'array'],
            'rol_gorunum.*' => ['string', 'in:grup,gizli,gorur'],
            'gorme_roles.*' => ['string', 'uuid'],
            'portal_roles' => ['sometimes', 'array'],
            'portal_roles.*' => ['string', 'uuid'],

            /*
             * Rol kutuları formda VAR mı? Tarayıcı işaretsiz kutuyu hiç
             * göndermez: hepsinin tiki kaldırılınca alan istekte yoktu ve
             * "dokunma" sayılıp eski tikler kalıyordu.
             * Form bu işareti yollar; işaret varken gelmeyen alan BOŞ listedir.
             */
            'rol_kutulari' => ['sometimes', 'boolean'],

            /*
             * Adres gerçek bir ağ adresi olmalı (bkz. App\Support\Host):
             * değer root'la yeniden yüklenen nginx yapılandırmasına metin
             * olarak giriyor. DEĞİŞMEMİŞ DEĞER SORULMAZ — bu doğrulamadan
             * önce yazılmış bir kaydın başka bir alanını düzenlemek bu
             * yüzden engellenmemeli; o kayıt üretimde zaten atlanır.
             */
            'host' => ['nullable', 'string', 'max:190', $this->adresKurali('host')],
            /* Uplink: switch'in bağlı olduğu üst cihazın adresi; yalnızca bilgi, sır gönderilmez. */
            'uplink' => ['nullable', 'string', 'max:190', $this->adresKurali('uplink')],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],

            /*
             * RDP güvenlik kipi. Boş = config'teki varsayılan.
             * Liste Guacamole'ün kabul ettiği değerlerle sınırlı; serbest
             * metin yazılırsa oturum sessizce kurulamazdı.
             */
            'rdp_security' => ['nullable', Rule::in(['any', 'nla', 'tls', 'rdp'])],
            'url' => ['nullable', 'string', 'url', 'max:500', $this->adresKurali('url')],
            'login_url' => ['nullable', 'string', 'url', 'max:500'],
            'login_username_field' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-\[\]]+$/'],
            'login_password_field' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-\[\]]+$/'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],

            /*
             * KRİTİK İŞARETİ: işlemlerde gerekçe sorulsun mu?
             *
             * Kaydı DÜZENLEYEBİLEN herkes koyup kaldırabilir; rol kısıtının
             * aksine yöneticiye bağlanmadı. İşaret erişim vermiyor, yalnızca
             * "neden" sorusunu açıp kapıyor; kaydı zaten düzenleyebilen biri
             * parolasını da adresini de değiştirebiliyor. Değişiklik denetim
             * kaydına yazılır (bkz. CredentialWriter).
             */
            'is_critical' => ['nullable', 'boolean'],
            'rotation_enabled' => ['nullable', 'boolean'],
            'rotation_driver' => ['nullable', 'string', Rule::in(array_keys(config('rotation.drivers', [])))],
            /*
             * Seçilen kayıt GERÇEKTEN GÖRÜLEBİLİR OLMALI: kullanıcı
             * göremediği bir kaydın kimliğini seçebilseydi, adını ve
             * adresini oradan öğrenirdi.
             */
            'rotation_credential_id' => ['nullable', 'integer', Rule::exists('credentials', 'id'), $this->bagliHesapKurali()],
            'rotation_username' => ['nullable', 'string', 'max:190'],
            'rotation_password' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Kullanıcının kayıt yazabildiği grupların uuid'leri.
     *
     * Ekleme 'create', düzenleme 'update' ister: bir gruba ekleyebilmek onu
     * değiştirebilmek anlamına gelmez.
     *
     * @return array<int, string>
     */
    private function yazilabilirGruplar(): array
    {
        $resolver = app(UserPermissionResolver::class);
        $duzenlenen = $this->route('credential');
        $action = $duzenlenen === null ? 'create' : 'update';

        return CredentialGroup::query()
            ->get(['id', 'uuid'])
            ->filter(fn (CredentialGroup $group): bool => $resolver->allows(
                $this->user(), CredentialPolicy::RESOURCE, $action, $group->getKey(),
            ))
            ->pluck('uuid')
            ->all();
    }

    /** @see Konak */
    private function adresKurali(string $alan): \Closure
    {
        return function (string $ad, mixed $deger, \Closure $basarisiz) use ($alan): void {
            $mevcut = $this->route('credential');
            $deger = trim((string) $deger);

            if ($deger === '' || ($mevcut instanceof Credential && trim((string) $mevcut->{$alan}) === $deger)) {
                return;
            }

            if (in_array($alan, ['host', 'uplink'], true) ? ! Host::gecerliMi($deger) : ! Host::adresGecerliMi($deger)) {
                $basarisiz('Geçerli bir alan adı ya da IP adresi yazın.');
            }
        };
    }

    /**
     * Güncelleme hesabı olarak seçilen kayıt, SEÇEN KİŞİNİN kullanabileceği
     * bir kayıt olmalı.
     *
     * Yalnızca varlığına bakılırdı: kullanıcı göremediği (başka grubun ya da
     * başkasının kişisel) bir kaydın numarasını yazıyor, tur o kaydın
     * kullanıcı adı ve parolasıyla, kullanıcının seçtiği adrese bağlanıyordu.
     * Şart: kayıt otomasyona açık (kişisel değil), kullanıcıya görünür ve
     * parolası kullanıcıya açık.
     *
     * DEĞİŞMEMİŞ DEĞER SORULMAZ: kaydı yönetici bağlamış olabilir; başka bir
     * alanı düzenleyen kişi bağı koruyor, yeniden doğrulaması gerekmez
     * (bkz. gorulmeyeniKoru: göremediği bağı da silemez).
     */
    private function bagliHesapKurali(): \Closure
    {
        return function (string $alan, mixed $deger, \Closure $basarisiz): void {
            $mevcut = $this->route('credential');

            if ($mevcut instanceof Credential && (int) $mevcut->rotation_credential_id === (int) $deger) {
                return;
            }

            $hedef = Credential::query()->visibleTo($this->user())->otomasyon()->whereKey($deger)->first();

            if ($hedef === null || ! $this->user()->can('reveal', $hedef)) {
                $basarisiz('Seçilen güncelleme hesabı kullanılamaz.');
            }
        };
    }

    /**
     * Sırra bağlı hedef değişiyorsa kimliğin yeniden girilmesi şart.
     *
     * HEDEF kilidi (Credential::kilitliHedefAlanlari) parolayı göremeyeni
     * durdurur. Burası parolayı GÖREBİLEN ama döndürme kimliğini
     * okuyamayan düzenleyiciyi durdurur: döndürme parolası yazma-yalnızdır;
     * adresi (ya da hesap adını) değiştirince panel o gizli kimliği yeni
     * adrese götürürdü ya da kimliğin sıfırlayabildiği başka bir hesabı
     * (root) hedeflerdi. Kimlik adres/hesap değişiminde YENİDEN girilmeli.
     *
     * Yönetici kimliği zaten kendisi tanımladığı için muaftır.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $dogrulayici): void {
            $kayit = $this->route('credential');

            if (! $kayit instanceof Credential || $this->user()?->isAdmin() || $dogrulayici->errors()->isNotEmpty()) {
                return;
            }

            $degisenler = array_values(array_filter(
                ['host', 'port', 'url', 'username'],
                fn (string $alan): bool => $this->hedefDegisti($kayit, $alan),
            ));

            if ($degisenler === []) {
                return;
            }

            $tutulanKimlik = filled($kayit->getAttributeValue('rotation_password')) && ! $this->filled('rotation_password');

            /* Başka bir kayıt seçilmediyse ve eskisi kullanılamıyorsa bağ OLDUĞU GİBİ KALIR. */
            $bagli = $kayit->rotationCredential;
            $tutulanBag = $bagli !== null
                && ! $this->user()->can('reveal', $bagli)
                && (! $this->filled('rotation_credential_id')
                    || (int) $this->input('rotation_credential_id') === (int) $bagli->getKey());

            if ($tutulanKimlik || $tutulanBag) {
                $dogrulayici->errors()->add($degisenler[0], 'Bu kayıtta gizli bir güncelleme hesabı var: adresi ya da kullanıcı adını '
                    .'değiştirmek için güncelleme parolasını da yeniden girin.');
            }
        }];
    }

    /** Alan, istekte kayıttaki değerden farklı mı? Alanı göremeyen için hiç değişmez. */
    private function hedefDegisti(Credential $kayit, string $alan): bool
    {
        /* Görmediği ya da kilitli alan zaten düşürülüyor (gorulmeyeniKoru). */
        if (! $this->has($alan)
            || ! ($kayit->visibleFieldsFor($this->user())[$alan] ?? true)
            || in_array($alan, $kayit->kilitliHedefAlanlari($this->user()), true)) {
            return false;
        }

        $gelen = $alan === 'port' ? (string) ($this->integer('port') ?: '') : trim((string) $this->input($alan));

        return $gelen !== trim((string) $kayit->{$alan});
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'group' => 'grup',
            'alt_grup' => 'alt grup',
            'name' => 'ad',
            'access_type' => 'erişim türü',
            'operating_system' => 'platform',
            'platform' => 'sürüm',
            'host' => 'adres',
            'uplink' => 'uplink',
            'port' => 'port',
            'rdp_security' => 'RDP güvenlik kipi',
            'url' => 'bağlantı adresi',
            'login_url' => 'giriş adresi',
            'login_username_field' => 'kullanıcı alanı adı',
            'login_password_field' => 'parola alanı adı',
            'username' => 'kullanıcı adı',
            'password' => 'parola',
            'rotation_credential_id' => 'güncelleme hesabı kaydı',
            'rotation_username' => 'güncelleme hesabı',
            'rotation_password' => 'güncelleme parolası',
            'notes' => 'not',
            'is_critical' => 'kritik işareti',
        ];
    }

    /**
     * Modele yazılacak hazır veri.
     *
     * Controller'da tek tek alan toplanmaz; $request->all() hiç kullanılmaz.
     *
     * @return array<string, mixed>
     */
    /**
     * Bu kaydı göremeyecek rollerin KİMLİKLERİ.
     *
     * YALNIZCA YÖNETİCİ. Kısıtı koyabilen kişi yetkiyi dağıtan kişidir;
     * kısıtlanmış bir rol kendi kısıtını kaldırabilseydi kısıt diye bir şey
     * olmazdı. Yönetici olmayan istekte alan hiç okunmaz — formda da
     * basılmıyor, ama forma güvenmek denetim değildir.
     *
     * null dönerse "dokunma" demektir: alanı hiç göndermeyen bir istek
     * (örneğin başka bir ekrandan gelen kısmi güncelleme) var olan kısıtı
     * silmemeli.
     *
     * @return array<int, int>|null
     */
    /**
     * Bu kayıtta PAROLAYI görebilecek ek rollerin kimlikleri.
     *
     * YALNIZCA YÖNETİCİ. Grubun vermediği bir parolayı tek bir kayıtta açmak,
     * yetki dağıtmakla aynı ağırlıkta bir iştir; kısıtta olduğu gibi burada
     * da karar yöneticinindir.
     *
     * null dönerse "dokunma" demektir.
     *
     * @return array<int, int>|null
     */
    public function grantedRoleIds(): ?array
    {
        return $this->rolKimlikleri('granted_roles');
    }

    /**
     * Bu kayıtta KONSOL açabilecek ek rollerin kimlikleri.
     *
     * Parola izninden ayrı: konsol parolayı göstermeden bağlıyor, o yüzden
     * "bağlansın ama parolayı görmesin" geçerli ve sık istenen bir ayar.
     *
     * @return array<int, int>|null
     */
    public function konsolRoleIds(): ?array
    {
        return $this->rolKimlikleri('konsol_roles');
    }

    /**
     * Bu kaydı GÖREBİLECEK ek roller: parola dışı alanlarla birlikte
     * (bkz. Credential::GORME_KAPSAMI). Parolayı açmaz.
     *
     * @return array<int, int>|null
     */
    public function gormeRoleIds(): ?array
    {
        return $this->rolKimlikleri('gorme_roles');
    }

    /**
     * Bu kayıtta PORTAL OTURUMU açabilecek ek roller: parolayı göstermeden
     * web arayüzüne girer.
     *
     * @return array<int, int>|null
     */
    public function portalRoleIds(): ?array
    {
        return $this->rolKimlikleri('portal_roles');
    }

    /**
     * Formdan gelen rol listesini kimliğe çevirir.
     *
     * YALNIZCA YÖNETİCİ. Grubun vermediğini tek bir kayıtta açmak, yetki
     * dağıtmakla aynı ağırlıkta bir iştir. null = "dokunma".
     *
     * @return array<int, int>|null
     */
    private function rolKimlikleri(string $alan): ?array
    {
        if ($alan === 'gorme_roles' && $this->has('rol_gorunum')) {
            return $this->gorunumKimlikleri('gorur');
        }

        if (! $this->user()?->isAdmin() || ! $this->rolAlaniGeldi($alan)) {
            return null;
        }

        $uuids = array_filter((array) $this->input($alan, []), 'is_string');

        /* Yönetici rollerine ek izin yazılmaz: zaten her şeyi görüyorlar. */
        return Role::query()
            ->whereIn('uuid', $uuids)
            ->where('is_admin', false)
            ->pluck('id')
            ->all();
    }

    /** Alan geldi ya da formun rol kutuları gönderildi (işaretsiz kutu = boş liste). */
    private function rolAlaniGeldi(string $alan): bool
    {
        return $this->has($alan) || $this->boolean('rol_kutulari');
    }

    /**
     * Rol başına seçimden ('rol_gorunum') istenen değeri taşıyan rollerin
     * kimlikleri. Yalnızca yönetici; yönetici rolleri hariç.
     *
     * @return array<int, int>|null
     */
    private function gorunumKimlikleri(string $deger): ?array
    {
        if (! $this->user()?->isAdmin()) {
            return null;
        }

        $uuids = array_keys(array_filter(
            (array) $this->input('rol_gorunum', []),
            fn ($secim, $uuid): bool => is_string($uuid) && $secim === $deger,
            ARRAY_FILTER_USE_BOTH,
        ));

        return Role::query()
            ->whereIn('uuid', $uuids)
            ->where('is_admin', false)
            ->pluck('id')
            ->all();
    }

    public function restrictedRoleIds(): ?array
    {
        if ($this->has('rol_gorunum')) {
            return $this->gorunumKimlikleri('gizli');
        }

        if (! $this->user()?->isAdmin() || ! $this->rolAlaniGeldi('restricted_roles')) {
            return null;
        }

        $uuids = array_filter((array) $this->input('restricted_roles', []), 'is_string');

        /* Yönetici rolleri kısıtlanamaz: yönetici bütün kontrolleri geçiyor,
           satır yazmak yanlış bir güven duygusu verirdi. */
        return Role::query()
            ->whereIn('uuid', $uuids)
            ->where('is_admin', false)
            ->pluck('id')
            ->all();
    }

    public function payload(): array
    {
        $group = CredentialGroup::query()->where('uuid', $this->string('group'))->firstOrFail();

        return $this->gorulmeyeniKoru([
            'credential_group_id' => $group->getKey(),

            /* Doğrulama alt grubun bu gruba ait olduğunu zaten sordu. */
            'alt_grup_id' => $this->filled('alt_grup')
                ? SubGroup::query()->where('uuid', $this->string('alt_grup'))->value('id')
                : null,

            'name' => $this->string('name')->squish()->value(),
            'access_type' => $this->enum('access_type', AccessType::class),
            'operating_system' => $this->enum('operating_system', OperatingSystem::class),
            'platform' => $this->string('platform')->squish()->value() ?: null,
            'host' => $this->string('host')->squish()->value(),
            'uplink' => $this->string('uplink')->squish()->value() ?: null,
            'port' => $this->integer('port') ?: null,
            'rdp_security' => $this->string('rdp_security')->value() ?: null,
            'url' => $this->string('url')->trim()->value(),
            'login_url' => $this->string('login_url')->trim()->value(),
            'login_username_field' => $this->string('login_username_field')->trim()->value(),
            'login_password_field' => $this->string('login_password_field')->trim()->value(),
            'username' => $this->string('username')->trim()->value(),
            'password' => $this->input('password'),
            'notes' => $this->input('notes'),
            'is_active' => $this->boolean('is_active'),
            'is_critical' => $this->boolean('is_critical'),
            'rotation_enabled' => $this->boolean('rotation_enabled'),
            'rotation_driver' => $this->string('rotation_driver')->value() ?: null,
            'rotation_credential_id' => $this->filled('rotation_credential_id')
                ? (int) $this->input('rotation_credential_id')
                : null,
            'rotation_username' => $this->string('rotation_username')->trim()->value(),
            'rotation_password' => $this->input('rotation_password'),
        ]);
    }

    /**
     * GÖREMEDİĞİN ALANI DEĞİŞTİREMEZSİN.
     *
     * Listede "•••" gösterilen alan düzenleme formunda da basılmaz; kutu
     * devre dışıdır ve forma katılmaz. Ama koruma orada BİTMEZ: devre dışı
     * kutu tarayıcıda açılabilir, istek elle de kurulabilir. Alan burada,
     * sunucuda düşürülür ve kayda hiç ulaşmaz.
     *
     * Parola için bu kural bir okuma kısıtı değil, bir ELE GEÇİRME kısıtıdır:
     * parolayı göremeyen biri kutuya kendi bildiği bir değeri yazabilseydi
     * görme yetkisinin hiçbir anlamı kalmazdı.
     *
     * Yetki, kaydın ŞU ANKİ grubuna göre sorulur: formdaki gruba bakılsaydı
     * yetkisi olan bir grup seçilerek kilit açılabilirdi.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function gorulmeyeniKoru(array $data): array
    {
        $credential = $this->route('credential');

        /* Yeni kayıtta korunacak eski değer yok; kullanıcı ne yazdığını görüyor. */
        if (! $credential instanceof Credential) {
            return $data;
        }

        $visible = $credential->visibleFieldsFor($this->user());

        foreach ($visible as $field => $allowed) {
            if (! $allowed) {
                unset($data[$field]);
            }
        }

        /*
         * SIRRIN GİDECEĞİ YER DE KORUNUR (adres, port, bağlantı, portal
         * giriş adresi ve alan adları).
         *
         * Alanı görmek onu değiştirmeye yetmez: kayıtta parola ya da döndürme
         * kimliği varsa sunucu onu bu alanların gösterdiği yere gönderir.
         * Parolayı göremeyen, adresi kendi dinleyicisine çevirip sırrı hiç
         * görmeden alabilirdi (konsol, cihaz arayüzü, portal oturumu).
         */
        foreach ($credential->kilitliHedefAlanlari($this->user()) as $field) {
            unset($data[$field]);
        }

        /*
         * GÖREMEDİĞİ BAĞI SİLEMEZ. Güncelleme hesabı olarak başka bir kayıt
         * seçilmişse ve düzenleyen onu kullanamıyorsa (listede çıkmaz, form
         * boş gelir) boş gönderim bağı koparmamalı.
         */
        $bagli = $credential->rotationCredential;

        if ($bagli !== null && ! $this->user()->can('reveal', $bagli)) {
            unset($data['rotation_credential_id']);
        }

        return $data;
    }
}
