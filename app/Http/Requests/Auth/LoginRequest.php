<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Services\ActivityRecorder;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Oturumu açar.
     *
     * Pasif hesap doğru parolayla da giremez: hesabı kapatmak, parolayı
     * değiştirmeyi beklemeden erişimi kesmelidir.
     */
    public function authenticate(ActivityRecorder $activity): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            ...$this->only('email', 'password'),
            'status' => UserStatus::Active,
        ];

        /*
         * HATIRLAMA ÇEREZİ VERİLMEZ (ikinci parametre false).
         *
         * Kalıcı çerez, hareketsizlik süresini anlamsız kılıyordu: oturum
         * düşse bile kullanıcı sessizce geri içeri alınıyordu. Giriş
         * ekranındaki kutucuk da kaldırıldı — ama karar BURADA duruyor:
         * forma güvenmek denetim değildir, isteği elle kuran "remember=1"
         * yazabilirdi.
         */
        if (! Auth::attempt($credentials, remember: false)) {
            RateLimiter::hit($this->throttleKey());

            $activity->record(
                ActivityAction::LoginFailed,
                'Başarısız giriş denemesi: '.$this->string('email')->limit(80),
            );

            throw ValidationException::withMessages([
                'email' => 'Bu bilgilerle giriş yapılamadı.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        $user = $this->user();

        /*
         * Son giriş zamanı BURADA YAZILMAZ: `Login` olayını dinleyen tek bir
         * yer yazıyor (bkz. MarkLastLogin). Burada yazılıyordu ve merkezle
         * giriş yapanlarda hiç yazılmıyordu.
         */
        $activity->record(ActivityAction::LoggedIn, "{$user->name} giriş yaptı", $user);
    }

    private function ensureIsNotRateLimited(): void
    {
        $limit = (int) config('auth.max_attempts');

        /*
         * SIFIR "KİLİT YOK" DEMEKTİR ve burada erken dönmek ŞARTTIR.
         *
         * `tooManyAttempts($key, 0)` sınırı kaldırmaz: sayaç sıfırdan büyük
         * ya da eşit olduğu için İLK yanlış paroladan sonra kilitler, yani
         * kapatmak isterken en sert hâline getirirdi. Aynı tuzak istek
         * sınırlarında da var (bkz. App\Support\RequestLimit ve
         * config/auth.php'deki login_throttle).
         */
        if ($limit <= 0) {
            return;
        }

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $limit)) {
            return;
        }

        Event::dispatch(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Çok fazla deneme yapıldı. {$seconds} saniye sonra tekrar deneyin.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
