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
 * adı ve parolası kayıtlı. İşletim sistemi "bilinmiyor" girilmiş SSH kaydı
 * da hedeftir: SSH bilgisi olduğu hâlde ekrana gelmiyordu. Betik paket
 * yöneticisini kendisi arar; bulamazsa yalnızca bunu bildirir. Gömülü
 * cihazlar (switch vb.) hedef DEĞİL: apt/dnf yok, kabuğu Linux kabuğu değil.
 *
 * RDP ile kaydedilmiş LINUX makine de hedeftir (Teknik PC'ler: xrdp). xrdp
 * sistem kullanıcısıyla girer; aynı kullanıcı ve parola SSH'ta da geçer.
 * Bağlantı o kayıtta kaydın portuna (RDP portu) DEĞİL, 22'ye gider (sshPort).
 * Windows RDP ve web/panel kayıtları hedef değil: kimlikleri işletim sistemi
 * kabuğunun kimliği değil. Ekran, toplu uç, zamanlayıcı ve kuyruk işi aynı
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
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $ssh) => $ssh
                    ->where('access_type', AccessType::Ssh)
                    ->whereIn('operating_system', [OperatingSystem::Linux, OperatingSystem::Unknown]))
                ->orWhere(fn (Builder $rdp) => $rdp
                    ->where('access_type', AccessType::Rdp)
                    ->where('operating_system', OperatingSystem::Linux)))
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

    /** Makine anahtarı: küçük harf adres + SSH portu. */
    public static function makine(Credential $credential): string
    {
        return mb_strtolower(trim((string) $credential->host)).':'.self::sshPort($credential);
    }

    /**
     * Bağlanılacak SSH portu. SSH kaydında kaydın portu (yoksa 22); RDP
     * kaydında kaydın portu RDP'nin portudur (3389), SSH için 22.
     */
    public static function sshPort(Credential $credential): int
    {
        return $credential->access_type === AccessType::Ssh ? ($credential->port ?? 22) : 22;
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
     * Kayıt güncellemeden HARİÇ mi (kayıt bazlı; kilit ise makine bazlı).
     * Hariç kayıtla makineye bağlanılmaz: kurulum, yeniden başlatma, denetim.
     */
    public function haricMi(Credential $credential): bool
    {
        return OsUpdateState::query()
            ->where('credential_id', $credential->getKey())
            ->where('excluded', true)
            ->exists();
    }

    /**
     * Makineye bağlanılacak kayıt: kayıt hariçse hariç olmayan ilk kardeşi,
     * o da yoksa null. Yalnızca sistemin kendi işleri için (zamanlanmış ve
     * gecikmeli denetim); kişinin seçimi kardeşe AKTARILMAZ (OsUpdateLauncher).
     */
    public function baglanilacak(Credential $credential): ?Credential
    {
        if (! $this->haricMi($credential)) {
            return $credential;
        }

        $kardesler = $this->kardesler($credential);
        $haric = OsUpdateState::query()
            ->whereIn('credential_id', $kardesler->modelKeys())
            ->where('excluded', true)
            ->pluck('credential_id')
            ->all();

        return $kardesler->sortBy('id')->first(fn (Credential $k): bool => ! in_array($k->getKey(), $haric, true));
    }

    /** @var list<string>|null */
    private ?array $panelAdresleri = null;

    /**
     * EYS'NİN KENDİ SUNUCUSU MU — kilitten bağımsız, kurulum ve yeniden
     * başlatma YAPILMAZ (denetim serbest).
     *
     * 16.11.2026: panelden NineDeploy'a (EYS'nin üzerinde çalıştığı sunucu)
     * "tüm güncellemeler" kuruldu. Docker yükseltmesi EYS konteynerini
     * durdurmaya çalıştı; EYS'nin kuyruk işçisi o kurulumun bitmesini
     * beklediği için Docker açılamadı, bütün siteler düştü.
     *
     * Adresler: panelin kendi adresinin (APP_URL) çözüldüğü IP'ler ve
     * os_update.panel_hosts. Vekil başka makinedeyse APP_URL vekili gösterir;
     * o da korunur, yanlış yön yalnızca "fazla koruma"dır.
     */
    public function panelSunucusu(Credential $credential): bool
    {
        $this->panelAdresleri ??= self::panelAdresleri();

        return in_array(mb_strtolower(trim((string) $credential->host)), $this->panelAdresleri, true);
    }

    /** @return list<string> */
    private static function panelAdresleri(): array
    {
        $adresler = array_map(
            fn (string $a): string => mb_strtolower(trim($a)),
            array_filter(explode(',', (string) config('os_update.panel_hosts', ''))),
        );
        $ad = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($ad) && $ad !== '') {
            $adresler[] = mb_strtolower($ad);
            $adresler = [...$adresler, ...(filter_var($ad, FILTER_VALIDATE_IP) ? [] : (gethostbynamel($ad) ?: []))];
        }

        /* Yerel adres "panel sunucusu" sayılmaz: geliştirmede her kayıt öyle görünürdü. */
        return array_values(array_unique(array_diff($adresler, ['localhost', '127.0.0.1', '::1'])));
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
