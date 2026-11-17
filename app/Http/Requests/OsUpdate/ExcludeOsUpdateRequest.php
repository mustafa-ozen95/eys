<?php

declare(strict_types=1);

namespace App\Http\Requests\OsUpdate;

use App\Support\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Kaydı güncellemeden hariç tutma / geri alma. Kilitle aynı yetki
 * ('os_update_lock'): ikisi de "bu makineye kurulum yapılmasın" kararıdır,
 * biri makine, biri kayıt kapsamında. Gerekçe her iki yönde zorunlu.
 */
class ExcludeOsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('osUpdateLock', $this->route('credential'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'haric' => ['required', 'boolean'],
            'reason' => ReasonRule::toplu(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['haric' => 'hariç', 'reason' => 'gerekçe'];
    }
}
