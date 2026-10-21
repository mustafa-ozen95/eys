<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Merkezden gelen tanınmayan kişi için YEREL hesap açar.
 *
 * NEDEN İSTEĞE BAĞLI: merkezde hesabı olmak, bu kasada işi olmak demek
 * değildir. Kapalıyken eşleşmeyen kimlik geri çevrilir ve hesabı yönetici
 * açar. Açmak bilinçli bir karardır ve o karar MERKEZDEDİR: merkezdeki
 * İstemciler ekranında uygulama başına verilir, panelde ikinci bir kopyası
 * tutulmaz.
 *
 * ROL MERKEZDEN GELİR ama merkez EMİR VERMEZ: gelen slug'ın burada aynı adlı
 * bir karşılığı varsa o verilir (roller kardeş panellerde de bilerek aynı
 * adlarla duruyor), yoksa varsayılan rol verilir. Tanınmayan bir ad hiçbir zaman
 * "yetki yok"a değil, en dar role düşer — eksik veri yetki üretmemeli.
 *
 * YÖNETİCİLİK AYRI BİR KARAR. Merkezin yöneticisi kimlikleri yönetir; bu
 * kasanın yöneticisi bütün sistemlerin parolalarını görür. İkisi kendiliğinden
 * aynı kişi olmamalı: merkezde rol dağıtabilen biri, buradaki hiçbir
 * yöneticinin haberi olmadan kendini burada da yönetici yapabilirdi. Bu yüzden
 * yöneticilik yalnızca `identity.yonetici_devri` açıkken devrolur.
 *
 * PAROLA YAZILMAZ. Hesap rastgele, kimsenin bilmediği bir parolayla doğar:
 * bu kişinin giriş yolu merkezdir. Parolayla girmek isterse yerel bir
 * yönetici parola tanımlar.
 */
class AccountOpener
{
    /**
     * Bu kimlik için hesap AÇILABİLİR Mİ?
     *
     * KARARI MERKEZ VERİR: hangi uygulamada
     * hesabın kendiliğinden açılacağını merkezdeki İstemciler ekranı söyler
     * ve kimlik yanıtında `hesap_acilabilir` olarak gelir. Panel kendi
     * kopyasını TUTMAZ — aynı karar iki yerde durursa biri değişince öbürü
     * sessizce eski kalır ve hangisinin geçerli olduğu belirsizleşir.
     *
     * Üç koşul birden: merkez izin vermeli, merkez e-postayı DOĞRULANMIŞ
     * saymalı ve kişiye merkezde BİR ROL ATANMIŞ olmalı.
     *
     * Rolün şart olması bilinçli ve PANELİN KENDİ KARARI (yetki detayı
     * projenin içinde): merkezde yeni açılmış, henüz kimsenin bakmadığı bir
     * hesap rolsüz duruyor. Rolsüzü de içeri alsaydık, merkezde kendi kendine
     * kaydolan herkes bu panele varsayılan rolle düşerdi — oysa rol atamak,
     * o kişiye bakılmış olmasının kendisidir.
     *
     * @param  array{email_verified: bool, rol?: ?string, hesap_acilabilir?: bool}  $kimlik
     */
    public function acilabilirMi(array $kimlik): bool
    {
        return ($kimlik['hesap_acilabilir'] ?? false)
            && ($kimlik['email_verified'] ?? false)
            && filled($kimlik['rol'] ?? null);
    }

    /**
     * @param  array{sub: string, name: string, email: string, email_verified: bool, rol?: ?string}  $kimlik
     */
    public function ac(array $kimlik): User
    {
        $rol = $this->rol($kimlik['rol'] ?? null);

        $kullanici = User::create([
            'name' => $kimlik['name'] !== '' ? $kimlik['name'] : $kimlik['email'],
            'email' => $kimlik['email'],

            /*
             * Bilinmeyen parola: hesabın kapısı merkezdir. Boş bırakmak ya da
             * öngörülebilir bir değer koymak, parolayla girişi açık bir kapı
             * hâline getirirdi.
             */
            'password' => Str::random(64),
            'role_id' => $rol?->id,
            'status' => UserStatus::Active,
        ]);

        /* Yetki kopyalanmaz: rol canlı okunuyor (bkz. RolePermissions). */
        return $kullanici->fresh();
    }

    /**
     * Hangi rolle doğacak: kararın tamamı burada.
     */
    private function rol(?string $slug): ?Role
    {
        if (blank($slug)) {
            return Role::default();
        }

        $rol = Role::query()->where('slug', $slug)->first();

        if ($rol === null) {
            return Role::default();
        }

        // Yöneticilik yalnızca AÇIKÇA devrediliyorsa geçer.
        if ($rol->is_admin && ! config('identity.yonetici_devri')) {
            return Role::default();
        }

        return $rol;
    }

    /**
     * Kayda yazılacak açıklama: hangi rolün neden verildiği.
     *
     * Ekranda "hesap açıldı" demek yetmiyor; denetimde sorulan soru, o kişinin
     * bu yetkiye NASIL sahip olduğu.
     */
    public function aciklama(User $kullanici, array $kimlik): string
    {
        $gelen = $kimlik['rol'] ?? null;
        $verilen = $kullanici->role?->slug ?? 'rolsüz';

        if (blank($gelen)) {
            return "Merkezden hesap açıldı: {$kullanici->email} · merkezde rolü yok, varsayılan rol verildi ({$verilen})";
        }

        return $gelen === $verilen
            ? "Merkezden hesap açıldı: {$kullanici->email} · merkezdeki rolüyle ({$verilen})"
            : "Merkezden hesap açıldı: {$kullanici->email} · merkezdeki rol '{$gelen}' devredilmedi, verilen rol: {$verilen}";
    }
}
