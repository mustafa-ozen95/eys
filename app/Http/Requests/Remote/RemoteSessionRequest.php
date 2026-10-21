<?php

declare(strict_types=1);

namespace App\Http\Requests\Remote;

use App\Models\Credential;
use App\Support\GerekceKurali;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Konsol oturumu isteği.
 *
 * Hedef uuid ile gelir; sıralı birincil anahtar dışarı çıkmaz.
 */
class RemoteSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],

            /*
             * GEREKÇE KRİTİK KAYITLARDA ZORUNLU (bkz. GerekceKurali).
             *
             * Konsol açmak her hâlükârda gerekçesiyle ya da gerekçesiz kayda
             * geçer; soru yalnızca işaretli kayıtlarda sorulur. Her konsol
             * açılışında "neden" istemek, kutuya "bakım" yazdırmaktan başka
             * bir şey üretmiyordu.
             */
            'reason' => GerekceKurali::kayit($this->hedef()),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'gerekçe'];
    }

    public function credential(): Credential
    {
        return Credential::query()->where('uuid', $this->validated('id'))->firstOrFail();
    }

    /**
     * Gerekçe kuralını kuracak kayıt — DOĞRULAMADAN ÖNCE aranır.
     *
     * Arama kullanıcının GÖREBİLDİĞİ kayıtlarla sınırlı: kural kaydın
     * durumuna göre değiştiği için, sınırsız arama "bu uuid var mı" sorusunu
     * doğrulama hatasıyla yanıtlayan bir uç yaratırdı. Bulunamazsa gerekçe
     * zorunlu olmaz; isteği zaten denetleyicideki yetki kontrolü düşürür.
     */
    private function hedef(): ?Credential
    {
        $id = $this->input('id');

        if (! is_string($id) || ! Str::isUuid($id) || $this->user() === null) {
            return null;
        }

        return Credential::query()->visibleTo($this->user())->where('uuid', $id)->first();
    }
}
