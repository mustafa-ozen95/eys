<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

use App\Models\Credential;
use App\Support\GerekceKurali;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toplu döndürme isteği.
 *
 * Gerekçe SUNUCUDA aranır (onay penceresi tarayıcıda; istek elle kurulabilir).
 * Kayıtlar uuid ile gelir ve KULLANICININ GÖREBİLDİKLERİYLE sınırlanır:
 * göremediği bir kaydın uuid'sini forma yazan biri onu döndüremesin. Yetki
 * kontrolü burada bitmez — her kayıt ayrıca RotationLauncher'da sorgulanır.
 */
class BulkRotateCredentialRequest extends FormRequest
{
    /** Tek istekte döndürülebilecek en fazla kayıt. */
    public const LIMIT = 50;

    public function authorize(): bool
    {
        return $this->user()->can('rotateAny', Credential::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kayitlar' => ['required', 'array', 'min:1', 'max:'.self::LIMIT],
            'kayitlar.*' => ['uuid'],
            /* TOPLU İŞLEM: gerekçe kayıttan bağımsız, HER ZAMAN istenir
               (bkz. GerekceKurali::toplu). */
            'reason' => GerekceKurali::toplu(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['kayitlar' => 'kayıt listesi', 'reason' => 'gerekçe'];
    }

    /**
     * Döndürülecek kayıtlar.
     *
     * KULLANICININ GÖREBİLDİKLERİYLE sınırlıdır. "Tüm kasayı güncelle"
     * seçeneği yok; yalnız seçilen kayıtlar döndürülür.
     * Yetki her kayıt için ayrıca RotationLauncher'da sorulur.
     *
     * @return Collection<int, Credential>
     */
    public function credentials(): Collection
    {
        return Credential::query()
            ->otomasyon()
            ->visibleTo($this->user())
            ->whereIn('uuid', $this->array('kayitlar'))
            ->with('group')
            ->ordered()
            ->get();
    }
}
