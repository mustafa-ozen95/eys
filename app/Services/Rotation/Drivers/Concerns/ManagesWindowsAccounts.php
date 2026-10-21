<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers\Concerns;

use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;

/**
 * Windows yerel hesap komutları — taşımadan BAĞIMSIZ.
 *
 * Aynı üç iş (kurtarma hesabı aç, parolayı değiştir, hesabı sil) iki ayrı
 * yoldan yapılıyor: OpenSSH açık makinelerde SSH, kapalı olanlarda WinRM.
 * Komutlar iki sürücüye kopyalansaydı biri düzeltilip diğeri eski kalırdı;
 * burada bir kez yazılır, taşımayı sürücü getirir.
 *
 * İki ayrıntı komutların tamamını belirliyor:
 *
 *  1. Parola KOMUT SATIRINA YAZILMAZ. `net user kullanici parola` en bilinen
 *     yol ama parolayı hedef makinenin süreç listesine düşürür. PowerShell
 *     onu standart girdiden okur.
 *
 *  2. Yönetici grubunun adı TÜRKÇE Windows'ta "Administrators" DEĞİLDİR.
 *     Grup, yerelleştirmeden etkilenmeyen tanınmış kimliğiyle (S-1-5-32-544)
 *     bulunur; adına göre aramak Türkçe kurulumda sessizce başarısız olurdu.
 */
trait ManagesWindowsAccounts
{
    /** Yerleşik Administrators grubunun tanınmış kimliği. */
    private const ADMIN_GROUP_SID = 'S-1-5-32-544';

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        /* Bu adı BİZ ürettik; dar süzgeçten geçmeli. */
        $this->guardGeneratedName($username);

        $ad = $this->escapeName($username);

        $this->run($session, <<<PS
            \$p = ConvertTo-SecureString {{EYS_GIZLI}} -AsPlainText -Force
            New-LocalUser -Name '{$ad}' -Password \$p -PasswordNeverExpires -AccountNeverExpires | Out-Null
            \$grup = (Get-LocalGroup -SID '{$this->adminGroupSid()}').Name
            Add-LocalGroupMember -Group \$grup -Member '{$ad}'
            PS, 'Kurtarma hesabı açılamadı.', $password);
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        $ad = $this->escapeName($username);

        $this->run($session, <<<PS
            \$p = ConvertTo-SecureString {{EYS_GIZLI}} -AsPlainText -Force
            Set-LocalUser -Name '{$ad}' -Password \$p
            PS, 'Parola değiştirilemedi.', $newPassword);
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $ad = $this->escapeName($username);

        $this->run($session, "Remove-LocalUser -Name '{$ad}'", 'Kurtarma hesabı silinemedi.');
    }

    /**
     * PowerShell betiğini çalıştırır.
     *
     * Betik base64 (UTF-16LE) olarak gönderilir: SSH yolunda Windows'un
     * varsayılan kabuğu cmd.exe ve tırnak kaçışı iki kabuktan geçerken
     * sessizce bozuluyor. -EncodedCommand bu sorunu tümüyle ortadan kaldırır
     * ve iki taşımada da aynı betiği kullanabilmemizi sağlar. Kodlamayı
     * TAŞIMA yapar, çünkü sırrın betiğe nasıl gireceğini de taşıma belirliyor.
     *
     * PAROLA BETİĞE GİRMEZ: betikte yalnızca bir yer tutucu durur
     * (bkz. PowerShellGizli) — SSH'ta standart girdiden, WinRM'de kabuğun
     * ortam değişkeninden okunur.
     */
    private function run(RotationSession $session, string $script, string $failure, ?string $stdin = null): void
    {
        $this->runPowerShell(
            $session,
            "\$ErrorActionPreference = 'Stop'\ntry {\n{$script}\n exit 0\n} catch { exit 1 }",
            $failure,
            $stdin ?? '',
        );
    }

    /**
     * HAM betiği taşımanın kendi yoluyla çalıştırır.
     *
     * $gizli: betikteki yer tutucunun yerini alacak sır (parola). Boşsa
     * betikte sır beklenmiyordur.
     */
    abstract protected function runPowerShell(
        RotationSession $session,
        string $script,
        string $failure,
        string $gizli,
    ): void;

    private function adminGroupSid(): string
    {
        return self::ADMIN_GROUP_SID;
    }

    /**
     * KASADAKİ adı PowerShell'e güvenle gömer.
     *
     * Dar bir süzgeç (`[A-Za-z0-9._-]`) VAR OLAN hesaplara uygulanmaz.
     * Windows kullanıcı adları Unicode: `GÖREVLİ` gibi Türkçe harfli bir hesap
     * böyle bir süzgece takılır ve tur, makineye hiç bağlanmadan "hesap adı
     * geçersiz" diyerek düşerdi. Adı biz seçmedik;
     * kasadaki hesap neyse odur.
     *
     * Onun yerine YALNIZCA Windows'un kendi yasakladıkları reddedilir ve ad
     * tek tırnaklı PowerShell dizgesine kaçırılarak konur — orada tek kaçış
     * kuralı tırnağı İKİLEMEKTİR. Betik base64 (UTF-16LE) gidiyor; araya
     * kabuk girmiyor, bu yüzden başka kaçış gerekmiyor.
     */
    private function escapeName(string $username): string
    {
        $ad = trim($username);

        /*
         * Yasaklı küme DÜZ LİSTE, düzenli ifade değil: aynı karakterlerin
         * yarısı hem PCRE'de hem PHP dizgesinde kaçış istiyor ve o kaçışların
         * biri eksik yazıldığında ifade sessizce bozuluyor (denendi).
         */
        $yasak = '"/\\[]:;|=,+*?<>';

        if ($ad === ''
            || mb_strlen($ad) > 20
            || strpbrk($ad, $yasak) !== false
            || preg_match('/[\x00-\x1F\x7F]/u', $ad) === 1) {
            throw new RotationException('Hesap adı Windows için geçersiz ya da gönderilemeyecek bir karakter içeriyor.');
        }

        return str_replace("'", "''", $ad);
    }

    /**
     * BİZİM ÜRETTİĞİMİZ ad — dar süzgeç burada yerinde.
     *
     * Kurtarma hesabının adını panel seçiyor; beklenmedik bir şey çıkarsa bu
     * bizim hatamızdır ve makinede tuhaf adlı bir hesap açmadan önce durmalıyız.
     */
    private function guardGeneratedName(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,20}$/', $username) !== 1) {
            throw new RotationException('Üretilen kurtarma hesabı adı geçersiz.');
        }
    }
}
