<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Credential;
use App\Services\Rotation\DriverRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Kasadaki kimlikleri SINAR — hiçbir şeyi değiştirmeden.
 *
 * NEDEN VAR: bir kaydın "güncellemeye hazır" görünmesi, o güncellemenin
 * gerçekten yürüyeceği anlamına gelmiyordu. İki kimlik sessizce bayatlıyor:
 *
 *   - Kayıtlı parola, makinede elle değiştirilmiş olabilir.
 *   - GÜNCELLEYEN kimlik (rotation_username/password) bir kopyadır ve asıl
 *     kaydı döndürüldüğünde kopya eski kalır.
 *
 * Örneğin bir Plesk kaydının güncelleyen kimliği, aynı makinenin root
 * parolası döndürüldüğünde geçersiz kalır. Panel kaydı yine "hazır"
 * gösterir; düğmeye basılırsa tur ilk adımda düşer.
 *
 * Komut yalnızca GİRİŞ dener; hesap açmaz, parola değiştirmez. Sorun bulursa
 * çıkış kodu 1'dir, yani izlemeye bağlanabilir.
 */
class AuditVaultCommand extends Command
{
    protected $signature = 'erisim:kasa-denetle {--kayit= : Yalnızca bu kaydın kimliğini sınar}';

    protected $description = 'Otomasyona hazır kayıtların kimliklerini sınar (salt okuma)';

    public function handle(DriverRegistry $registry): int
    {
        $records = Credential::with('group')
            ->otomasyon()
            ->when($this->option('kayit'), fn ($query, $uuid) => $query->where('uuid', $uuid))
            ->get()
            ->filter(fn (Credential $c): bool => $registry->blockedReason($c) === null)
            ->sortBy('id');

        if ($records->isEmpty()) {
            $this->info('Otomasyona hazır kayıt yok.');

            return self::SUCCESS;
        }

        $rows = $records->map(fn (Credential $c): array => $this->check($registry, $c))->all();

        $this->table(['Kayıt', 'Sürücü', 'Kayıtlı parola', 'Güncelleyen kimlik'], $rows);

        $sorunlu = collect($rows)->filter(fn (array $r): bool => str_contains($r[2].$r[3], 'GEÇERSİZ'))->count();

        if ($sorunlu === 0) {
            $this->info($records->count().' kaydın kimliği geçerli.');

            return self::SUCCESS;
        }

        $this->error($sorunlu.' kayıtta sorun var: bu kayıtlarda güncelleme başlamadan düşer.');

        return self::FAILURE;
    }

    /** @return array<int, string> */
    private function check(DriverRegistry $registry, Credential $credential): array
    {
        $driver = $registry->for($credential);

        $stored = $this->attempt(fn (): bool => $driver->verify(
            $credential,
            (string) $credential->username,
            (string) $credential->password,
        ));

        /*
         * Güncelleyen kimlik AYRI sorulur: kimi sürücüde değiştiren ile
         * değişen hesap farklıdır (Plesk'te panel admini ile makinenin SSH
         * kimliği). Boşsa kaydın kendi hesabı kullanılıyor demektir.
         */
        $updater = blank($credential->rotation_username)
            ? '—'
            : $this->attempt(function () use ($driver, $credential): bool {
                $driver->open(
                    $credential,
                    (string) $credential->rotation_username,
                    (string) $credential->rotation_password,
                )->close();

                return true;
            });

        return [
            '#'.$credential->id.' '.$credential->name,
            (string) $registry->keyFor($credential),
            $stored,
            $updater,
        ];
    }

    /** @param  callable(): bool  $probe */
    private function attempt(callable $probe): string
    {
        try {
            return $probe() ? 'GEÇERLİ' : 'GEÇERSİZ';
        } catch (Throwable $e) {
            /* Ulaşılamamak geçersizlikten farklıdır; ikisi karıştırılmamalı. */
            return 'GEÇERSİZ ('.str($e->getMessage())->limit(40).')';
        }
    }
}
