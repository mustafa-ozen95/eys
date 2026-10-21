<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Support\ReasonRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toplu parola çıktısı isteği.
 *
 * KAPSAM SATIR SEÇİMİYLE DEĞİL, EKRANDAKİ LİSTEYLE belirlenir.
 *
 * Listedeki onay kutuları toplu döndürmeye ait ve döndürülemeyen kayıtlarda
 * KAPALI duruyor (sürücüsü yok, parolası boş...). Aynı kutuyu çıktıya da
 * bağlamak, "çıktı alınamıyor" diye okunacak bir kapalı kutu üretirdi —
 * oysa o kayıtların parolası pekâlâ dışarı çıkabilir. Ekrandaki süzgeç ise
 * kullanıcının zaten kurduğu, gözüyle gördüğü kümedir.
 *
 * Yetki kapıda bir kez (exportAny), sonra HER KAYIT için ayrı sorulur
 * (bkz. PasswordOutput).
 */
class PasswordExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('exportAny', Credential::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kapsam' => ['required', 'in:liste,tumu'],

            /* Süzgeç yalnızca "liste" kapsamında anlamlı; "tümü" hiçbirine bakmaz. */
            'grup' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:190'],
            'platform' => ['nullable', 'string', 'max:64'],

            /* TOPLU İŞLEM: gerekçe kayıttan bağımsız, HER ZAMAN istenir
               (bkz. ReasonRule::toplu). */
            'reason' => ReasonRule::toplu(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'gerekçe', 'kapsam' => 'kapsam'];
    }

    public function allScope(): bool
    {
        return $this->string('kapsam')->value() === 'tumu';
    }

    /** Çıktının başlığında duracak kapsam adı. */
    public function kapsamAdi(): string
    {
        if ($this->allScope()) {
            return 'Kasanın tamamı';
        }

        $grup = $this->grup();

        return $grup?->name ?? 'Ekrandaki liste';
    }

    private function grup(): ?CredentialGroup
    {
        $uuid = $this->string('grup')->value();

        return $uuid === '' ? null : CredentialGroup::query()
            ->otomasyon()->visibleTo($this->user())
            ->where('uuid', $uuid)
            ->first();
    }

    /** @return Collection<int, Credential> */
    public function credentials(): Collection
    {
        $sorgu = Credential::query()->otomasyon()->visibleTo($this->user())->with('group');

        if (! $this->allScope()) {
            $grup = $this->grup();

            $sorgu
                ->when($grup !== null, fn ($q) => $q->whereBelongsTo($grup, 'group'))
                ->search($this->string('q')->squish()->value() ?: null, $this->user())
                ->ofPlatform($this->string('platform')->value() ?: null);
        }

        return $sorgu->ordered()->get();
    }
}
