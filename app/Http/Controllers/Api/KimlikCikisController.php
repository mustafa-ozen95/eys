<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserIdentity;
use App\Services\Kimlik\HesapEslestirici;
use App\Services\Kimlik\MerkezIstemcisi;
use App\Services\Kimlik\OrtakCikis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MERKEZDEN "bu kişi çıkış yaptı" bildirimi (bkz. OrtakCikis).
 *
 * İmza anı da kapsar (`cikis|<subject>|<an>`); tutarsa an doğrudan
 * uygulanır, merkeze yeniden sorulmaz. Kimlik bildiriminden ayrı uç: o uç
 * kişi başına kısılıyor, çıkış kısılmamalı. Yanıt her durumda aynı.
 */
class KimlikCikisController extends Controller
{
    public function __invoke(Request $request, MerkezIstemcisi $merkez): JsonResponse
    {
        $subject = trim((string) $request->input('subject'));
        $an = filter_var($request->input('an'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sir = (string) config('kimlik.bildirim_sirri');

        $imzali = $sir !== '' && $subject !== '' && $an !== false && hash_equals(
            hash_hmac('sha256', 'cikis|'.$subject.'|'.$an, $sir),
            (string) $request->header('X-Kimlik-Imza'),
        );

        if ($imzali && $merkez->acikMi()) {
            $bag = UserIdentity::query()
                ->where('provider', HesapEslestirici::SAGLAYICI)
                ->where('subject', $subject)
                ->first();

            if ($bag !== null) {
                OrtakCikis::isle($bag, $an);
            }
        }

        return response()->json(['sonuc' => 'alındı']);
    }
}
