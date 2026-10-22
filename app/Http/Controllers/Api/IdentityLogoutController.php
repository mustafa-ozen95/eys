<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use App\Services\Identity\CenterClient;
use App\Services\Identity\SharedLogout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MERKEZDEN "bu kişi çıkış yaptı" bildirimi (bkz. SharedLogout).
 *
 * İmza anı da kapsar (`logout|<subject>|<time>`); tutarsa zaman doğrudan
 * uygulanır, merkeze yeniden sorulmaz. Kimlik bildiriminden ayrı uç: o uç
 * kişi başına kısılıyor, çıkış kısılmamalı. Yanıt her durumda aynı.
 */
class IdentityLogoutController extends Controller
{
    public function __invoke(Request $request, CenterClient $merkez): JsonResponse
    {
        $subject = trim((string) $request->input('subject'));
        $an = filter_var($request->input('time'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sir = (string) config('identity.notify_secret');

        $imzali = $sir !== '' && $subject !== '' && $an !== false && hash_equals(
            hash_hmac('sha256', 'logout|'.$subject.'|'.$an, $sir),
            (string) $request->header('X-Identity-Signature'),
        );

        if ($imzali && $merkez->acikMi()) {
            $bag = UserIdentity::query()
                ->where('provider', AccountMatcher::SAGLAYICI)
                ->where('subject', $subject)
                ->first();

            if ($bag !== null) {
                SharedLogout::isle($bag, $an);
            }
        }

        return response()->json(['result' => 'received']);
    }
}
