<?php

declare(strict_types=1);

namespace App\Services\Update;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Güncellemeyi BAŞLATIR: kurulumun platformundaki deploy hook'unu
 * (UPDATE_HOOK_URL, ör. NineDeploy'un /v1/deploy-hooks/<jeton>) çağırır.
 *
 * Panel kendini KURMAZ: hook yalnızca platformdaki servisin bir sonraki
 * deploy'unu sıraya koyar; ne kurulacağına (deponun main dalı) ve nasıl
 * kurulacağına platform karar verir. Ele geçen bir panel bununla sunucuda
 * komut çalıştıramaz, en çok aynı depoyu yeniden kurdurur.
 *
 * Yalnız son kontrol YENİ SÜRÜM bulduysa başlatır (UpdateCheck::yeniVar):
 * düğme ekranda gizli olsa da istek elle gönderilebilir, karar sunucuda.
 *
 * Hook adresi sır gibi saklanır: içindeki jeton deploy yetkisidir; ekranda,
 * iletide ve kayıtta geçmez.
 */
class UpdateApply
{
    public function __construct(private UpdateCheck $denetim) {}

    public function hazir(): bool
    {
        return $this->hook() !== '';
    }

    /** Düğme görünsün mü: hook tanımlı ve yeni sürüm var. */
    public function mumkun(): bool
    {
        return $this->hazir() && $this->denetim->yeniVar();
    }

    /**
     * @return string platformun verdiği deploy numarası ya da kısa açıklama
     *
     * @throws RuntimeException güncellenecek bir şey yoksa ya da platform reddederse
     */
    public function baslat(): string
    {
        if (! $this->hazir()) {
            throw new RuntimeException('Güncelleme bağlantısı (UPDATE_HOOK_URL) tanımlı değil.');
        }

        if (! $this->denetim->yeniVar()) {
            throw new RuntimeException('Kurulacak yeni sürüm yok; önce yeniden kontrol edin.');
        }

        try {
            $yanit = Http::acceptJson()->timeout(15)->post($this->hook());
        } catch (ConnectionException) {
            throw new RuntimeException('Platforma ulaşılamadı (deploy hook yanıt vermedi).');
        }

        if (! $yanit->successful()) {
            throw new RuntimeException('Platform isteği reddetti ('.$yanit->status().'): hook kapatılmış ya da yenilenmiş olabilir.');
        }

        $no = $yanit->json('deploymentId');

        return match (true) {
            (bool) $yanit->json('alreadyInProgress') => 'zaten süren bir deploy var (#'.$no.')',
            $no !== null => 'deploy #'.$no,
            default => 'deploy sıraya alındı',
        };
    }

    private function hook(): string
    {
        return trim((string) config('update.hook'));
    }
}
