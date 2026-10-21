<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\AltGrup;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Erişim kaydını yazan tek yer.
 *
 * Kaydı ekleyen, güncelleyen ve silen üç ekran var ve üçünde de aynı iki iş
 * yapılmalı: yazma ve denetim kaydı. Denetim kaydını unutan bir yol, kaydın
 * sessizce değişebildiği bir yoldur.
 *
 * Boş bırakılan parola alanı parolayı SİLMEZ; dokunmaz. Düzenleme ekranında
 * parola hiç basılmadığı için form her kaydedişte boş gelir — "boş gelen alan
 * boşaltır" kuralı burada parolaları tek tek siler.
 */
class CredentialWriter
{
    public function __construct(private ActivityRecorder $activity) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Credential
    {
        return DB::transaction(function () use ($data): Credential {
            $prepared = $this->prepare($data);
            $prepared['position'] ??= $this->sonSira($prepared['credential_group_id'] ?? null, $prepared['alt_grup_id'] ?? null);

            $credential = Credential::create($prepared);

            $this->activity->record(
                ActivityAction::Created,
                $this->summary($credential),
                $credential,
            );

            return $credential;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Credential $credential, array $data): Credential
    {
        return DB::transaction(function () use ($credential, $data): Credential {
            $prepared = $this->prepare($data);

            /*
             * Boş bırakılan parola alanları SİLMEZ, dokunmaz. Düzenleme
             * ekranında parolalar hiç basılmadığı için form her kaydedişte
             * boş gelir; "boş gelen alan boşaltır" kuralı burada parolaları
             * tek tek silerdi.
             */
            foreach (['password', 'rotation_password'] as $secret) {
                if (blank($prepared[$secret] ?? null)) {
                    unset($prepared[$secret]);
                }
            }

            $credential->fill($prepared);

            /* Başka bölüme taşınan kayıt oranın SONUNA gider; eski sırası orada anlamsız. */
            if ($credential->isDirty(['credential_group_id', 'alt_grup_id']) && ! array_key_exists('position', $prepared)) {
                $credential->position = $this->sonSira($credential->credential_group_id, $credential->alt_grup_id, $credential->getKey());
            }

            $credential->save();

            $this->activity->record(
                ActivityAction::Updated,
                $this->summary($credential->fresh() ?? $credential),
                $credential,
            );

            return $credential;
        });
    }

    /**
     * Gerekçe ARTIK ZORUNLU DEĞİL.
     *
     * Yalnızca "kritik" işaretli kayıtlarda soruluyor (bkz. GerekceKurali);
     * imza bunu söylemeli, yoksa çağıran taraf boş bir dize uydurup
     * gerekçeymiş gibi yazar. Silmenin KENDİSİ her hâlükârda kayda geçer.
     */
    public function delete(Credential $credential, ?string $reason = null): void
    {
        DB::transaction(function () use ($credential, $reason): void {
            $summary = $this->summary($credential);

            $credential->delete();

            $this->activity->record(
                ActivityAction::Deleted,
                $summary,
                $credential,
                $reason,
            );
        });
    }

    /**
     * Boş metinler null olur.
     *
     * Port BOŞ BIRAKILIRSA BOŞ KALIR. Önce türün varsayılanını (SSH için 22,
     * RDP için 3389) yazıyordu; portlar güvenlik gereği varsayılandan
     * farklı olabildiği için kasaya sessizce YANLIŞ bir port yazılıyordu.
     * Boş bir alan "bilinmiyor" der; yanlış bir sayı "biliyorum" der.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * Kaydı göremeyecek rolleri yazar.
     *
     * null = DOKUNMA. Alanı hiç göndermeyen bir istek (yönetici olmayan
     * kullanıcının güncellemesi, başka bir ekrandan gelen kısmi kayıt) var
     * olan kısıtı silmemeli — sessizce açılan bir kayıt, kısıtın en kötü
     * biçimde kaybolmasıdır.
     *
     * Değişiklik AYRI bir denetim satırına yazılır: kimin hangi kayda hangi
     * rolü kapattığı, kaydın kendi güncellemesinin içinde kaybolmamalı.
     *
     * @param  array<int, int>|null  $roleIds
     */
    public function syncRestrictions(Credential $credential, ?array $roleIds): void
    {
        if ($roleIds === null) {
            return;
        }

        $oncesi = $credential->restrictedRoles()->pluck('roles.id')->sort()->values()->all();
        $sonrasi = collect($roleIds)->unique()->sort()->values()->all();

        if ($oncesi === $sonrasi) {
            return;
        }

        $credential->restrictedRoles()->sync($sonrasi);

        $adlar = Role::query()->whereIn('id', $sonrasi)->orderBy('label')->pluck('label');

        $this->activity->record(
            ActivityAction::Updated,
            "Kayıt bazlı rol kısıtı değişti: {$credential->name} · "
                .($adlar->isEmpty() ? 'kısıt kalmadı' : 'kapalı roller: '.$adlar->implode(', ')),
            $credential,
        );
    }

    /**
     * Bu kayıtta PAROLAYI görebilecek ek rolleri yazar.
     *
     * Kısıtın aynası: grubun vermediğini tek bir kayıtta verir. null =
     * DOKUNMA — alanı hiç göndermeyen bir istek var olan izni silmemeli.
     *
     * Değişiklik AYRI bir denetim satırına yazılır: bir kayıtta kime parola
     * açıldığı, kaydın kendi güncellemesinin içinde kaybolmamalı. Parola
     * açmak, adres değiştirmekle aynı ağırlıkta bir olay değildir.
     *
     * @param  array<int, int>|null  $roleIds
     */
    public function syncGrants(Credential $credential, ?array $roleIds, string $action = 'field.password'): void
    {
        if ($roleIds === null) {
            return;
        }

        if (! in_array($action, Credential::VERILEBILEN_AKSIYONLAR, true)) {
            return;
        }

        $oncesi = $credential->grantedRoles()
            ->wherePivot('action', $action)
            ->pluck('roles.id')->sort()->values()->all();

        $sonrasi = collect($roleIds)->unique()->sort()->values()->all();

        if ($oncesi === $sonrasi) {
            return;
        }

        DB::transaction(function () use ($credential, $action, $sonrasi): void {
            /*
             * Yalnızca BU aksiyonun satırları silinir: ileride ikinci bir
             * aksiyon eklenirse süpürülmesin.
             */
            $credential->grantedRoles()->wherePivot('action', $action)->detach();

            foreach ($sonrasi as $roleId) {
                $credential->grantedRoles()->attach($roleId, ['action' => $action]);
            }
        });

        $adlar = Role::query()->whereIn('id', $sonrasi)->orderBy('label')->pluck('label');

        $ne = match ($action) {
            'connect' => ['konsol', 'konsol açabilen'],
            'view' => ['görme', 'görebilen'],
            'session' => ['portal', 'portal açabilen'],
            default => ['parola', 'parolayı görebilen'],
        };

        $this->activity->record(
            ActivityAction::Updated,
            "Kayıt bazlı {$ne[0]} izni değişti: {$credential->name} · "
                .($adlar->isEmpty() ? 'ek izin kalmadı' : "{$ne[1]} ek roller: ".$adlar->implode(', ')),
            $credential,
        );
    }

    /**
     * Bir bölümün (grup + alt grup) yeni dizilimini yazar.
     *
     * Gelen liste bölümün TAMAMI olmayabilir: süzgeç ya da rol kısıtı bazı
     * satırları gizler. Gizli satırlar yerinde kalır; gelenler, kendi
     * eski yuvalarına yeni sırayla oturur. Sonra bölüm 10'ar artışla
     * baştan numaralanır — eşit sıra değerleri (hepsi 0) kararsız dizilim
     * bırakmasın.
     *
     * @param  Collection<int, Credential>  $kayitlar
     * @param  list<string>  $sira  uuid'ler, yeni dizilimle
     */
    public function sirala(Collection $kayitlar, array $sira): void
    {
        $ilk = $kayitlar->first();

        DB::transaction(function () use ($ilk, $sira): void {
            $bolum = Credential::query()
                ->where('credential_group_id', $ilk->credential_group_id)
                ->where('alt_grup_id', $ilk->alt_grup_id)
                ->ordered()
                ->lockForUpdate()
                ->get(['id', 'uuid']);

            $gelen = array_values(array_unique($sira));
            $dizilim = $bolum->map(function (Credential $c) use (&$gelen, $sira): string {
                return in_array($c->uuid, $sira, true) ? array_shift($gelen) : $c->uuid;
            });

            $kimlik = $bolum->pluck('id', 'uuid');

            foreach ($dizilim->values() as $i => $uuid) {
                Credential::query()->whereKey($kimlik[$uuid])->update(['position' => ($i + 1) * 10]);
            }
        });

        $grup = CredentialGroup::query()->whereKey($ilk->credential_group_id)->value('name');
        $bolme = $ilk->alt_grup_id ? AltGrup::query()->whereKey($ilk->alt_grup_id)->value('name') : null;

        $this->activity->record(
            ActivityAction::Updated,
            'Kayıtlar yeniden sıralandı: '.$grup.($bolme ? " · {$bolme}" : ''),
            $ilk,
        );
    }

    /** Bölümün sonu: en büyük sıra + 10 (kendisi hariç). */
    private function sonSira(?int $grup, ?int $altGrup, ?int $haric = null): int
    {
        return (int) Credential::query()
            ->where('credential_group_id', $grup)
            ->where('alt_grup_id', $altGrup)
            ->when($haric, fn ($q) => $q->whereKeyNot($haric))
            ->max('position') + 10;
    }

    private function prepare(array $data): array
    {
        foreach (['host', 'url', 'username', 'password', 'notes', 'rotation_username', 'rotation_password',
            'login_url', 'login_username_field', 'login_password_field'] as $field) {
            if (array_key_exists($field, $data) && blank($data[$field])) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function summary(Credential $credential): string
    {
        $group = $credential->group?->name
            ?? CredentialGroup::query()->whereKey($credential->credential_group_id)->value('name')
            ?? '—';

        return "{$group} · {$credential->name}";
    }
}
