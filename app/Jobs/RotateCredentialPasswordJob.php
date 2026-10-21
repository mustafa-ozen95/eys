<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Credential;
use App\Models\User;
use App\Services\Rotation\PasswordRotator;
use App\Services\Rotation\RotationLauncher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Döndürmeyi kuyruğa alır: cihaza bağlanmak saniyeler sürebilir ve tarayıcı
 * beklememeli.
 *
 * TEKRAR DENENMEZ (tries = 1). Yarıda kalmış bir döndürmeyi körlemesine
 * tekrarlamak cihazı bilinmeyen bir parolayla bırakabilir; ikinci deneme
 * insan kararıyla başlatılır.
 *
 * AYNI KAYIT İÇİN İKİNCİ İŞ SIRAYA GİRMEZ (ShouldBeUnique). Düğmeye iki kez
 * basmak, aynı kaydı hem tek tek hem toplu seçmek ya da iki kişinin aynı anda
 * denemesi kolayca oluyor. Asıl koruma turun kendisindeki kilittir
 * (PasswordRotator), ama gereksiz işi kuyruğa hiç almamak daha temiz: aksi
 * hâlde kayıtta "zaten sürüyor" diye biten sahte turlar birikirdi.
 */
class RotateCredentialPasswordJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * Tekillik süresi işin zaman aşımından uzun.
     *
     * Kısa olsaydı, hâlâ çalışan bir turun üzerine ikincisi sıraya girebilirdi.
     */
    public int $uniqueFor = 600;

    public function __construct(
        private Credential $credential,
        private ?User $actor = null,
        private ?string $reason = null,
    ) {}

    /** Tekillik KAYIT BAŞINA: farklı kayıtlar birbirini beklemez. */
    public function uniqueId(): string
    {
        return (string) $this->credential->getKey();
    }

    public function handle(PasswordRotator $rotator): void
    {
        /*
         * "Sıraya alındı" izi işçi BAŞLAYINCA düşer. Önce yalnızca kayıt
         * panelinin yoklaması siliyordu; toplu turda kimse kayıtları tek tek
         * açmadığı için biten tur beş dakika "sırada" görünüyordu.
         */
        try {
            $rotator->rotate($this->credential, $this->actor, $this->reason);
        } finally {
            RotationLauncher::forgetPending($this->credential);
        }
    }
}
