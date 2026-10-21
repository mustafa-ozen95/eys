<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uygulama hatası.
 *
 * Aynı hata tekrar tekrar satır açmaz; imzası eşleşince sayaç artar.
 */
class ErrorLog extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'signature',
        'level',
        'type',
        'message',
        'file',
        'line',
        'context_label',
        'method',
        'user_id',
        'actor_name',
        'ip_address',
        'trace',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Çözülmüş bir hata TEKRAR ederse yeniden açılır.
     *
     * "Çözüldü" işareti geçmişe dairdir; hata devam ediyorsa çözülmemiştir.
     */
    public function isReopened(): bool
    {
        return $this->resolved_at !== null && $this->last_seen_at->gt($this->resolved_at);
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('resolved_at')
            ->orWhereColumn('last_seen_at', '>', 'resolved_at'));
    }

    /** @param  Builder<$this>  $query */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('last_seen_at')->orderByDesc('id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('message', 'like', "%{$value}%")
                ->orWhere('type', 'like', "%{$value}%")
                ->orWhere('context_label', 'like', "%{$value}%")
        ));
    }
}
