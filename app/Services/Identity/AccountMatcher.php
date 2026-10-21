<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use App\Models\UserIdentity;

/**
 * Merkezdeki kimliği YEREL hesapla eşleştirir.
 *
 * Sıra ve gerekçeleri:
 *
 *  1. Daha önce bağlanmışsa SUBJECT ile bulunur. Kişi merkezde e-postasını
 *     değiştirse bile bağ kopmaz.
 *
 *  2. Bağ yoksa e-postaya bakılır — ama YALNIZCA merkez o adresi doğrulanmış
 *     sayıyorsa ve yerelde tam olarak BİR karşılığı varsa. Doğrulanmamış bir
 *     adresle eşleştirmek, meslektaşının e-postasıyla merkezde hesap açan
 *     birinin onun yerine girmesi demek olurdu.
 *
 *  3. Hiçbiri tutmuyorsa BAĞLANMAZ. Otomatik kullanıcı oluşturmak yok:
 *     merkezde hesabı olan herkesin bu panelde de işi olduğu anlamına
 *     gelmez. Hesabı yönetici açar.
 */
class AccountMatcher
{
    public const SAGLAYICI = 'kimlik';

    /** @param  array{sub: string, name: string, email: string, email_verified: bool}  $kimlik */
    public function bul(array $kimlik): ?User
    {
        $bag = UserIdentity::where('provider', self::SAGLAYICI)
            ->where('subject', $kimlik['sub'])
            ->first();

        if ($bag?->user) {
            return $bag->user;
        }

        return $kimlik['email_verified'] ? $this->epostayaGore($kimlik['email']) : null;
    }

    /** İlk eşleşmeyi kalıcı hâle getirir. */
    public function bagla(User $kullanici, array $kimlik): UserIdentity
    {
        return UserIdentity::updateOrCreate(
            ['provider' => self::SAGLAYICI, 'subject' => $kimlik['sub']],
            ['user_id' => $kullanici->id, 'email' => $kimlik['email'], 'last_login_at' => now()],
        );
    }

    /**
     * E-postayla eşleşme: TEK karşılık şart.
     *
     * Birden fazla hesap aynı adresi taşıyorsa hangisinin kastedildiği belli
     * değildir; tahmin etmek yanlış hesaba giriş demek olurdu.
     */
    private function epostayaGore(string $eposta): ?User
    {
        $adaylar = User::where('email', $eposta)->limit(2)->get();

        return $adaylar->count() === 1 ? $adaylar->first() : null;
    }
}
