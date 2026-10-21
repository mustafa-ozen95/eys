<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityAction;
use App\Models\ConsoleSession;
use App\Services\ActivityRecorder;
use App\Services\DeviceInterface\InterfaceSession;
use App\Services\Remote\TtydManager;
use Illuminate\Console\Command;

/**
 * Sesi kesilen konsol oturumlarını kapatır.
 *
 * KAPANIŞ HABERİ HER ZAMAN GELMEZ. Kullanıcı düğmeye basarsa, pencereyi
 * kapatırsa ya da sekmeden çıkarsa tarayıcı haber veriyor; ama tarayıcı
 * çökerse, makine uykuya girerse ya da ağ koparsa kimse haber veremez.
 * O durumda denetim kaydında "açıldı" satırı olur, "kapandı" satırı olmaz —
 * yani bir oturumun ne zaman bittiği kayıtta HİÇ yazmaz.
 *
 * Bu komut, canlı tutma isteği kesileli belli süre geçmiş oturumları
 * kapanmış sayar: ttyd süreci varsa sonlandırılır, iz kapatılır ve kayda
 * "bağlantı düştü" olarak geçer.
 *
 * ttyd zaten `--once` ile başlatılıyor, yani tarayıcı düşünce süreç kendi
 * kapanır; burada asıl yapılan İZİ KAPATMAK, süreci öldürmek değil.
 */
class ConsoleCleanCommand extends Command
{
    protected $signature = 'erisim:konsol-temizle';

    protected $description = 'Sesi kesilen konsol oturumlarını kapatır ve kayda geçirir';

    public function handle(TtydManager $ttyd, ActivityRecorder $recorder, InterfaceSession $arayuzler): int
    {
        /*
         * Sınır: canlı tutma aralığının birkaç katı. Tarayıcı dört dakikada
         * bir ses veriyor; iki tur kaçırmak ağ kesintisi olabilir, üç tur
         * kaçırmak gitmiş olmaktır.
         */
        $sinir = (int) config('remote.session.ttl') + 120;

        $oturumlar = ConsoleSession::query()->sessizKalan($sinir)->get();

        $cihazda = 0;

        foreach ($oturumlar as $oturum) {
            if ($oturum->port !== null) {
                $ttyd->stop($oturum->port);
            }

            /*
             * CİHAZDA DA ÇIKIŞ YAPILIR. Sunucu tarafında giriş yapılan
             * arayüzlerde (Plesk, iLO, vSphere) oturumu panel açıyor; kapatan
             * olmazsa cihaz kendi zaman aşımını bekliyor ve o süre boyunca
             * listede duruyor. Art arda birkaç açılışta cihazın oturum listesi
             * dolar (iLO'da oturum yuvaları sınırlıdır). Kapanışın üç işi de
             * tek yerde: bkz. InterfaceSession::kapat.
             */
            $cihazda += (int) $arayuzler->kapat($oturum, 'dustu');

            $recorder->record(
                ActivityAction::ConsoleClosed,
                "Konsol kapandı (bağlantı düştü): {$oturum->label} ({$oturum->protocol->value})",
                $oturum->credential,
                actor: $oturum->user,
            );
        }

        $this->info($oturumlar->count().' düşen oturum kapatıldı; '.$cihazda.' tanesinde cihazda da çıkış yapıldı.');

        return self::SUCCESS;
    }
}
