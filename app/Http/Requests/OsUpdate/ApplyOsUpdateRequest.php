<?php

declare(strict_types=1);

namespace App\Http\Requests\OsUpdate;

use App\Models\Credential;
use App\Services\OsUpdate\OsUpdateScripts;
use App\Services\OsUpdate\OsUpdateTargets;
use App\Support\ReasonRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Seçilen makinelere sistem güncellemesi kurma isteği.
 *
 * Gerekçe HER ZAMAN zorunlu: kurulum cihazı değiştirir ve tek istek elli
 * makineye dokunabilir. Kayıt başına yetki ve kilit OsUpdateLauncher'da.
 */
class ApplyOsUpdateRequest extends FormRequest
{
    public const LIMIT = 50;

    public function authorize(): bool
    {
        return $this->user()->can('osUpdateAny', Credential::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kayitlar' => ['required', 'array', 'min:1', 'max:'.self::LIMIT],
            'kayitlar.*' => ['uuid'],
            'mod' => ['required', Rule::in(OsUpdateScripts::MODLAR)],
            'reason' => ReasonRule::toplu(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['kayitlar' => 'makine listesi', 'mod' => 'kurulum türü', 'reason' => 'gerekçe'];
    }

    /** @return Collection<int, Credential> */
    public function credentials(): Collection
    {
        return app(OsUpdateTargets::class)
            ->kullaniciIcin($this->user())
            ->whereIn('uuid', $this->array('kayitlar'))
            ->with(['group', 'altGrup', 'osUpdateState'])
            ->ordered()
            ->get();
    }
}
