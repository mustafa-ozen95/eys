<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CredentialApiResource;
use App\Models\Credential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Kasadaki kayıtların okunması.
 *
 * PAROLA BURADAN DÖNMEZ; ayrı bir uç ve ayrı bir yetki ister
 * (bkz. CredentialSecretApiController). Listede dönseydi, yalnızca "hangi
 * kayıtlar var" sorusunu soran bir istemci de parolayı almış olurdu.
 *
 * Yetkilendirme: `auth:api` + policy. Jeton sahibi adına konuşur ve onu
 * AŞAMAZ — grup bazlı istisnalar aynen geçerli, yani jeton sahibinin
 * göremediği grubu okuyamaz.
 */
class CredentialApiController extends Controller implements HasMiddleware
{
    private const SAYFA = 50;

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        /*
         * show DA kapıdan geçer. Yalnızca index'e bağlıydı: "view" yeteneği
         * olmayan bir jeton (örneğin yalnız 'rotate') listeden 403 alıyor
         * ama tek kaydı uuid ile okuyabiliyordu. viewAny, jetonda 'view'
         * olarak sorulur (bkz. TokenAbilityGate).
         */
        return [new Middleware('can:viewAny,'.Credential::class, only: ['index', 'show'])];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $veri = $request->validate([
            'host' => ['sometimes', 'string', 'max:255'],
            'kullanici' => ['sometimes', 'string', 'max:255'],
            'grup' => ['sometimes', 'string', 'max:64'],
        ]);

        $sorgu = Credential::with(['group', 'altGrup'])
            ->otomasyon()
            ->visibleTo($request->user())
            ->orderBy('name');

        /*
         * Süzgeçler ADRES ve KULLANICI ADI üzerinden: çağıran istemci kendi
         * envanterindeki sunucuyu bu ikisiyle tanıyor, kasadaki uuid'yi
         * bilmiyor.
         */
        if (isset($veri['host'])) {
            $sorgu->where('host', $veri['host']);
        }

        if (isset($veri['kullanici'])) {
            $sorgu->where('username', $veri['kullanici']);
        }

        if (isset($veri['grup'])) {
            $sorgu->whereHas('group', fn ($q) => $q->where('uuid', $veri['grup']));
        }

        return CredentialApiResource::collection($sorgu->paginate(self::SAYFA));
    }

    /**
     * GÖREMEDİĞİ KAYIT "YOK"TUR, "yasak" değil.
     *
     * Örtük rota bağlaması kaydı önce bulur, policy sonra 403 döndürürdü ve
     * bu 403, "bu uuid var" demenin başka bir yolu olurdu. Kayıt görünür
     * kümenin İÇİNDEN çözülür; dışarıdaki her uuid 404 alır.
     *
     * Süzme çözümleyiciden geçer (bkz. scopeVisibleTo): ayrı bir kontrol
     * yazsaydık API ile ekran farklı yanıt verebilirdi.
     */
    public function show(Request $request, string $credential): CredentialApiResource
    {
        return new CredentialApiResource($this->bul($request, $credential));
    }

    private function bul(Request $request, string $uuid): Credential
    {
        return Credential::with(['group', 'altGrup'])
            ->visibleTo($request->user())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
