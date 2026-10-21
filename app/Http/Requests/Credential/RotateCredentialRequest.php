<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

use App\Support\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Döndürme gerekçesi SUNUCUDA aranır.
 *
 * Onay penceresi gerekçeyi forma ekliyor ama pencere tarayıcıda; isteği elle
 * kuran gerekçesiz döndürebilirdi.
 *
 * ZORUNLULUK KAYDA BAĞLI (bkz. ReasonRule). Tur yine her hâlükârda kayda
 * geçer ve yazılı onay (adres yazdırma) kritik olmayan kayıtta da durur;
 * kalkan yalnızca "neden" kutusudur.
 */
class RotateCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rotate', $this->route('credential'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ReasonRule::kayit($this->route('credential')),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'gerekçe'];
    }
}
