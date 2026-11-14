<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\OsUpdateState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sistem güncellemesinin HEDEFLERİ ve KİLİDİ — tek yerde.
 *
 * Hedef: kişisel olmayan, etkin, SSH erişimli Linux kaydı; adresi, kullanıcı
 * adı ve parolası kayıtlı. Ekran, toplu uç, zamanlayıcı ve kuyruk işi aynı
 * sorguyu kullanır: biri genişletilip öteki dar kalırsa ekranda seçilemeyen
 * kayda uçtan kurulum yapılabilirdi.
 *
 * MAKİNE = adres + port. Aynı makinenin iki kaydı (root ve dgn gibi) tek
 * makinedir: biri kilitliyse ikisinden de kurulum yapılmaz, denetim sonucu
 * ikisine birden yazılır.
 */
class OsUpdateTargets
{
    /** @return Builder<Credential> */
    public function sorgu(): Builder
    {
        return Credential::query()
            ->otomasyon()
            ->where('operating_system', OperatingSystem::Linux)
            ->where('access_type', AccessType::Ssh)
            ->where('is_active', true)
            ->whereNotNull('host')->where('host', '!=', '')
            ->whereNotNull('username')->where('username', '!=', '')
            ->whereNotNull('password');
    }

    /** @return Builder<Credential> */
    public function kullaniciIcin(User $user): Builder
    {
        return $this->sorgu()->visibleTo($user);
    }

    public function hedefMi(Credential $credential): bool
    {
        return $this->sorgu()->whereKey($credential->getKey())->exists();
    }

    /**
     * Ekranda ve iletilerde makinenin adı: "alt grup · kayıt" (DGN_NOC · SSH).
     *
     * Kayıt adları çoğunlukla erişim türüdür ("SSH"); hangi makine olduğunu
     * alt grup söyler. Alt grup yoksa kayıt adı tek başına yeter.
     */
    public static function ad(Credential $credential): string
    {
        $alt = $credential->altGrup?->name;

        return filled($alt) ? "{$alt} · {$credential->name}" : $credential->name;
    }

    /** Makine anahtarı: küçük harf adres + port (yoksa 22). */
    public static function makine(Credential $credential): string
    {
        return mb_strtolower(trim((string) $credential->host)).':'.($credential->port ?? 22);
    }

    /**
     * Aynı makinenin hedef kayıtları (kendisi dahil).
     *
     * @return Collection<int, Credential>
     */
    public function kardesler(Credential $credential): Collection
    {
        return $this->sorgu()
            ->whereRaw('LOWER(TRIM(host)) = ?', [mb_strtolower(trim((string) $credential->host))])
            ->whereRaw('COALESCE(port, 22) = ?', [$credential->port ?? 22])
            ->get();
    }

    /**
     * Makinedeki kilit, varsa.
     *
     * Kilit hangi kayıtta konduysa o kayıtta durur ama MAKİNEYİ korur:
     * kilitsiz kardeş kayıttan kurulum başlatmak kilidi aşmanın yolu olmasın.
     */
    public function kilit(Credential $credential): ?OsUpdateState
    {
        return OsUpdateState::query()
            ->where('locked', true)
            ->whereIn('credential_id', $this->kardesler($credential)->modelKeys())
            ->with('credential')
            ->first();
    }

    /**
     * Kilitli makinelerin anahtarları → kilidi taşıyan durum. Liste ekranı
     * satır başına sorgu atmasın diye tek seferde.
     *
     * @return array<string, OsUpdateState>
     */
    public function kilitliMakineler(): array
    {
        $kilitler = [];

        OsUpdateState::query()
            ->where('locked', true)
            ->with(['credential.altGrup', 'locker'])
            ->get()
            ->each(function (OsUpdateState $durum) use (&$kilitler): void {
                if ($durum->credential !== null) {
                    $kilitler[self::makine($durum->credential)] ??= $durum;
                }
            });

        return $kilitler;
    }
}
