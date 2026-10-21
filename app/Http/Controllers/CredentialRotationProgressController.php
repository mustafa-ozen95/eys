<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RotationStage;
use App\Models\Credential;
use App\Services\Rotation\DriverRegistry;
use App\Services\Rotation\RotationLauncher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * SÜREN bir turun nerede olduğunu söyler — ekrandaki çubuğu besler.
 *
 * NEDEN AYRI BİR UÇ: tur kuyruğa alınıyor ve cihaza göre birkaç saniyeden
 * yarım dakikaya kadar sürüyor. Ekran o süre boyunca "güncelleme kuyruğa
 * alındı" deyip sussaydı kullanıcı işin sürüp sürmediğini ancak sayfayı
 * yenileyerek anlar, çoğu zaman düğmeye ikinci kez basardı.
 *
 * Uç yalnızca AŞAMA döndürür: parola, kurtarma parolası ve denenen parola
 * modelde $hidden'dır ve buraya hiç uğramaz.
 */
class CredentialRotationProgressController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        /* Görme yetkisi yetmez: tur ayrıntısı döndürme yetkisine bağlıdır. */
        return [new Middleware('can:rotate,credential')];
    }

    public function __invoke(Request $request, Credential $credential, DriverRegistry $surucular): JsonResponse
    {
        $rotation = $credential->rotations()->latestFirst()->first();

        /*
         * SIRAYA ALINDI ama işçi henüz başlamadı: tur kaydı yok, iş var.
         * Kayıt, izden SONRA açıldıysa iş başlamış demektir ve iz düşer.
         */
        if (RotationLauncher::waiting($credential, $rotation)) {
            return response()->json([
                'calisiyor' => true,
                'yuzde' => 4,
                'adim' => 0,
                'toplam' => $surucular->adimSayisi($credential),
                'asama' => null,
                'metin' => 'Sıraya alındı, işçi bekleniyor…',
                'durum' => 'queued',
                'durum_metni' => 'Sırada',
                'mesaj' => null,
            ]);
        }

        if ($rotation === null) {
            return response()->json(['calisiyor' => false]);
        }

        RotationLauncher::forgetPending($credential);

        $stage = $rotation->stage ?? RotationStage::Started;
        $calisiyor = $rotation->status?->value === 'running';

        return response()->json([
            'calisiyor' => $calisiyor,
            'yuzde' => $rotation->progress(),
            'adim' => $rotation->completedSteps(),
            'toplam' => $rotation->totalSteps(),
            'asama' => $stage->value,
            'metin' => $calisiyor ? $stage->running() : $stage->label(),
            'durum' => $rotation->status?->value,
            'durum_metni' => $rotation->status?->label(),
            'mesaj' => $rotation->message,
        ]);
    }
}
