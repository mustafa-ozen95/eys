<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Services\ActivityRecorder;
use App\Services\Api\ApiTokenContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kasadaki bir kaydın parolası.
 *
 * AYRI UÇ, AYRI YETKİ, AYRI DENETİM SATIRI. Liste ucunda parola dönseydi,
 * yalnızca "hangi kayıtlar var" sorusunu soran bir istemci de parolayı almış
 * olurdu; üstelik kimin hangi parolayı okuduğu listelemenin içinde kaybolurdu.
 *
 * NEDEN VAR: aynı sunucunun parolası iki panelde birden duruyordu ve kasa
 * parolayı döndürünce diğerindeki kopya sessizce eskiyordu. Kopyanın kendi
 * kendini tazeleyebilmesi için kasanın okunabilir olması gerekiyor.
 *
 * Yetkilendirme: `auth:api` + `reveal` policy'si; kayıt önce GÖRÜNÜR kümeden
 * çözülür (IDOR). "Görüntüle" yetkisi bunu
 * KAPSAMAZ — grubu gören listeyi görür, parolayı yalnızca o grupta
 * 'field.password' verilmiş olan açar. Jeton da aynı yetkiyi taşımak zorunda.
 */
class CredentialSecretApiController extends Controller
{
    public function __invoke(
        Request $request,
        string $credential,
        ActivityRecorder $activity,
        ApiTokenContext $jeton,
    ): JsonResponse {
        /*
         * ÖNCE GÖRÜNÜRLÜK, SONRA PAROLA YETKİSİ.
         *
         * Kayıt görünür kümenin içinden çözülür: göremediği bir uuid 404
         * alır, çünkü 403 "bu uuid var" demenin başka bir yolu olurdu.
         * Görebildiği ama parolasını göremediği kayıtta ise 403 doğrudur —
         * varlığını zaten biliyor.
         */
        $credential = Credential::with('group')
            ->otomasyon()
            ->visibleTo($request->user())
            ->where('uuid', $credential)
            ->firstOrFail();

        abort_unless($request->user()->can('reveal', $credential), 403);

        /*
         * HER OKUMA KAYDA GEÇER ve hangi jetonla okunduğu da yazılır.
         *
         * Panelde parola görüntülemek zaten kayda geçiyor; API'nin bundan muaf
         * olması, kasanın en sessiz kapısını açık bırakmak olurdu.
         */
        $activity->record(
            ActivityAction::Revealed,
            "{$credential->group?->name} · {$credential->name} · API",
            $credential,
            'API jetonu: '.($jeton->get()?->name ?? 'bilinmiyor'),
        );

        return response()->json([
            'kimlik' => $credential->uuid,
            'kullanici' => $credential->username,
            'parola' => $credential->password,

            /*
             * Kasadaki son değişiklik zamanı: çağıran istemci kendi
             * kopyasının eskiyip eskimediğini buna bakarak anlayabilir.
             */
            'guncellendi' => $credential->updated_at?->toIso8601String(),
        ]);
    }
}
