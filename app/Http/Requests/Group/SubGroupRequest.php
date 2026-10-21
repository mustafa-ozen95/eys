<?php

declare(strict_types=1);

namespace App\Http\Requests\Group;

use App\Models\SubGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alt grup ekleme ve düzenleme — aynı kurallar, tek sınıf.
 *
 * Yetki grubunkiyle aynı: grubu düzenleyebilen bölmelerini de düzenler
 * (yalnızca yönetici, bkz. CredentialGroupPolicy). Ayrı bir politika,
 * aynı kararı ikinci bir yerde yazmak olurdu.
 */
class SubGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $group = $this->route('group');
        $altGrup = $this->route('altGrup');

        return [
            /*
             * Alan adı grup formundan FARKLI (alt_ad): aynı sayfada
             * iki form var ve doğrulama hatasında eski girdi ada göre geri
             * doluyor — "name" deseydik alt grup adı grubun ad kutusuna
             * yazılırdı.
             */
            'alt_ad' => [
                'required',
                'string',
                'max:120',
                /* Ad grubun İÇİNDE tekil: iki grupta aynı bölme adı olabilir (ikisinde de "Yedek"). */
                Rule::unique(SubGroup::class, 'name')
                    ->where('credential_group_id', $group->getKey())
                    ->ignore($altGrup),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'alt_ad' => 'alt grup adı',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'name' => $this->string('alt_ad')->squish()->value(),
        ];
    }
}
