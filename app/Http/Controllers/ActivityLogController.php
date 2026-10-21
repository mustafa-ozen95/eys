<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Jobs\VerifyActivityChainJob;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Services\Activity\ActivityChain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Denetim kaydı — yalnızca yönetici. Yalnızca okunur; silme ucu yoktur.
 */
class ActivityLogController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:viewAny,App\Models\ActivityLog')];
    }

    public function index(Request $request, ActivityChain $chain): View
    {
        $subject = $this->subject($request);

        return view('logs.index', [
            /*
             * Ekran her açılışta bütün satırları yeniden özetlemez; son
             * doğrulamanın sonucunu okur. Doğrulamayı kullanıcı başlatır.
             */
            'verification' => $chain->lastVerification(),

            /* Düğmeye basan, işin sürdüğünü görmeli; yoksa tekrar tekrar basar. */
            'verifying' => $chain->isRunning(),
            'logs' => ActivityLog::query()
                /* Toplu parola çıktısının ayrıntısı (dosyaya giren kayıtlar) satırdaki pencerede açılır. */
                ->with(['user', 'ciktiSatirlari'])
                ->action($request->string('islem')->value() ?: null)
                ->forSubject($subject)
                ->search($request->string('q')->squish()->value() ?: null)
                ->latestFirst()
                ->paginate(50)
                ->withQueryString(),

            /* Süzgeç bir kayda daraltılmışsa ekran bunu söylesin ve kaldırma yolu versin. */
            'subject' => $subject,
            'query' => $request->string('q')->value(),
            'action' => $request->string('islem')->value(),
            'actions' => ['' => 'Tüm işlemler', ...ActivityAction::options()],
        ]);
    }

    /**
     * Adresteki kayıt süzgeci.
     *
     * Kimlik KULLANICININ GÖREBİLDİĞİ kayıtlarla sınırlanır: göremediği bir
     * kaydın kimliğini adrese yazan biri onun geçmişini de okuyamamalı.
     * Eşleşme yoksa süzgeç yok sayılır.
     */
    private function subject(Request $request): ?Credential
    {
        $uuid = $request->string('kayit')->value();

        if ($uuid === '') {
            return null;
        }

        return Credential::query()->visibleTo($request->user())->where('uuid', $uuid)->first();
    }

    /**
     * Zinciri baştan sona doğrular — KUYRUKTA.
     *
     * Doğrulama bütün satırları yeniden özetler; büyük kayıtta dakikalar
     * sürer. İstek içinde çalıştığında tarayıcı bekliyor, istek zaman
     * aşımına uğruyor ve doğrulama hiç bitmiyordu. Sonuç bittiğinde bu
     * ekranın üst şeridinde görünür.
     */
    public function verify(ActivityChain $chain): RedirectResponse
    {
        if ($chain->isRunning()) {
            return back()->with('status', 'Doğrulama zaten sürüyor; sonucu bu ekranda göreceksiniz.');
        }

        $chain->markRunning();

        VerifyActivityChainJob::dispatch();

        return back()->with(
            'status',
            'Zincir doğrulaması sıraya alındı. Büyük kayıtta birkaç dakika sürebilir; sonuç bu ekranda görünür.',
        );
    }
}
