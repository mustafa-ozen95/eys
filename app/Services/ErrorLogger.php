<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ErrorLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Uygulama hatalarını panele yazar.
 *
 * Dosya günlüğü sunucuya girmeyi gerektiriyor; kuyrukta çalışan bir döndürme
 * patladığında bunu panelden görebilmek gerek. Laravel'in kendi dosya
 * günlüğü kapanmaz, bu ONA EK bir görünümdür.
 */
class ErrorLogger
{
    /**
     * Kaydedilmeyen istisnalar.
     *
     * Doğrulama hatası, yetkisiz erişim ve 404 uygulamanın NORMAL çalışması;
     * bunları hata listesine koymak listeyi kullanılmaz hâle getirirdi.
     * Yetki reddi zaten denetim kaydında görünür.
     *
     * @var list<class-string>
     */
    private const IGNORED = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        TokenMismatchException::class,
    ];

    public function __construct(
        private Request $request,
        private Application $app,
    ) {}

    public function record(Throwable $e): void
    {
        if ($this->shouldIgnore($e)) {
            return;
        }

        /*
         * Hata kaydının kendisi patlarsa istisna işleyicisi döngüye girer.
         * Bu yüzden bütün gövde sessizce korunur: kayıt tutamamak, uygulamayı
         * durdurmaktan iyidir.
         */
        try {
            $this->store($e);
        } catch (Throwable) {
            // yoksay
        }
    }

    private function store(Throwable $e): void
    {
        $signature = hash('sha256', implode('|', [
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            (string) $e->getLine(),
        ]));

        $user = $this->request->user();

        /*
         * Aynı hata varsa sayaç artar. updateOrCreate iki isteğin aynı anda
         * gelmesinde çakışabilir; benzersiz imza sütunu bunu veritabanında
         * yakalar ve ikinci deneme artırmaya döner.
         */
        $existing = ErrorLog::query()->where('signature', $signature)->first();

        if ($existing !== null) {
            /*
             * Sayaç SORGU ÜZERİNDEN artırılır: model kaydı ham ifadeyi
             * değere çevirmiyor ve sayaç sessizce 1'de kalıyordu. Sorgu
             * biçimi ayrıca atomik — aynı hatayı aynı anda üreten iki
             * istek birbirinin artışını ezmez.
             */
            ErrorLog::query()->whereKey($existing->getKey())->update([
                'occurrences' => DB::raw('occurrences + 1'),
                'last_seen_at' => now(),
            ]);

            return;
        }

        ErrorLog::create([
            'signature' => $signature,
            'level' => 'error',
            'type' => $e::class,
            'message' => str($e->getMessage())->limit(2000)->value(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context_label' => $this->label(),
            'method' => $this->app->runningInConsole() ? null : $this->request->method(),
            'user_id' => $user?->getKey(),
            'actor_name' => $user?->name,
            'ip_address' => $this->app->runningInConsole() ? null : $this->request->ip(),
            'trace' => $this->safeTrace($e),
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Yığın izi ARGÜMANSIZ üretilir.
     *
     * PHP'nin kendi getTraceAsString() çıktısı çağrı argümanlarını
     * taşıyabiliyor ve bu panelde argümanlar parola oluyor. Yalnızca dosya,
     * satır ve çağrılan metot yazılır.
     */
    private function safeTrace(Throwable $e): string
    {
        $lines = [];

        foreach (array_slice($e->getTrace(), 0, 25) as $i => $frame) {
            $lines[] = sprintf(
                '#%d %s(%s): %s%s%s()',
                $i,
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? '-',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '',
            );
        }

        return implode("\n", $lines);
    }

    private function label(): string
    {
        return $this->app->runningInConsole()
            ? 'konsol / kuyruk'
            : str($this->request->path())->limit(190)->value();
    }

    private function shouldIgnore(Throwable $e): bool
    {
        foreach (self::IGNORED as $ignored) {
            if ($e instanceof $ignored) {
                return true;
            }
        }

        /* 404 ve 403 gibi beklenen HTTP durumları gürültüdür; 5xx değildir. */
        return $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500;
    }
}
