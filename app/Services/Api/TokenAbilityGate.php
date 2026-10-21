<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\User;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\PermissionRegistry;

/**
 * API jetonunun yetkisini SAHİBİNİNKİYLE kesiştirir.
 *
 * Jeton sahibi adına konuşur ama onu AŞAMAZ. Karar iki aşamalıdır:
 *
 *   1. Jeton bu kaynakta bu işi yapabiliyor mu? Yapamıyorsa burada biter.
 *   2. Yapabiliyorsa karar normal yetki zincirine BIRAKILIR — sahibinin
 *      policy'leri, grup bazlı istisnaları, yönetici geçişi.
 *
 * Böylece sahibinin yetkisi daraltıldığında jeton da daralır; jeton
 * unutulmuş bir arka kapıya dönüşmez.
 *
 * VARSAYILAN KAPALIDIR: yeteneği kaynağa çeviremediğimiz her durumda jeton
 * reddedilir. "Ne olduğunu bilmiyorum" ile "izinli" aynı şey değildir.
 */
class TokenAbilityGate
{
    /**
     * Policy metodunun jetonda hangi aksiyon olarak sorulduğu.
     *
     * "viewAny" jetonda "view"dır; ayrı bir yetki değildir. "reveal",
     * "rotate" ve "session" ise KENDİ aksiyonlarına çevrilir — parolayı
     * okumak ile kaydı görmek ayrı yetkilerdir ve jeton listesinde de ayrı
     * durmalıdır.
     *
     * @var array<string, string>
     */
    private const ESLEME = [
        'viewAny' => 'view',
        'reveal' => 'field.password',
        'rotateAny' => 'rotate',
    ];

    public function __construct(
        private ApiTokenContext $context,
        private PermissionRegistry $registry,
    ) {}

    /**
     * Gate::before kancası.
     *
     * @param  array<int, mixed>  $arguments
     * @return bool|null false: reddet. null: normal zincire bırak.
     */
    public function check(User $user, string $ability, array $arguments): ?bool
    {
        $token = $this->context->get();

        /* Oturumla gelen istek: daraltma yok. */
        if ($token === null) {
            return null;
        }

        $action = self::ESLEME[$ability] ?? $ability;

        /*
         * Kasada TEK kaynak var: erişim grupları. Yetenek o kaynağın bir
         * aksiyonuna çevrilemiyorsa jeton reddedilir.
         */
        if (! $this->registry->supports(CredentialPolicy::RESOURCE, $action)) {
            return false;
        }

        return $token->permits(CredentialPolicy::RESOURCE, $action) ? null : false;
    }
}
