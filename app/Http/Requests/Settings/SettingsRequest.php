<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\Setting;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ayar formu.
 *
 * Kurallar KATALOGDAN gelir; forma elle eklenmiş bir alan doğrulamadan
 * geçemez ve kaydedilmez. Kuralları burada tekrar yazmak, katalogla
 * ayrışacak ikinci bir doğruluk kaynağı yaratırdı.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Setting::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $catalog = app(SettingsCatalog::class);
        $rules = [];

        foreach ($catalog->definitionsFor($this->group()) as $definition) {
            /*
             * Boole alanı işaretlenmediğinde tarayıcı HİÇ göndermez; kural
             * 'required' olsaydı kutuyu kapatmak imkânsız olurdu.
             */
            $rules[$definition->field()] = $definition->type === 'bool'
                ? ['nullable', ...$definition->rules]
                : ['required', ...$definition->rules];
        }

        return $rules;
    }

    public function group(): string
    {
        $catalog = app(SettingsCatalog::class);
        $group = $this->string('group')->value();

        return in_array($group, $catalog->groupKeys(), true) ? $group : $catalog->groupKeys()[0];
    }

    /**
     * Form alanlarını ayar anahtarlarına geri çevirir.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $values = [];

        foreach (app(SettingsCatalog::class)->definitionsFor($this->group()) as $definition) {
            $values[$definition->key] = $definition->type === 'bool'
                ? $this->boolean($definition->field())
                : $this->input($definition->field());
        }

        return $values;
    }
}
