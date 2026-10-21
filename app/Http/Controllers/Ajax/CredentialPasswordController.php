<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Services\ActivityRecorder;
use App\Support\RequestLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Parolayı ekranda gösteren TEK uç.
 *
 * Panelin genel kuralı parolayı hiçbir ekranda basmamaktır; burası bilinçli
 * bir istisnadır ve bedeli şudur:
 *
 *  - Ayrı bir yetki ister ("reveal"), grup başına verilir. "Görüntüle" yetkisi
 *    bunu kapsamaz.
 *  - HER gösterim kayda geçer; kaydı silen bir ekran yoktur.
 *  - Adres GET değil POST'tur: tarayıcı geçmişine, sunucu erişim kaydına ve
 *    bağlantı ön yüklemesine takılmasın.
 *  - Yanıt önbelleğe alınmaz ve tek kayıt için istenir; listede toplu basılmaz.
 *  - Dakikada 20 istekle sınırlıdır: yetkili bir hesap ele geçse bile kasa
 *    tek seferde boşaltılamasın.
 */
class CredentialPasswordController extends Controller implements HasMiddleware
{
    public function __construct(private ActivityRecorder $activity) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:reveal,credential'),
            ...RequestLimit::icin('reveal'),
        ];
    }

    public function __invoke(Credential $credential): JsonResponse
    {
        abort_if(blank($credential->password), 404);

        $credential->loadMissing('group');

        $this->activity->record(
            ActivityAction::Revealed,
            "{$credential->group->name} · {$credential->name} parolası görüntülendi",
            $credential,
        );

        return response()
            ->json([
                'username' => $credential->username,
                'password' => $credential->password,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
