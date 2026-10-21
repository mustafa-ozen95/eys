<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Models\Setting;
use App\Services\ActivityRecorder;
use App\Services\Database\ConnectionFile;
use App\Services\Database\RemoteDatabase;
use App\Services\Settings\SettingsTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Number;
use Illuminate\View\View;
use Throwable;

/**
 * Panelin veritabanı — nerede olduğu ve uzak bir sunucuya taşınması.
 *
 * İKİ ADIM: önce SINA, sonra TAŞI. Sınanan bağlantı oturumda şifreli durur;
 * taşı düğmesi parolayı yeniden istemez ama sınamaları yeniden yapar —
 * arada hedef dolmuş ya da yetki alınmış olabilir.
 *
 * Taşımanın kendisi panel kapalıyken başlatıcıda yapılır (bkz. ConnectionFile).
 * Bu ekran isteği bırakır ve konteyneri yeniden başlatır.
 *
 * Yetkilendirme: sertifika ekranıyla aynı — görmek `viewAny`, sınamak ve
 * taşımak `update` (Setting politikası; ayarlar yalnızca yöneticide).
 */
class DatabaseController extends Controller implements HasMiddleware
{
    /** Sınanan bağlantı bu kadar süre taşınabilir; sonra yeniden sınanır. */
    private const SINAMA_OMRU = 900;

    private const OTURUM_ANAHTARI = 'veritabani.sinanan';

    private const KAYNAKLAR = [
        'ortam' => ['etiket' => 'Kurulum ayarı', 'aciklama' => 'Bağlantı kurulumun ortam değişkenlerinden (DB_*) geliyor; varsayılanı compose\'taki db servisi.'],
        'panel' => ['etiket' => 'Uzak (panel ayarı)', 'aciklama' => 'Bu sekmeden taşındı; bağlantı panelin depolama hacminde şifreli duruyor ve kurulum ayarını eziyor.'],
    ];

    /** @var array<string, array{etiket: string, renk: string}> */
    private const ASAMALAR = [
        'tasinacak' => ['etiket' => 'istendi, yeniden başlatma bekleniyor', 'renk' => 'blue'],
        'tasiniyor' => ['etiket' => 'kopyalanıyor', 'renk' => 'blue'],
        'deneniyor' => ['etiket' => 'uzak veritabanıyla açılıyor', 'renk' => 'blue'],
        'etkin' => ['etiket' => 'tamamlandı', 'renk' => 'green'],
        'basarisiz' => ['etiket' => 'yapılmadı', 'renk' => 'red'],
    ];

