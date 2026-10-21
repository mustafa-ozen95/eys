<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers\Concerns;

use App\Models\Credential;
use App\Services\Rotation\PowerShellGizli;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Support\Facades\Process;

/**
 * WinRM taşıması — OpenSSH'i olmayan Windows makineler için.
 *
 * Sunucu `AllowUnencrypted=false` olduğunda kimlik doğrulandıktan SONRA
 * mesajın da NTLM oturum anahtarıyla mühürlenmesini ister. Bunu PHP'de elle
 * yazmak bir parola kasasına el yapımı kripto koymak olurdu; iş, olgun bir
 * istemciyi (pywinrm) çağıran küçük bir yardımcıya verilir.
 *
 * SSH taşımasıyla AYNI yüzeyi sunar (open/verify/runPowerShell), böylece
 * Windows komutları tek yerde kalır (bkz. ManagesWindowsAccounts).
 *
 * SSH'ten bir farkı var: kalıcı bir oturum yoktur. Her çağrı kendi kabuğunu
 * açıp kapatır; oturum nesnesi yalnızca kimliği taşır.
 */
trait RunsOverWinRm
{
    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        /* Kimlik burada sınanır: bağlanamıyorsak tur daha başlamadan durmalı. */
        $this->call($credential, $username, $password, ['action' => 'verify'], 'Bağlantı kurulamadı.');

