<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir Linux kaydının sistem güncellemesi durumu: son kontrol, son kurulum, kilit.
 *
 * Dışarı çıkmaz: URL'de kaydın uuid'si taşınır, bu satırın kimliği değil.
 *
 * @property array<int, array{name: string, version: string, critical: bool}>|null $packages
 */
class OsUpdateState extends Model
{
    /* Kurulumun durumları; ekran ve toplu gösterge bu adları kullanır. */
    public const SIRADA = 'queued';

    public const SURUYOR = 'running';

    public const BASARILI = 'succeeded';

    public const BASARISIZ = 'failed';

    public const ATLANDI = 'skipped';

    protected $fillable = [
        'credential_id',
        'package_manager',
        'os_name',
        'critical_count',
        'normal_count',
        'packages',
        'reboot_required',
        'boot_free_mb',
        'checked_at',
        'check_error',
        'apply_status',
        'apply_mode',
        'apply_queued_at',
        'apply_finished_at',
        'apply_error',
        'applied_by',
        'locked',
        'lock_reason',
        'locked_by',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'packages' => 'array',
            'reboot_required' => 'boolean',
            'locked' => 'boolean',
            'critical_count' => 'integer',
            'normal_count' => 'integer',
            'boot_free_mb' => 'integer',
            'checked_at' => 'datetime',
            'apply_queued_at' => 'datetime',
            'apply_finished_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Credential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /** @return BelongsTo<User, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function isBusy(): bool
    {
        return in_array($this->apply_status, [self::SIRADA, self::SURUYOR], true);
    }
}
