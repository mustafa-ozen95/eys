<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Enums\UserStatus;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * GÖRSEL TEST veritabanının kurulumu.
 *
 * Playwright senaryoları belirli kayıtların ADIYLA arıyor ("srv-a SSH",
 * "iLO Deneme", "Grup B"). Elle kurulan bir veri, sqlite dosyası kaybolunca
 * hiçbir yerde yazılı kalmaz ve senaryolar "kayıt bulunamadı" diye düşer;
 * sebebi ancak senaryolar tek tek okunarak anlaşılır.
 *
 * Bu yüzden kurulum BURADA duruyor. Neyin neden gerektiği her kaydın yanında
 * yazılı, çünkü bir senaryo değiştiğinde buranın da değişmesi gerektiği başka
 * türlü anlaşılmıyor.
 *
 * ÜRETİMDE ÇALIŞTIRILMAZ: `DatabaseSeeder`'a bağlı değildir, elle çağrılır.
 */
class VisualTestSeeder extends Seeder
{
    public function run(): void
    {
        $yoneticiRol = Role::where('is_admin', true)->firstOrFail();
        $sadeRol = Role::where('is_admin', false)->firstOrFail();

        User::updateOrCreate(['email' => 'gorsel@ornek.test'], [
            'name' => 'Görsel Test',
            'password' => 'gorsel-test-1616',
            'role_id' => $yoneticiRol->id,
            'status' => UserStatus::Active,
        ]);

        /* Yetkisiz görünümü sınayan senaryolar için: yönetici OLMAYAN hesap. */
        User::updateOrCreate(['email' => 'kisitli@ornek.test'], [
            'name' => 'Kısıtlı Kullanıcı',
            'password' => 'gorsel-test-1616',
            'role_id' => $sadeRol->id,
            'status' => UserStatus::Active,
        ]);

        $grupA = CredentialGroup::firstOrCreate(['name' => 'Grup A'], ['position' => 1]);

        /* Görsel takıma ait ikinci grup; senaryo bağlantıyı adıyla buluyor. */
        $grupB = CredentialGroup::firstOrCreate(['name' => 'Grup B'], ['position' => 90]);

        /*
         * Parola gösterme senaryosunun aradığı kayıt.
         *
         * Parolası OLMAK ZORUNDA: parolasız bir kayda denk geldiğinde test
         * sessizce atlanıyor, yani kapsam kimse fark etmeden düşüyordu.
         */
        $this->kayit($grupA, 'srv-a SSH', [
            'access_type' => AccessType::Ssh,
            'operating_system' => OperatingSystem::Linux,
            'host' => '10.0.0.11',
            'username' => 'root',
            'password' => 'gorsel-parola-1',
            'rotation_driver' => 'ssh',
            'rotation_enabled' => true,
            'position' => 1,
        ]);

        /* Toplu seçim en az İKİ hazır kayıt istiyor ("2 kaydın parolası"). */
        $this->kayit($grupA, 'srv-a Yedek SSH', [
            'access_type' => AccessType::Ssh,
            'operating_system' => OperatingSystem::Linux,
            'host' => '10.0.0.12',
            'username' => 'root',
            'password' => 'gorsel-parola-2',
            'rotation_driver' => 'ssh',
            'rotation_enabled' => true,
            'position' => 2,
        ]);

        /*
         * Portal senaryosunun aradığı kayıt: giriş adresi girilmeden de
         * oturum açabilen bir iLO.
         */
        $this->kayit($grupA, 'iLO Deneme', [
            'access_type' => AccessType::Ilo,
            'operating_system' => OperatingSystem::Bmc,
            'host' => '10.0.0.13',
            'username' => 'Administrator',
            'password' => 'gorsel-ilo-1',
            'position' => 3,
        ]);

        /*
         * Grup B'de HİÇ hazır kayıt olmamalı: senaryo "seçilenler kapalı,
         * tüm kasa açık" ayrımını tam olarak burada sınıyor.
         */
        $this->kayit($grupB, 'Portal B', [
            'access_type' => AccessType::Web,
            'operating_system' => OperatingSystem::Application,
            'url' => 'https://portal.example.test',
            'username' => 'ornek-uye',
            'password' => 'gorsel-parola-3',
            'rotation_enabled' => false,
            'position' => 1,
        ]);
    }

    /** @param  array<string, mixed>  $veri */
    private function kayit(CredentialGroup $grup, string $ad, array $veri): void
    {
        Credential::updateOrCreate(
            ['credential_group_id' => $grup->id, 'name' => $ad],
            [...$veri, 'is_active' => true],
        );
    }
}
