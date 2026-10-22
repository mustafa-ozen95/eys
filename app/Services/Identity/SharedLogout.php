<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * ORTAK ÇIKIŞ — panel tarafı (merkezdeki eşi: KYM App\Services\SharedLogout).
 *
 * Bir panelden çıkış yapan kişi merkezden ve öteki panellerden de çıkmış
 * olmalı. Her uygulama kendi oturumunu tutar; kişinin merkezdeki son
 * çıkış anından ÖNCE açılmış panel oturumu bir sonraki istekte kapanır
 * (bkz. VerifyIdentityStatus). Anı üç yol getirir:
 *
 *   - merkezin imzalı çıkış bildirimi (IdentityLogoutController),
 *   - identity-status yanıtındaki `last_logout` (bildirim kaybolursa),
 *   - bu panelden çıkış (LoginController · destroy; merkeze de bildirilir).
 *
 * An yalnızca İLERİ gider: geç gelen eski bir bildirim yeni girişi kapatmaz.
 */
final class SharedLogout
{
    /** Oturumun açıldığı an (ms); Login olayında yazılır (bkz. WriteLogin). */
    public const OTURUM_ANAHTARI = 'oturum_acilis_ms';

    public static function simdiMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Çıkış ekran kilidinden mi (süre doldu)? O çıkış ortak değildir (bkz. session-lock.js). */
    public static function zamanAsimiMi(Request $istek): bool
    {
        return $istek->header('X-Oturum-Zaman-Asimi') === '1';
    }

    public static function bag(User $kullanici): ?UserIdentity
    {
        return UserIdentity::query()
            ->where('user_id', $kullanici->getKey())
            ->where('provider', AccountMatcher::SAGLAYICI)
            ->first();
    }

    public static function isle(UserIdentity $bag, int $an): void
    {
        if ($an > (int) $bag->merkez_cikis_ms) {
            $bag->forceFill(['merkez_cikis_ms' => $an])->save();
        }
    }

    public static function oturumEskiMi(UserIdentity $bag, Session $oturum): bool
    {
        $cikis = (int) $bag->merkez_cikis_ms;

        return $cikis > 0 && (int) $oturum->get(self::OTURUM_ANAHTARI, 0) < $cikis;
    }
}