        return new RotationSession($credential, $username, $password, static fn () => null);
    }

    public function verify(Credential $credential, string $username, string $password): bool
    {
        try {
            $this->open($credential, $username, $password)->close();

            return true;
        } catch (RotationException) {
            return false;
        }
    }

    /**
     * Komutun söylediklerinden okunabilir bir özet.
     *
     * PowerShell hata akışını CLIXML olarak sarmalıyor; ham hâli ekranda
     * XML çöplüğü olurdu. Etiketler atılır, satırlar birleştirilir ve metin
     * kısaltılır.
     *
     * @param  array<string, mixed>  $sonuc
     */
    private function ciktiOzeti(array $sonuc, string $gizli): string
    {
        $metin = '';

        foreach (['stderr', 'stdout'] as $akis) {
            $metin .= ' '.base64_decode((string) ($sonuc[$akis] ?? ''), true);
        }

        /* CLIXML sarmalı ve etiketler. */
        $metin = preg_replace('/#< CLIXML/', ' ', $metin) ?? $metin;
        $metin = preg_replace('/<[^>]*>/', ' ', $metin) ?? $metin;
        $metin = str_replace(['_x000D_', '_x000A_'], ' ', $metin);

        /* Sır metne düşmüş olamaz ama düşseydi burada kalmasın. */
        if ($gizli !== '') {
            $metin = str_replace($gizli, '***', $metin);
        }

        $metin = trim((string) preg_replace('/\s+/', ' ', $metin));

        return $metin === '' ? '' : ' Makinenin söylediği: '.mb_substr($metin, 0, 300);
    }

    /**
     * Bağlanılacak WinRM portu.
     *
     * Çoğu Windows kaydında kaydın portu WinRM portudur. Panel kayıtlarında
     * değildir: orada port panelin portudur (Plesk'te 8443) ve WinRM oraya
     * bağlanmaz — bu yüzden sürücü kendi portunu söyleyebilir (bkz.
     * PleskWindowsPasswordDriver).
     */
    protected function winRmPort(Credential $credential): int
    {
        return $credential->port ?? (int) config('rotation.winrm.port', 5985);
    }

    protected function runPowerShell(
        RotationSession $session,
        string $script,
        string $failure,
        string $gizli,
    ): void {
        /** @var Credential $credential */
        $credential = $session->handle;

        /*
         * SIR KABUĞUN ORTAM DEĞİŞKENİNDE GİDER, standart girdiden değil.
         *
         * pywinrm 0.3.0'da `send_command_input` yok; yardımcı onu çağırsaydı
         * parola isteyen her WinRM turu "AttributeError" diyerek düşerdi.
         * WS-Man kabuğu açılırken ortam değişkeni verilebiliyor ve değişken
         * komut satırında görünmüyor.
         */
        $encoded = PowerShellGizli::kodla(PowerShellGizli::ortamIle($script));

        $sonuc = $this->call($credential, $session->username, $session->password, [
            'action' => 'run',
            'program' => 'powershell.exe',
            'arguments' => ['-NonInteractive', '-NoProfile', '-EncodedCommand', $encoded],
            'env' => $gizli === '' ? [] : [PowerShellGizli::DEGISKEN => $gizli],
        ], $failure);

        if ((int) ($sonuc['status'] ?? 1) !== 0) {
            /*
             * ÇIKTI ARTIK SÖYLENİYOR.
             *
             * "Hedef makine komutu reddetti (kod 1)" hiçbir şey anlatmıyordu
             * ve sebebi bulmak makineye elle bağlanmayı gerektiriyordu
             * (ör. Plesk'in kendi hatası görünmediği için PowerShell'in boş
             * argümanı düşürdüğü ancak elle denemeyle anlaşılır).
             *
             * Bu yolda çıktı SIR TAŞIMAZ: parola komut satırına da betiğe de
             * girmiyor, ortam değişkeninde duruyor ve yankılanmıyor. Yine de
             * sır metinden AYRICA temizleniyor — "taşımaz" bir varsayımdır,
             * temizlik ölçüdür.
             */
            throw new RotationException(
                $failure.' Hedef makine komutu reddetti (kod '.($sonuc['status'] ?? '?').').'
                .$this->ciktiOzeti($sonuc, $gizli),
            );
        }
    }

    /**
     * Yardımcıyı çağırır.
     *
     * İstek STANDART GİRDİDEN gider: parola komut satırına yazılsaydı bu
     * sunucunun süreç listesinde görünürdü.
     *
     * @param  array<string, mixed>  $istek
     * @return array<string, mixed>
     */
    private function call(
        Credential $credential,
        string $username,
        string $password,
        array $istek,
        string $failure,
    ): array {
        $ayar = config('rotation.winrm');

        $sonuc = Process::timeout((int) $ayar['timeout'] + 15)
            ->env(['OPENSSL_CONF' => $ayar['openssl_conf']])
            ->input(json_encode([
                'host' => $credential->host,
                'port' => $this->winRmPort($credential),
                'username' => $username,
                'password' => $password,
                'timeout' => (int) $ayar['timeout'],
                ...$istek,
            ], JSON_THROW_ON_ERROR))
            ->run([$ayar['python'], $ayar['runner']]);

        if (! $sonuc->successful()) {
            throw new RotationException($failure.' WinRM yardımcısı çalıştırılamadı.');
        }

        $yanit = json_decode(trim($sonuc->output()), true);

        if (! is_array($yanit)) {
            throw new RotationException($failure.' WinRM yardımcısı anlaşılmayan bir yanıt verdi.');
        }

        if (($yanit['ok'] ?? false) !== true) {
            throw new RotationException($failure.' '.$this->humanize((string) ($yanit['error'] ?? '')));
        }

        return $yanit;
    }

    /**
     * Kütüphane hatasını insanca bir cümleye çevirir.
     *
     * Ham Python istisnası kullanıcıya gösterilecek bir metin değil; sık
     * görülen üç durum kendi cümlesini alır, gerisi kısaltılır.
     */
    private function humanize(string $error): string
    {
        return match (true) {
            str_contains($error, '401') => 'Kimlik doğrulaması reddedildi.',
            str_contains($error, 'Max retries') || str_contains($error, 'ConnectionError') => 'Makineye ulaşılamadı.',
            str_contains($error, 'pywinrm yüklü değil') => $error,
            default => str($error)->limit(120)->value(),
        };
    }
}
