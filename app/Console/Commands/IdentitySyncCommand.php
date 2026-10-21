<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\CenterClient;
use App\Services\Identity\IdentitySyncer;
use Illuminate\Console\Command;

/**
 * Merkezdeki rol ve durum değişikliklerini yerel hesaplara taşır.
 *
 * NEDEN VAR: erişimi merkezde kalkan biri, buradaki oturumu açık olduğu için
 * erişmeye devam ediyordu. Panelde gezinen kişi için bunu ara katman zaten
 * yapıyor (bkz. VerifyIdentityStatus); bu komut hiç uğramayan hesapları da
 * kapatır ve toplu bir eşitleme gerektiğinde elle çalıştırılır.
 *
 * MERKEZE TEK İSTEK atılır: bütün kimlikler bir arada sorulur.
 */
class IdentitySyncCommand extends Command
{
    protected $signature = 'erisim:kimlik-esitle';

    protected $description = 'Merkezdeki rol ve durum değişikliklerini yerel hesaplara uygular';

    public function handle(CenterClient $merkez, IdentitySyncer $esitleyici): int
    {
        if (! $merkez->acikMi()) {
            $this->warn('Kimlik Yönetim Merkezi yapılandırılmamış; eşitleme yapılmadı.');

            return self::SUCCESS;
        }

        $sayac = $esitleyici->esitle();

        $this->info(
            "{$sayac['sorulan']} kimlik soruldu; {$sayac['kapatilan']} hesap kapatıldı, "
            ."{$sayac['acilan']} hesap açıldı, {$sayac['rol_degisen']} hesabın rolü güncellendi."
        );

        return self::SUCCESS;
    }
}
