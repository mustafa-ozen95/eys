<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Net\Cidr;
use Carbon\CarbonImmutable;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Dışarıya verilen bir API jetonu.
 *
 * Jetonun kendisi SAKLANMAZ; yalnızca sha256 özeti tutulur. Kaybolan jeton
 * geri gösterilemez, yenisi üretilir (bkz. rotate).
 *
 * Jeton SAHİBİNİ AŞAMAZ: yetkisi, jetonun kendi listesi ile sahibinin panel
 * yetkilerinin KESİŞİMİDİR. Sahibinin yetkisi daraltılınca jeton da daralır;
 * bu yüzden jeton, unutulmuş bir arka kapıya dönüşmez.
 */
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    use HasUuid;

    /** Özetin ve önekin ayracı: "eys_<sır>". */
    public const SEPARATOR = '_';

    protected $fillable = [
        'name',
        'user_id',
        'created_by',
        'token_hash',
        'prefix',
        'abilities',
        'allowed_ips',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * Yeni bir sır üretir ve özetini döndürür.
     *
     * Sır YALNIZCA burada görülür; çağıran onu kullanıcıya bir kez gösterir
     * ve unutur.
     *
     * @return array{plain: string, hash: string, prefix: string}
     */
    public static function mint(): array
    {
        $prefix = (string) config('api.prefix');
        $secret = Str::random(max(32, (int) config('api.secret_bytes')));
        $plain = $prefix.self::SEPARATOR.$secret;

        return [
            'plain' => $plain,
            'hash' => hash('sha256', $plain),

            // Ekranda jetonu tanımak için; sır değildir.
            'prefix' => $prefix.self::SEPARATOR.substr($secret, 0, 6),
        ];
    }

    /**
     * Jetonun kendi yetki listesi: kaynak => aksiyonlar.
     *
     * @return array<string, list<string>>
     */
    public function abilityMap(): array
    {
        return $this->abilities ?? [];
    }

    /**
     * Jeton bu kaynakta bu işi yapabilir mi?
     *
     * Yalnızca jetonun KENDİ listesine bakar; sahibinin yetkisi ayrıca
     * sorulur (bkz. AppServiceProvider'daki Gate::before).
     */
    public function permits(string $resource, string $action): bool
    {
        return in_array($action, $this->abilityMap()[$resource] ?? [], true);
    }

    /**
     * Jeton şu anda kullanılabilir mi?
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * İstek bu adresten kabul edilir mi?
     *
     * Liste boşsa her yerden kabul edilir. Adres tek bir IP ya da CIDR
     * olabilir; ikisi de aynı biçimde karşılaştırılır.
     */
    public function allowsIp(?string $ip): bool
    {
        $izinli = $this->allowed_ips ?? [];

        if ($izinli === []) {
            return true;
        }

        if ($ip === null) {
            return false;
        }

        foreach ($izinli as $kural) {
            if ($this->matches((string) $kural, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jetonu iptal eder. SİLMEZ: kimin hangi jetonla ne yaptığı kayıtta
     * durur ve iptal edilmiş jetonun adı da o kaydın parçasıdır.
     */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
    }

    /**
     * Yeni bir sır üretir; eskisi anında geçersiz olur.
     *
     * @return string düz metin jeton; yalnızca burada görülür
     */
    public function rotate(): string
    {
        $yeni = self::mint();

        $this->forceFill([
            'token_hash' => $yeni['hash'],
            'prefix' => $yeni['prefix'],
            /*
             * revoked_at'e DOKUNULMAZ. Sır değiştirmek bir YAŞAM DÖNGÜSÜ
             * kararı değildir; iptal edilmiş jeton sır yenilemeyle yeniden
             * etkinleşmez. İptal edilmişin sırrı hiç değiştirilmez
             * (bkz. ApiTokenController::rotate).
             */
            'last_used_at' => null,
            'last_used_ip' => null,
        ])->save();

        return $yeni['plain'];
    }

    /** @param  Builder<$this>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tek bir IP ile bir kural (IP ya da CIDR) eşleşiyor mu.
     *
     * Tek adresli kural doğrudan karşılaştırılır; ağ kuralı için istek
     * adresi tek konaklı bir bloğa çevrilir (Cidr::contains bir blok bekler).
     */
    private function matches(string $rule, string $ip): bool
    {
        if (! str_contains($rule, '/')) {
            return hash_equals($rule, $ip);
        }

        $host = Cidr::tryParse($ip.(str_contains($ip, ':') ? '/128' : '/32'));

        return $host !== null && (Cidr::tryParse($rule)?->contains($host) ?? false);
    }
}
