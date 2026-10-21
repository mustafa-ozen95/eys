<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityAction;
use App\Models\Builders\ActivityLogBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Denetim kaydı.
 *
 * Yazılır, okunur; güncellenmez. Panelde bir "temizle" düğmesi YOKTUR —
 * kaydı silebilen bir denetim kaydı, denetim kaydı değildir. Silmenin tek
 * yolu sunucuya erişebilen birinin çalıştırdığı budama komutudur
 * (bkz. PruneActivityLogCommand) ve o silme de kendini zincire yazar.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_name',
        'action',
        'subject_type',
        'subject_id',
        'summary',
        'reason',
        'ip_address',
        'user_agent',
        'chain',
        'chain_version',
        'previous_hash',
        'hash',
    ];

    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'created_at' => 'datetime',
        ];
    }

    /** Budama sürerken true. Bkz. prune(). */
    private static bool $pruning = false;

    /**
     * Sorgu kurucusu da kapıyı bilmeli.
     *
     * Toplu silme model olaylarını ATLAR: yasak yalnızca olayla kurulsaydı
     * `ActivityLog::query()->delete()` yasağın etrafından dolaşırdı.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return ActivityLogBuilder<$this>
     */
    public function newEloquentBuilder($query): ActivityLogBuilder
    {
        return new ActivityLogBuilder($query);
    }

    /** Budama sürüyor mu? Yalnızca sorgu kurucusu sorar. */
    public static function pruning(): bool
    {
        return self::$pruning;
    }

    /**
     * Zincirin bütünlüğü satırın DEĞİŞMEMESİNE dayanır.
     *
     * Uygulamanın kendi kodundan bir güncelleme ya da silme, zinciri kıran
     * ilk şey olurdu. Model seviyesinde kapatılır; veritabanına dışarıdan
     * yapılan müdahaleyi zaten doğrulama yakalar.
     */
    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => self::$pruning);
    }

    /**
     * Silmenin izin verilen TEK yolu.
     *
     * Kapı hem model olayına hem sorgu kurucusuna bakar; ikisinden biri
     * eksik olsaydı yasak "kapalı" görünür ama öbür yoldan sessizce
     * aşılabilirdi. İstisna böylece görünür, aranabilir ve tek yerdedir.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function prune(callable $callback): mixed
    {
        self::$pruning = true;

        try {
            return $callback();
        } finally {
            self::$pruning = false;
        }
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Toplu parola çıktısında dosyaya giren kayıtlar (çıktı başına tek denetim satırı).
     *
     * @return HasMany<PasswordOutputRow, $this>
     */
    public function ciktiSatirlari(): HasMany
    {
        return $this->hasMany(PasswordOutputRow::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  Builder<$this>  $query */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeAction(Builder $query, ?string $action): void
    {
        $query->when(
            $action !== null && ActivityAction::tryFrom($action) !== null,
            fn (Builder $q) => $q->where('action', $action)
        );
    }

    /**
     * Tek bir kaydın geçmişi.
     *
     * Ada göre aramak yetmez: "srv-a" araması "srv-a SSH"yi de getirir. Kimlik
     * üzerinden süzmek tam olarak o kaydı gösterir.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForSubject(Builder $query, ?Model $subject): void
    {
        /*
         * Bir kaydın geçmişi, o kaydın girdiği toplu parola çıktılarını da
         * gösterir: çıktının denetim satırı tek ve konusuz, kayıt ayrıntıda.
         */
        $query->when($subject, fn (Builder $q, Model $model) => $q->where(fn (Builder $ic) => $ic
            ->where(fn (Builder $dogrudan) => $dogrudan
                ->where('subject_type', $model->getMorphClass())
                ->where('subject_id', $model->getKey()))
            ->when($model instanceof Credential, fn (Builder $c) => $c
                ->orWhereHas('ciktiSatirlari', fn (Builder $satir) => $satir->where('credential_id', $model->getKey())))));
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('summary', 'like', "%{$value}%")
                ->orWhere('actor_name', 'like', "%{$value}%")
                ->orWhere('ip_address', 'like', "%{$value}%")
        ));
    }
}
