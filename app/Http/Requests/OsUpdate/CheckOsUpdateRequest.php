<?php

declare(strict_types=1);

namespace App\Http\Requests\OsUpdate;

use App\Models\Credential;
use App\Services\OsUpdate\OsUpdateTargets;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bekleyen güncellemeleri şimdi denetleme isteği. Yalnızca okur.
 *
 * Kayıt listesi boşsa kullanıcının denetleyebildiği bütün makineler.
 * "Seçilenleri denetle" `secilen=1` gönderir: o yoldan boş liste gelirse
 * (betik seçimi taşıyamadıysa) hepsi denetlenmez, istek reddedilir.
 */
class CheckOsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('osUpdateAny', Credential::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'secilen' => ['sometimes', 'boolean'],
            'kayitlar' => ['required_if_accepted:secilen', 'array', 'max:500'],
            'kayitlar.*' => ['uuid'],
        ];
    }

    /** @return Collection<int, Credential> */
    public function credentials(): Collection
    {
        return app(OsUpdateTargets::class)
            ->kullaniciIcin($this->user())
            ->when($this->filled('kayitlar'), fn ($q) => $q->whereIn('uuid', $this->array('kayitlar')))
            ->with('group')
            ->ordered()
            ->get();
    }
}
