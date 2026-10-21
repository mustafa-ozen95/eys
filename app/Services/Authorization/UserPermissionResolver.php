<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\PermissionEffect;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Yetki kararlarının tek merkezi.
 *
 * İKİ KATMAN VAR: rol ve kişi.
 *
 *   ROL   canlı okunur (bkz. RolePermissions). Rol ne diyorsa o geçerlidir;
 *         kullanıcıya kopyalanmaz; kopya eskir: rolü düzenleyen yeni
 *         yetkiyi görür, kullanıcılar eski yetkiyle çalışırdı.
 *   KİŞİ  yalnızca İSTİSNA tutar: rolün vermediğini ekler (izin), verdiğini
 *         keser (yasak). Satırı olmayan yerde rol geçerlidir.
 *
 * Karar sırası (ilk eşleşen kazanır):
 *   1. Kişinin KAYDA ÖZEL satırı        -> izin / yasak
 *   2. Kişinin GENEL satırı             -> izin / yasak
 *   3. Rolün kayda özel yetkisi         -> izin ver
 *   4. Rolün genel yetkisi              -> izin ver
 *   5. Eşleşme yok                      -> reddet (varsayılan kapalı)
 *
 * KİŞİ ROLDEN ÖNCE GELİR ve bu bilinçli: "bu kişide olmasın" demenin yolu
 * yoksa istisna diye bir şey yoktur. Bu sıra sayesinde "hepsinde parolayı
 * görsün, srv-a hariç" tek satırla yazılabilir ve yeni açılan bir grup
 * kendiliğinden kurala uyar.
 */
class UserPermissionResolver
{
    /**
     * Karar tabloları: "kullanıcı|kaynak|aksiyon" => (kayıt id => etki).
     *
     * @var array<string, array<int, PermissionEffect>>
     */
    private array $index = [];

    public function __construct(
        private PermissionRegistry $registry,
        private RolePermissions $roles,
    ) {}

    public function allows(User $user, string $resource, string $action, ?int $recordId = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isActive()) {
            return false;
        }

        /*
         * Kaydı göremeyen birine alanını göstermenin anlamı yok: alan izni
         * 'view' iznini KAPSAMAZ, ona EKLENİR.
         */
        if (config('permissions.requires_view') && str_starts_with($action, 'field.')
            && ! $this->decide($user, $resource, 'view', $recordId)) {
            return false;
        }

