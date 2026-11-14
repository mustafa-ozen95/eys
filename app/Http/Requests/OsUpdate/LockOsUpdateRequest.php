<?php

declare(strict_types=1);

namespace App\Http\Requests\OsUpdate;

use App\Support\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Güncelleme kilidini koyma / kaldırma. Gerekçe her iki yönde de zorunlu:
 * kilidi kaldırmak kritik sunucuyu kuruluma açmaktır.
 */
class LockOsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('osUpdateLock', $this->route('credential'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kilit' => ['required', 'boolean'],
            'reason' => ReasonRule::toplu(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['kilit' => 'kilit', 'reason' => 'gerekçe'];
    }
}
