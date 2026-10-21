<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos;

use Throwable;

/**
 * Junos yapılandırmasına YAZMA — döndürmenin ihtiyacı olan en küçük hâli.
 *
 * Akış (cihazda doğrulanmış sıra):
 *
 *  1. lock-configuration: aday kopya kilitlenir. "open-configuration private"
 *     KULLANILMAZ: Junos özel aday içinde "commit confirmed"i kabul etmiyor,
 *     yani ya geri alma güvencesi ya da yazma kaybolurdu. Kilit ikisini de korur.
 *  2. Paylaşılan adayın TEMİZ olduğu doğrulanır; başkasının bekleyen
 *     değişikliği varsa yazma REDDEDİLİR, çünkü commit onu da yayına alırdı.
 *  3. load-configuration merge, ardından `compare rollback 0`. Fark boşsa
 *     commit HİÇ atılmaz.
 *  4. commit: geri alınabilir (confirmed) ya da düz.
 *  5. Geri alınabilir ise YENİ bir oturum açılıp commit onaylanır. O oturumun
 *     açılabilmesi, değişikliğin erişimi bozmadığının kanıtıdır; açılamazsa
 *     onay verilmez ve cihaz kendiliğinden eski hâline döner.
 *
 * Fark (diff) metni HİÇBİR YERE yazılmaz ve döndürülmez: cihaz parolanın
 * özetini fark çıktısında gösterir.
 */
final class ConfigurationWriter
{
    public function __construct(
        private readonly NetconfClient $client,
        private readonly int $confirmMinutes = 2,
    ) {}

    /**
     * @param  NetconfTarget  $target  Oturumu açan kimlik; onay oturumu da bununla açılır
     * @param  string  $document  <configuration> kök etiketiyle Junos XML
     * @param  string  $summary  Commit günlüğüne yazılır (ASCII, parolasız)
     * @param  bool  $geriAlinabilir  Yalnızca KENDİ ERİŞİMİMİZİ etkileyebilecek yazmalarda açılır
     * @return bool Commit atıldı mı (fark boşsa false)
     *
     * @throws NetconfException
     */
    public function apply(
        NetconfSession $session,
        NetconfTarget $target,
        string $document,
        string $summary,
        bool $geriAlinabilir,
    ): bool {
        $log = htmlspecialchars($summary, ENT_XML1 | ENT_QUOTES);

        $session->rpc('<lock-configuration/>');

        try {
            $this->guardPendingChanges($session);

            try {
                try {
                    $session->rpc(sprintf('<load-configuration action="merge" format="xml">%s</load-configuration>', $document));
                } catch (NetconfException $e) {
                    throw $e->withStage('load');
                }

                if ($this->diff($session) === '') {
                    return false;
                }

                /*
                 * Ayrı bir "commit check" YAPILMAZ: commit confirmed aynı
                 * doğrulamayı zaten çalıştırır ve başarısızsa hiçbir şey commit
                 * edilmez. İkincisi cihazın en pahalı adımını ikiye katlardı.
                 */
                try {
                    $session->rpc($geriAlinabilir
                        ? sprintf(
                            '<commit-configuration><confirmed/><confirm-timeout>%d</confirm-timeout><log>%s</log></commit-configuration>',
                            $this->confirmMinutes,
                            $log,
                        )
                        : "<commit-configuration><log>{$log}</log></commit-configuration>");
                } catch (NetconfException $e) {
                    throw $e->withStage('commit');
                }
            } catch (Throwable $e) {
                /*
                 * Yarım yüklenmiş aday ATILIR. Paylaşılan aday oturumla
                 * birlikte gitmez; kalsaydı bir sonraki yazma "bekleyen
                 * değişiklik var" diye reddedilirdi.
                 */
                $this->discard($session);

                throw $e;
            }
        } finally {
            $this->unlock($session);
        }

        if ($geriAlinabilir) {
            $this->confirm($target, $log);
        }

        return true;
    }

    /**
     * Geri alınabilir commit'i YENİ bir oturumda onaylar.
     *
     * Hata yukarı bırakılır: onay verilmezse cihaz confirm-timeout dolunca
     * kendini eski hâline döndürür ve çağıran bunu bir başarısızlık olarak görür.
     */
    private function confirm(NetconfTarget $target, string $log): void
    {
        $this->client->runOn($target, function (NetconfSession $session) use ($log): void {
            $session->rpc('<lock-configuration/>');

            try {
                $session->rpc("<commit-configuration><log>{$log}</log></commit-configuration>");
            } catch (NetconfException $e) {
                throw $e->withStage('confirm');
            } finally {
                $this->unlock($session);
            }
        });
    }

    /** Adayı yayındaki yapılandırmaya döndürür (load rollback 0); en iyi çaba. */
    private function discard(NetconfSession $session): void
    {
        try {
            $session->rpc('<load-configuration rollback="0"/>');
        } catch (Throwable) {
            /* Asıl hata önemli olan; temizlik başarısızsa onu örtmeyelim. */
        }
    }

    /**
     * Kilidi bırakır; bırakılamazsa ASIL hatayı örtmemek için susar. Kilit
     * oturum kapanınca zaten düşer.
     */
    private function unlock(NetconfSession $session): void
    {
        try {
            $session->rpc('<unlock-configuration/>');
        } catch (Throwable) {
            /* Oturum kapanışı kilidi bırakır. */
        }
    }

    /**
     * Paylaşılan adayda BAŞKASININ bekleyen değişikliği var mı? Varsa commit
     * onu da yayına alırdı; panelin görmediği bir yapılandırma çıkardı.
     */
    private function guardPendingChanges(NetconfSession $session): void
    {
        if ($this->diff($session) === '') {
            return;
        }

        throw NetconfException::transport(
            'Cihazda kaydedilmemiş bir yapılandırma değişikliği duruyor; commit onu da yayına alırdı. '
            .'CLI’da "rollback" ya da "commit" ile temizlendikten sonra yeniden deneyin.',
            'candidate-dirty',
        );
    }

    private function diff(NetconfSession $session): string
    {
        $reply = $session->rpc('<get-configuration compare="rollback" rollback="0" format="text"/>');

        return trim((string) ($reply->xpath('//*[local-name()="configuration-output"]')[0] ?? ''));
    }
}
