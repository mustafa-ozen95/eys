<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pasife alınan hesabın AÇIK OTURUMLARI da kapanır.
 *
 * NEDEN VAR: durumu değiştirmek yalnızca "bir daha giremesin" demekti; o
 * anda açık olan oturum çalışmaya devam ediyordu. Erişimi kaldırılan biri
 * sekmesini kapatmadığı sürece içeride kalıyordu — kaldırma işleminin
 * amacının tam tersi.
 *
 * MODEL OLAYINA bağlanır, tek tek ekranlara değil: durum dört ayrı yoldan
 * değişiyor (panel ekranı, merkez eşitlemesi, konsol komutu, kurulum) ve
 * hepsine ayrı ayrı eklemek, birini unutmaya açık olurdu.
 *
 * YALNIZCA VERİTABANI OTURUMUNDA satır silinebilir: redis ve dosya
 * sürücülerinde oturumlar kullanıcıya göre indekslenmiyor, "bu kişinin
 * oturumları" diye sorulabilecek bir yer yok. O yüzden sürücü kontrol edilir
 * ve desteklenmeyen sürücüde sessizce yanlış iş yapılmaz.
 *
 * BU YÜZDEN TEK BAŞINA YETMEZ ve yalnız bırakılmaz: EnsureUserIsActive her
 * istekte durumu sorar ve pasif hesabı dışarı atar. İkisi aynı şeyin iki
 * katmanıdır — satır silme ANINDA etkilidir, ara katman ise hangi sürücü
 * kullanılırsa kullanılsın ve durum hangi yoldan değişirse değişsin
 * çalışır.
 */
trait OturumlariKapatir
{
    public static function bootOturumlariKapatir(): void
    {
        static::updated(function (Model $model): void {
            /*
             * PAROLASI DEĞİŞEN BAŞKASININ oturumları da kapanır (yönetici
             * sıfırlaması). AuthenticateSession bunu zaten her istekte
             * zorlar; burası veritabanı oturumunda satırı da siler ve
             * hatırlama jetonunu yeniler. Hesabın SAHİBİ kendi parolasını
             * değiştiriyorsa dokunulmaz: o akış kendi oturumunu korur
             * (bkz. ProfilController::parola).
             */
            if ($model->wasChanged('password') && ! $model->is(auth()->user())) {
                $model->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
                $model->oturumlariKapat();
            }

            if (! $model->wasChanged('status')) {
                return;
            }

            if ($model->status === UserStatus::Active) {
                return;
            }

            $model->oturumlariKapat();
        });
    }

    /** Bu kullanıcının açık oturumlarını siler; kaç oturum kapandığını döndürür. */
    public function oturumlariKapat(): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->delete();
    }
}
