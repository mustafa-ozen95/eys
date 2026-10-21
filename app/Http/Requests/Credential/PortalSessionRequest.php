<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

use App\Support\GerekceKurali;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Portal oturumu gerekçesi SUNUCUDA aranır.
 *
 * Onay penceresi gerekçeyi forma ekliyor ama pencere tarayıcıda; isteği elle
 * kuran gerekçesiz oturum açabilirdi. "Bu portal hesabını kim, ne zaman,
 * neden kullandı" sorusunun cevabı bu alandır.
 *
 * SORU ARTIK YALNIZCA KRİTİK KAYITLARDA sorulur (bkz. GerekceKurali) —
 * kritik işaretli bir portal hesabı sıradan bir web arayüzü değildir.
 * Oturumun kendisi her iki durumda da kayda geçer.
 */
class PortalSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('session', $this->route('credential'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => GerekceKurali::kayit($this->route('credential')),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'gerekçe'];
    }
}
