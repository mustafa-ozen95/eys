<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Credential;
use App\Policies\CredentialPolicy;
use App\Services\Api\ApiTokenContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kasadaki bir kaydın API görünümü.
 *
 * PAROLA YOK ve olmayacak: ayrı bir uç, ayrı bir yetki ve ayrı bir denetim
 * satırı (bkz. CredentialSecretApiController). Buraya eklenseydi "hangi
 * kayıtlar var" sorusunu soran her istemci parolayı da almış olurdu.
 *
 * ALANLAR YETKİYE GÖRE: adresi, portu ve kullanıcı adını görmek ayrı ayrı
 * yetkilere bağlı (bkz. config/permissions.php). Yetkisi olmayana alan hiç
 * yazılmaz — boş dönmek "değer yok" demektir, oysa değer var ve görmesi
 * yasak.
 *
 * Sıralı birincil anahtar DIŞARI ÇIKMAZ; kayıt uuid taşır.
 *
 * @mixin Credential
 */
class CredentialApiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $kullanici = $request->user();

        /*
         * Alan görünürlüğü TEK YERDEN sorulur.
         *
         * Credential::visibleFieldsFor panelin de kullandığı yol: grup
         * yetkisi taban, kayıt bazlı ek izin üstüne. Ayrı bir kontrol
         * yazsaydık API ile ekran farklı yanıt verebilirdi ve fark ancak
         * birinde sızıntı olarak görünürdü.
         *
         * Soru kaydın kendisine sorulur; dışarıdan geçirilecek bir kimlik
         * yoktur, yani kayıt ile grup kimliği karışamaz.
         */
        $gorunur = $kullanici === null
            ? []
            : $this->resource->visibleFieldsFor($kullanici);

        /*
         * JETON DA ALANLARI DARALTIR: sahibinin görebildiği alan, jetonun
         * yetenek listesinde de varsa döner. Jetona yalnızca 'view' verilmiş
         * olması adres/bağlantı/kullanıcı adının verilmesi demek değildir;
         * kullanıcı adı 'field.password' yeteneğine bağlıdır (bkz.
         * config/permissions.php). Oturumla gelen istekte jeton yoktur, bir
         * daraltma da yoktur.
         */
        $jeton = app(ApiTokenContext::class)->get();

        if ($jeton !== null) {
            foreach ((array) config('permissions.fields', []) as $alan => $aksiyon) {
                if (! $jeton->permits(CredentialPolicy::RESOURCE, $aksiyon)) {
                    $gorunur[$alan] = false;
                }
            }
        }

        return [
            'kimlik' => $this->uuid,
            'ad' => $this->name,
            'grup' => $this->whenLoaded('group', fn (): array => [
                'kimlik' => $this->group->uuid,
                'ad' => $this->group->name,
            ]),

            /* Grubun içindeki bölme; yoksa null. Yetki taşımaz, yalnızca yer söyler. */
            'alt_grup' => $this->whenLoaded('altGrup', fn (): ?array => $this->altGrup === null ? null : [
                'kimlik' => $this->altGrup->uuid,
                'ad' => $this->altGrup->name,
            ]),
            'tur' => $this->access_type?->value,
            'platform' => $this->platform,

            'adres' => $this->when($gorunur['host'] ?? false, $this->host),
            'uplink' => $this->when($gorunur['uplink'] ?? false, $this->uplink),
            'port' => $this->when($gorunur['port'] ?? false, $this->port),
            'baglanti' => $this->when($gorunur['url'] ?? false, $this->url),
            'kullanici' => $this->when($gorunur['username'] ?? false, $this->username),

            /* Parola YOK; yalnızca alınabilir olup olmadığı söylenir. */
            'parola_alinabilir' => $kullanici?->can('reveal', $this->resource) === true,

            'guncelleme_acik' => (bool) $this->rotation_enabled,
            'son_guncelleme' => $this->updated_at?->toIso8601String(),
        ];
    }
}