    public function __construct(
        private SettingsTabs $sekmeler,
        private RemoteDatabase $veritabani,
        private ConnectionFile $dosya,
        private ActivityRecorder $activity,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Setting::class, only: ['index']),
            new Middleware('can:update,'.Setting::class, only: ['sina', 'tasi']),
        ];
    }

    public function index(Request $istek): View
    {
        $sinanan = $this->sinanan($istek);
        $durum = $this->dosya->durum();
        $bilgi = $this->veritabani->bilgi();

        return view('settings.database', [
            'sections' => $this->sekmeler->all('veritabani'),
            'bilgi' => $bilgi,
            'boyut' => $bilgi['boyut'] === null ? '—' : Number::fileSize($bilgi['boyut'], 1),
            'kaynak' => self::KAYNAKLAR[$bilgi['kaynak']],
            'adres' => $bilgi['username'].'@'.$bilgi['host'].':'.$bilgi['port'].'/'.$bilgi['database'],
            'durum' => $durum === null ? null : [
                ...$durum,
                'istenme' => self::zaman($durum['istenme'] ?? null),
                'zaman' => self::zaman($durum['zaman'] ?? null),
            ],
            'asama' => $durum === null ? null : self::ASAMALAR[$durum['durum']],
            // Süren ya da biten bir taşıma varken yeni taşıma formu gösterilmez.
            'tasinabilir' => $bilgi['kaynak'] === 'ortam'
                && ! in_array($durum['durum'] ?? null, ['tasinacak', 'tasiniyor', 'deneniyor', 'etkin'], true),
            'sonuclar' => session('veritabani.sonuclar'),
            'sinanan' => $sinanan === null ? null : [
                ...array_diff_key($sinanan, ['password' => true]),
                'adres' => $sinanan['username'].'@'.$sinanan['host'].':'.$sinanan['port'].'/'.$sinanan['database'],
            ],
        ]);
    }

    public function sina(Request $istek): RedirectResponse
    {
        $hedef = ConnectionFile::temizle($istek->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:200'],
        ], [
            'host.regex' => 'Konak adı ya da IPv4 adresi girin.',
            'database.regex' => 'Veritabanı adında yalnızca harf, rakam, alt çizgi ve tire olabilir.',
        ]));

        if (RemoteDatabase::icMi($hedef['host'])) {
            return back()->withInput($istek->except('password'))
                ->withErrors(['host' => 'Bu adres konteynerin kendisi; uzak sunucunun adresini girin.']);
        }

        $sonuclar = $this->veritabani->sina($hedef);
        $tamam = RemoteDatabase::hepsiTamam($sonuclar);

        if ($tamam) {
            $istek->session()->put(self::OTURUM_ANAHTARI, Crypt::encrypt([...$hedef, 'zaman' => time()]));
        } else {
            $istek->session()->forget(self::OTURUM_ANAHTARI);
        }

        return back()
            ->withInput($istek->except('password'))
            ->with('veritabani.sonuclar', $sonuclar)
            ->with($tamam ? 'status' : 'error', $tamam
                ? 'Sınamalar geçti. Taşımak için aşağıdaki düğmeyi kullanın.'
                : 'Hedef taşımaya uygun değil; ayrıntılar aşağıda.');
    }

    public function tasi(Request $istek): RedirectResponse
    {
        $hedef = $this->sinanan($istek);

        if ($hedef === null) {
            return back()->withErrors(['veritabani' => 'Önce bağlantıyı sınayın (sınama 15 dakika geçerli).']);
        }

        if (in_array($this->dosya->asama(), ['tasinacak', 'tasiniyor', 'deneniyor', 'etkin'], true)) {
            return back()->withErrors(['veritabani' => 'Süren ya da tamamlanmış bir taşıma var.']);
        }

        $sonuclar = $this->veritabani->sina($hedef);

        if (! RemoteDatabase::hepsiTamam($sonuclar)) {
            $istek->session()->forget(self::OTURUM_ANAHTARI);

            return back()
                ->with('veritabani.sonuclar', $sonuclar)
                ->withErrors(['veritabani' => 'Sınamalar artık geçmiyor; taşıma başlatılmadı.']);
        }

        $adres = $hedef['username'].'@'.$hedef['host'].':'.$hedef['port'].'/'.$hedef['database'];

        try {
            $this->dosya->baglantiYaz($hedef);
            $this->dosya->asamaYaz('tasinacak', null, [
                'hedef' => $adres,
                'isteyen' => $istek->user()?->name,
                'istenme' => date('c'),
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->dosya->baglantiSil();

            return back()->withErrors(['veritabani' => 'Taşıma isteği yazılamadı (storage/app/database). Sunucu yöneticisine bildirin.']);
        }

        $this->activity->record(
            ActivityAction::Updated,
            'Veritabanı taşıması istendi: '.$adres,
            reason: trim((string) $istek->input('reason', '')),
        );
        $istek->session()->forget(self::OTURUM_ANAHTARI);

        if (! $this->dosya->yenidenBaslatIste()) {
            return back()->withErrors(['veritabani' => 'Taşıma istendi ama panel yeniden başlatılamadı. Konteyneri elle yeniden başlatın; taşıma açılışta yapılır.']);
        }

        return back()->with('status', 'Taşıma istendi. Panel birkaç saniye içinde yeniden başlayacak ve kopya bitene kadar erişilemeyecek; oturumlar kapanır. Sonucu bu sekmede görürsünüz.');
    }

    /** Dosyadaki ISO zaman, panelin saat diliminde okunur biçime. */
    private static function zaman(?string $iso): ?string
    {
        try {
            return $iso === null ? null : Carbon::parse($iso)->setTimezone(config('app.timezone'))->format('d.m.Y H:i');
        } catch (Throwable) {
            return $iso;
        }
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string}|null */
    private function sinanan(Request $istek): ?array
    {
        $sifreli = $istek->session()->get(self::OTURUM_ANAHTARI);

        if (! is_string($sifreli)) {
            return null;
        }

        try {
            $veri = (array) Crypt::decrypt($sifreli);
        } catch (Throwable) {
            return null;
        }

        return time() - (int) ($veri['zaman'] ?? 0) <= self::SINAMA_OMRU ? ConnectionFile::temizle($veri) : null;
    }
}
