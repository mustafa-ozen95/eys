<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Açılmış bir konsol oturumunun denetim izi.
 *
 * Oturumun canlı hâli önbellektedir; buradaki satır "ne zaman açıldı, en son
 * ne zaman ses verdi, nasıl kapandı" sorularını yanıtlar. Kapanışı istemci
 * haber veremezse (tarayıcı çöktü, makine uyudu, ağ koptu) zamanlayıcı bu
 * satırdan anlar.
 */
class ConsoleSession extends Model
{
    protected $table = 'konsol_oturumlari';

    protected $fillable = [
        'token',
        'user_id',
        'credential_id',
        'label',
        'protocol',
        'port',
        'cihaz_cerezi',
        'opened_at',
        'last_seen_at',
        'closed_at',
        'closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'protocol' => AccessType::class,
            'port' => 'integer',
            /*
             * Cihazın oturum çerezi. ŞİFRELİ: satır bir denetim izi, sır
             * deposu değil — yine de kapanana kadar burada duruyor ve
             * veritabanını okuyan biri onunla cihazda oturum açabilirdi.
             */
            'cihaz_cerezi' => 'encrypted',
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Credential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /**
     * Kapanmamış ama SESİ KESİLMİŞ oturumlar.
     *
     * Canlı tutma isteği dakikalarca gelmiyorsa karşı taraf gitmiştir.
     * Sınır, canlı tutma aralığının birkaç katıdır: ağdaki bir saniyelik
     * kesinti oturumu düşmüş saymamalı.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSessizKalan(Builder $query, int $saniye): void
    {
        $query->whereNull('closed_at')->where('last_seen_at', '<', now()->subSeconds($saniye));
    }

    /** @param  Builder<$this>  $query */
    public function scopeAcik(Builder $query): void
    {
        $query->whereNull('closed_at');
    }
}