        return $this->decide($user, $resource, $action, $recordId);
    }

    /** Kullanıcının kaynağa hiç erişimi var mı? Menü ve liste ekranları bunu sorar. */
    public function allowsAny(User $user, string $resource, string $action): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isActive()) {
            return false;
        }

        /*
         * Genel yasak, rolün genel iznini keser ama kayda özel izinleri
         * kesmez: "hiçbir yerde, srv-a hariç" hâlâ bir erişimdir.
         */
        if ($this->genelEtki($user, $resource, $action) === PermissionEffect::Allow) {
            return true;
        }

        return $this->izinliKayitlar($user, $resource, $action) !== [];
    }

    /**
     * Sorguyu kullanıcının görebildiği kayıtlarla sınırlar.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, User $user, string $resource, string $action = 'view'): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if (! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        /*
         * SÜZME VERİTABANINDA: "hepsini getir sonra ekranda gizle" güvenlik
         * değildir. İki katman burada da aynı sırayla uygulanır.
         */
        if ($this->genelEtki($user, $resource, $action) === PermissionEffect::Allow) {
            return $query->whereNotIn(
                $query->qualifyColumn('id'),
                $this->idsWithEffect($this->effectMap($user, $resource, $action), PermissionEffect::Deny),
            );
        }

        return $query->whereIn(
            $query->qualifyColumn('id'),
            $this->izinliKayitlar($user, $resource, $action),
        );
    }

    /**
     * "Bütün kayıtlar" düzeyindeki etki: önce kişinin satırı, sonra rol.
     */
    private function genelEtki(User $user, string $resource, string $action): ?PermissionEffect
    {
        $kisi = $this->effectMap($user, $resource, $action);

        if (isset($kisi[UserPermission::ALL_RECORDS])) {
            return $kisi[UserPermission::ALL_RECORDS];
        }

        return isset($this->roles->map($user->role, $resource, $action)[UserPermission::ALL_RECORDS])
            ? PermissionEffect::Allow
            : null;
    }

    /**
     * Kayda özel olarak İZİNLİ olan kayıt kimlikleri.
     *
     * Rolün verdiği kayıtlar da buradadır; kişinin yasağı onları çıkarır.
     *
     * @return array<int, int>
     */
    private function izinliKayitlar(User $user, string $resource, string $action): array
    {
        $kisi = $this->effectMap($user, $resource, $action);
        $rol = $this->roles->map($user->role, $resource, $action);

        $izinli = array_flip($this->idsWithEffect($kisi, PermissionEffect::Allow));

        /*
         * KİŞİNİN GENEL SATIRI rolün KAYDA ÖZEL iznini de keser — karar
         * sırası böyle (bkz. decide: kişinin genel satırı rolden önce gelir).
         * Yalnızca kayda özel kişi satırını saymak yetmez: "kişiye genel
         * yasak + rolde grup bazlı izin" bileşiminde liste, politikanın
         * reddettiği kayıtları göstermemeli. Kişinin KAYDA ÖZEL izni
         * (yukarıda) yine geçer: o her zaman önce gelir.
         */
        if (! isset($kisi[UserPermission::ALL_RECORDS])) {
            foreach (array_keys($rol) as $id) {
                if ($id !== UserPermission::ALL_RECORDS && ($kisi[$id] ?? null) !== PermissionEffect::Deny) {
                    $izinli[$id] = true;
                }
            }
        }

        return array_keys($izinli);
    }

    /**
     * Kullanıcının BİR KAYITTA görebildiği alanlar.
     *
     * Ekranlar bunu bir kez sorar ve hazır listeyi kullanır; her alan için
     * ayrı yetki sorgusu atılmaz.
     *
     * @return array<string, bool> alan adı => görünür mü
     */
    public function visibleFields(User $user, string $resource, int $recordId): array
    {
        return collect($this->registry->fields())
            ->map(fn (string $action): bool => $this->allows($user, $resource, $action, $recordId))
            ->all();
    }

    /**
     * Yetki matrisi ekranını besleyen veri.
     *
     * @return array<string, array<string, array{wildcard: ?string, allowed: array<int, int>, denied: array<int, int>}>>
     */
    public function matrixFor(User $user): array
    {
        $matrix = [];

        foreach ($user->permissions as $permission) {
            $bucket = &$matrix[$permission->resource][$permission->action];

            $bucket ??= ['wildcard' => null, 'allowed' => [], 'denied' => []];

            if ($permission->resource_id === UserPermission::ALL_RECORDS) {
                $bucket['wildcard'] = $permission->effect->value;

                continue;
            }

            $key = $permission->effect === PermissionEffect::Allow ? 'allowed' : 'denied';
            $bucket[$key][] = $permission->resource_id;
        }

        return $matrix;
    }

    /**
     * Bir kaynağın izinlerini tek işlemde yeniden yazar.
     *
     * Satırlar İSTİSNADIR: rolün verdiğini burada yazmaya gerek yok, rol
     * canlı okunuyor (bkz. RolePermissions).
     *
     * @param  array<int, string>  $wildcardActions  bütün kayıtlarda İZİN
     * @param  array<string, array<int, int>>  $allowedRecords  aksiyon => kayıt id'leri
     * @param  array<string, array<int, int>>  $deniedRecords  aksiyon => kayıt id'leri
     * @param  array<int, string>  $wildcardDenied  bütün kayıtlarda YASAK
     */
    public function syncResource(
        User $user,
        string $resource,
        array $wildcardActions,
        array $allowedRecords = [],
        array $deniedRecords = [],
        array $wildcardDenied = [],
    ): void {
        DB::transaction(function () use ($user, $resource, $wildcardActions, $allowedRecords, $deniedRecords, $wildcardDenied): void {
            $user->permissions()->where('resource', $resource)->delete();

            $rows = [];

            foreach ($wildcardActions as $action) {
                $rows[] = $this->row($resource, UserPermission::ALL_RECORDS, $action, PermissionEffect::Allow);
            }

            /* Genel YASAK: rolün "bütün gruplarda" verdiğini bu kişide keser. */
            foreach ($wildcardDenied as $action) {
                $rows[] = $this->row($resource, UserPermission::ALL_RECORDS, $action, PermissionEffect::Deny);
            }

            foreach ($allowedRecords as $action => $ids) {
                foreach (array_unique($ids) as $id) {
                    $rows[] = $this->row($resource, (int) $id, $action, PermissionEffect::Allow);
                }
            }

            foreach ($deniedRecords as $action => $ids) {
                foreach (array_unique($ids) as $id) {
                    $rows[] = $this->row($resource, (int) $id, $action, PermissionEffect::Deny);
                }
            }

            if ($rows !== []) {
                $user->permissions()->createMany($rows);
            }
        });

        $this->flush($user);
    }

    /**
     * Yetki satırları hem model örneğinde hem karar tablosunda önbelleklenir;
     * bu metot İKİSİNİ DE düşürür. Yalnızca biri düşseydi izin değişikliği
     * aynı istek içinde görünmezdi.
     */
    public function flush(User $user): void
    {
        $user->unsetRelation('permissions');
        $this->roles->flush();

        $prefix = $user->getKey().'|';

        foreach (array_keys($this->index) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->index[$key]);
            }
        }
    }

    /**
     * Kişinin bu aksiyon için kayda özel ya da genel (yasak/izin) satırı var mı?
     *
     * Kayıt bazlı rol izinleri (credential_role_grants) kararı resolver'ın
     * dışında verir; kişinin kendi kararına saygı göstermeleri için bunu
     * sorarlar.
     */
    public function hasPersonRow(User $user, string $resource, string $action, int $recordId): bool
    {
        $kisi = $this->effectMap($user, $resource, $action);

        return isset($kisi[$recordId]) || isset($kisi[UserPermission::ALL_RECORDS]);
    }

    private function decide(User $user, string $resource, string $action, ?int $recordId): bool
    {
        $kisi = $this->effectMap($user, $resource, $action);
        $rol = $this->roles->map($user->role, $resource, $action);

        $kayit = $recordId !== null && $recordId !== UserPermission::ALL_RECORDS ? $recordId : null;

        /* 1-2: kişinin istisnası — önce kayda özel, sonra genel. */
        if ($kayit !== null && isset($kisi[$kayit])) {
            return $kisi[$kayit] === PermissionEffect::Allow;
        }

        if (isset($kisi[UserPermission::ALL_RECORDS])) {
            return $kisi[UserPermission::ALL_RECORDS] === PermissionEffect::Allow;
        }

        /* 3-4: rol. */
        return ($kayit !== null && isset($rol[$kayit]))
            || isset($rol[UserPermission::ALL_RECORDS]);
    }

    /** @return array<string, mixed> */
    private function row(string $resource, int $recordId, string $action, PermissionEffect $effect): array
    {
        return [
            'resource' => $resource,
            'resource_id' => $recordId,
            'action' => $action,
            'effect' => $effect,
        ];
    }

    /**
     * Bir kaynak/aksiyon çiftinin karar tablosu: kayıt id => etki.
     *
     * Satırlar model örneğinin ilişki önbelleğinden okunur (istek başına tek
     * sorgu), ama TARAMA da bir maliyettir: her soru koleksiyonu baştan
     * süzseydi, satır başına birçok soru soran liste ekranı kalabalık bir
     * sayfada yalnızca bu yüzden belirgin biçimde yavaşlardı.
     *
     * Tablo istek boyunca hazır durur; izinler değiştiğinde flush() düşürür.
     *
     * @return array<int, PermissionEffect>
     */
    private function effectMap(User $user, string $resource, string $action): array
    {
        $key = $user->getKey().'|'.$resource.'|'.$action;

        return $this->index[$key] ??= $user->permissions
            ->where('resource', $resource)
            ->where('action', $action)
            ->mapWithKeys(fn (UserPermission $permission): array => [
                $permission->resource_id => $permission->effect,
            ])
            ->all();
    }

    /**
     * @param  array<int, PermissionEffect>  $map
     * @return array<int, int>
     */
    private function idsWithEffect(array $map, PermissionEffect $effect): array
    {
        unset($map[UserPermission::ALL_RECORDS]);

        return array_keys(array_filter($map, fn (PermissionEffect $row): bool => $row === $effect));
    }
}
