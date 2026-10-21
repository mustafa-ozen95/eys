<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir yerel hesabın merkezdeki karşılığı.
 *
 * Bağ SUBJECT ile kurulur: kişi merkezde e-postasını değiştirse bile
 * eşleşme bozulmaz.
 */
class UserIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'subject', 'email', 'last_login_at'];

    protected $casts = ['last_login_at' => 'datetime', 'merkez_cikis_ms' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
