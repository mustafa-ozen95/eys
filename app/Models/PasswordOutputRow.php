<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Toplu parola çıktısında dosyaya giren bir kayıt (bkz. parola_ciktisi_satirlari göçü).
 */
class PasswordOutputRow extends Model
{
    protected $table = 'parola_ciktisi_satirlari';

    public $timestamps = false;

    protected $fillable = ['activity_log_id', 'credential_id', 'grup', 'kayit'];

    public function log(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class, 'activity_log_id');
    }
}
