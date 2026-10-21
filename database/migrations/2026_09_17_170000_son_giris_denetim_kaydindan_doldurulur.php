<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SON GİRİŞ ALANI DENETİM KAYDINDAN DOLDURULUR.
 *
 * Son giriş her giriş yolunda (parola ve merkez) `Login` olayıyla yazılır
 * (bkz. MarkLastLogin); bu göç alanı boş kalmış hesaplar için GEÇMİŞİ
 * denetim kaydından doldurur. Kaynak uydurma değil: `activity_logs` zincirindeki
 * `logged_in` satırları, girişin kendisinin kanıtı.
 *
 * ÜSTÜNE YAZMAZ: alanı dolu olan kayıtlara dokunulmaz — biri denetim
 * kaydındakinden yeni bir giriş taşıyorsa onu geri almak, onarım değil kayıp
 * olurdu.
 *
 * GERİ ALINMAZ (down boş): "önceden boştu" bilgisi saklanmaz ve doğru
 * bir zamanı geri silmek hiçbir şeyi düzeltmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        $girisler = DB::table('activity_logs')
            ->where('action', 'logged_in')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->pluck(DB::raw('MAX(created_at)'), 'user_id');

        foreach ($girisler as $kullaniciId => $sonGiris) {
            DB::table('users')
                ->where('id', $kullaniciId)
                ->where(function ($sorgu) use ($sonGiris): void {
                    $sorgu->whereNull('last_login_at')->orWhere('last_login_at', '<', $sonGiris);
                })
                ->update(['last_login_at' => $sonGiris]);
        }
    }

    public function down(): void
    {
        //
    }
};
