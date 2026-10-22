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
                'running' => true,
                'percent' => 4,
                'step' => 0,
                'total' => $surucular->adimSayisi($credential),
                'stage' => null,
                'text' => 'Sıraya alındı, işçi bekleniyor…',
                'status' => 'queued',
                'status_text' => 'Sırada',
                'message' => null,
            ]);
        }

        if ($rotation === null) {
            return response()->json(['running' => false]);
        }

        RotationLauncher::forgetPending($credential);

        $stage = $rotation->stage ?? RotationStage::Started;
        $running = $rotation->status?->value === 'running';

        return response()->json([
            'running' => $running,
            'percent' => $rotation->progress(),
            'step' => $rotation->completedSteps(),
            'total' => $rotation->totalSteps(),
            'stage' => $stage->value,
            'text' => $running ? $stage->running() : $stage->label(),
            'status' => $rotation->status?->value,
            'status_text' => $rotation->status?->label(),
            'message' => $rotation->message,
        ]);
    }
}
