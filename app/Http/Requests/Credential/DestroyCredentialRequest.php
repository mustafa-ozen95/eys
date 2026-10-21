<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

use App\Support\GerekceKurali;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Silme gerekçesi SUNUCUDA aranır.
 *
 * Onay penceresi gerekçeyi forma ekliyor ama pencere tarayıcıda; isteği elle
 * kuran gerekçesiz silebilirdi. Denetimde sorulan soru "kim sildi" kadar
 * "neden sildi"dir de.
 *
 * ARTIK YALNIZCA KRİTİK KAYITLARDA zorunlu (bkz. GerekceKurali): her kayıtta
 * sorulan gerekçe "test" yazılarak geçiliyordu. "Kim sildi" her zaman kayda
 * geçer; değişen yalnızca "neden" sorusunun sorulup sorulmadığı.
 */
class DestroyCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('credential'));
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
