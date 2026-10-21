<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RotationStage;
use App\Enums\RotationStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir parola döndürme denemesinin kaydı.
 *
 * Denenen ve önceki parola ŞİFRELİ saklanır ve $hidden içindedir. Bu kayıt
 * bir kurtarma noktasıdır: "cihazda değişti ama doğrulanamadı" hâlinde
 * cihaza girmenin tek yolu buradaki denenen paroladır.
 */
class PasswordRotation extends Model
{
    use HasUuid;

    protected $fillable = [
        'credential_id',
        'user_id',
        'actor_name',
        'status',
        'stage',
        'recovery_username',
        'recovery_password',
        'recovery_active',
        'attempted_password',
        'previous_password',
        'message',
    ];

    protected $hidden = [
        'attempted_password',
        'previous_password',
        'recovery_password',
    ];

    protected function casts(): array
    {
        return [
            'status' => RotationStatus::class,
            'stage' => RotationStage::class,
            'recovery_active' => 'boolean',
            'finished_at' => 'datetime',
            'attempted_password' => 'encrypted',
            'previous_password' => 'encrypted',
            'recovery_password' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Credential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeRunning(Builder $query): void
    {
        $query->where('status', RotationStatus::Running);
    }

    /**
     * Bu tur kurtarma hesaplı sırayı mı işletiyor?
     *
     * Kurtarma adı, hesap cihaza gitmeden ÖNCE kayda yazılıyor; dolu olması
     * turun altı adımlı sırada olduğunu söyler. Sürücüye sormak yanıltıcı
     * olurdu: sürücünün bugünkü yeteneği, üç ay önce başlamış bir turun
     * hangi sırayı işlettiğini anlatmaz.
     */
    public function usesRecovery(): bool
    {
        return filled($this->recovery_username);
    }

    /** Kurtarma hesaplı sıra: aç, kanıtla, değiştir, doğrula, sil, bitir. */
    public const ADIM_KURTARMALI = 6;

    /** Doğrudan sıra: başla, yaz, doğrula. */
    public const ADIM_DOGRUDAN = 3;

    /** Turun toplam adım sayısı. */
    public function totalSteps(): int
    {
        return $this->usesRecovery() ? self::ADIM_KURTARMALI : self::ADIM_DOGRUDAN;
    }

    /** Kaçıncı adım bitti. */
    public function completedSteps(): int
    {
        $stage = $this->stage ?? RotationStage::Started;

        if ($this->usesRecovery()) {
            return $stage->step();
        }

        /* Kurtarmasız sıra üç adımdır: başla, yaz, doğrula. */
        return match ($stage) {
            RotationStage::PasswordVerified, RotationStage::RecoveryRemoved => 3,
            RotationStage::PasswordChanged => 2,
            default => 1,
        };
    }

    /**
     * Çubuğun doluluğu (%).
     *
     * Biten tur her zaman doludur — başarısız bitse bile. Yarım kalmış bir
     * çubuk, ekranda hâlâ bir şey olduğu izlenimi verirdi; oysa turun sonucu
     * rozette yazıyor.
     */
    public function progress(): int
    {
        if ($this->status !== RotationStatus::Running) {
            return 100;
        }

        return (int) round($this->completedSteps() / $this->totalSteps() * 100);
    }
}
