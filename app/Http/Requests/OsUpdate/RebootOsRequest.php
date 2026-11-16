<?php

declare(strict_types=1);

namespace App\Http\Requests\OsUpdate;

use App\Support\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Makineyi yeniden başlatma. Gerekçe zorunlu: makine bir süre kapanır.
 */
class RebootOsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('osReboot', $this->route('credential'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ReasonRule::toplu(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'gerekçe'];
    }
}
