<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\Credential\InterfaceSessionRequest;
use App\Models\Credential;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\DeviceInterface\InterfaceSession;
use App\Services\DeviceInterface\InterfaceType;
use App\Support\RequestLimit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cihaz arayüzünü panelin kendi kökeninden açar (ESXi, VAMI).
 *
 * Gerekçe InterfaceType'nde: bu arayüzlerin oturumu dışarıdan açılamıyor. Arayüz
 * buradan servis edilince çerez de localStorage da bizim tarafımızda olur ve
 * giriş tutar.
 *
 * PAROLA SAYFA KAYNAĞINA YAZILMAZ. Fırlatma sayfası parolayı gövdesinde
 * taşımaz; açılış betiği onu TEK KULLANIMLIK bir uçtan ister ve uç ilk
 * okumadan sonra kapanır. Sayfa geri tuşuyla yeniden çizilse ya da bir yerde
 * önbelleğe düşse bile parola orada olmaz.
 */
class InterfaceSessionController extends Controller implements HasMiddleware
{
    public function __construct(
        private ActivityRecorder $activity,
        private InterfaceSession $oturumlar,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            /*
             * "arayuz", "session" DEĞİL.
             *
             * Portal fırlatma parolayı tarayıcıdan gönderiyor ve orada
             * parolayı görme yetkisi aranıyor; vekil oturumu SUNUCUDA açıyor,
             * parola tarayıcıya hiç gitmiyor. Gerekçe CredentialPolicy'de.
             */
            new Middleware('can:arayuz,credential', only: ['ac']),
            ...RequestLimit::icin('portal', ['ac']),
        ];
    }

    /** Oturumu açar ve tarayıcıyı vekillenen arayüze yollar. */
    public function ac(InterfaceSessionRequest $request, Credential $credential): RedirectResponse|Response
    {
        abort_unless($credential->arayuz_turu !== null, 404);

        /*
         * CİHAZ GİRİŞİ BAŞARISIZSA BU BİR ÇÖKME DEĞİLDİR.
         *
         * Panelde her şey yerindeyken cihaz girişi yine de tutmayabilir:
         * kasadaki parola eskimiştir, hesap kilitlenmiştir, cihaz kapalıdır.
         * Bunlar uygulamanın NORMAL çalışması ve kullanıcıya böyle
         * görünmeliler — istisna olduğu gibi yukarı çıksaydı ekranda
         * "HTTP 500" yazardı (ör. kasadaki parolayı cihaz kabul etmediğinde).
         *
         * Deneme denetim defterine de düşer: bir arayüzün açılamaması,
         * açılması kadar bilinmeye değer.
         */
        try {
            $oturum = $this->oturumlar->ac($credential, (int) $request->user()->id);
        } catch (Throwable $e) {
            /*
             * AĞ HATASI AYRI YAZILIR. cURL'ün kendi cümlesi ("cURL error 28:
             * Operation timed out…") kullanıcıya bir şey anlatmıyor; söylediği
             * tek şey cihaza ulaşılamadığı ve onu Türkçe söylemek daha doğru.
             */
            $sebep = $e instanceof ConnectionException
                ? 'Cihaza ulaşılamadı: adres kapalı, ağ erişimi yok ya da cihaz yanıt vermiyor.'
                : $e->getMessage();

            $this->activity->record(
                ActivityAction::LoginFailed,
                "{$credential->group->name} · {$credential->name} · arayüz açılamadı ({$credential->username}) · {$sebep}",
                $credential,
                $request->string('reason')->value(),
            );

            return response()->view('credentials.interface.error', [
                'credential' => $credential,
                'sebep' => $sebep,
            ], 502);
        }

        $this->activity->record(
            ActivityAction::PortalSession,
            "{$credential->group->name} · {$credential->name} · {$oturum['tur']->etiket()} açıldı ({$credential->username})",
            $credential,
            $request->string('reason')->value(),
        );

        $omur = (int) ceil($this->oturumlar->omur() / 60);

        /*
         * Kimlik vekil kökenine ANLIK bir jetonla taşınır.
         *
         * Çerez BURADA konulmaz: vekil ayrı bir alan adındaysa buradan
         * konulan çerez oraya zaten gitmez; aynı alandaysa da cihazın
         * oturumunu panelin kökenine bırakmanın anlamı yok. Jeton TEK
         * KULLANIMLIKTIR ve devir ucunda yanar — adres çubuğunda, tarayıcı
         * geçmişinde ya da bir kayıt satırında kalsa bile ikinci kez işe
         * yaramaz. Kalıcı olan tek şey, yalnızca vekil kökeninde yaşayan
         * çerezdir.
         *
         * away() kullanılıyor: Laravel'in url() üreticisi adresi kendi
         * köküne göre kurar ve sondaki eğik çizgiyi kırpar; vekil başka bir
         * alan adında olabilir ve adres olduğu gibi çıkmalı.
         */
        /*
         * DEVİR, BAĞLANTIYI AÇAN TARAYICIYA BAĞLANIR.
         *
         * Devir adresi bir taşıyıcı jetondur; başkasının tarayıcısında
         * açtırılırsa (saldırgan kendi devir adresini kurbana yollar) kurbanın
         * vekil çerezi saldırganın oturumuna çevrilirdi. Oturumu açan
         * tarayıcıya kısa ömürlü, gizli bir çerez bırakılır ve devir yalnızca
         * onunla harcanır. Çerez konak-özeldir (portu umursamaz): vekil panelle
         * aynı konaktaysa gider. Vekil AYRI bir alan adındaysa çerez oraya
         * gidemez; o durumda bağlama yapılamaz ve devir eskisi gibi çalışır.
         */
        $bag = $this->devirBagiGecerliMi($request) ? Str::random(40) : null;
        $devir = $this->oturumlar->devirJetonuUret($oturum['token'], $bag);

        $yanit = redirect()->away(InterfaceSession::taban().InterfaceSession::onek().'/devir/'.$devir);

        return $bag === null
            ? $yanit
            : $yanit->withCookie(Cookie::make(
                InterfaceSession::DEVIR_BAG_COOKIE, $bag, (int) ceil(config('remote.arayuz.devir_omru') / 60) + 1,
                '/', null, true, true, false, 'lax',
            ));
    }

    /** Vekil, paneli açan tarayıcının çerezlerini görecek kadar aynı konakta mı? */
    private function devirBagiGecerliMi(Request $request): bool
    {
        $alan = trim((string) config('remote.arayuz.alan'));

        return $alan === ''
            || strcasecmp((string) preg_replace('/:\d+$/', '', $alan), $request->getHost()) === 0;
    }

    /**
     * Devir: anlık jetonu harcar, vekil kökenine çerezi kurar.
     *
     * Buradan sonra panelin oturum çerezine hiç ihtiyaç yok — ayrı alan
     * adında zaten gelmiyor. Yetki her istekte yeniden sorulur ama kimlik
     * kaydın kendisinden okunur (bkz. InterfaceProxyWatcher).
     */
    public function devral(Request $request, string $devir): RedirectResponse
    {
        $kayit = $this->oturumlar->devriHarca($devir, $request->cookie(InterfaceSession::DEVIR_BAG_COOKIE));

        abort_if($kayit === null, 410, 'Bu bağlantı kullanılmış ya da süresi dolmuş.');

        $omur = (int) ceil($this->oturumlar->omur() / 60);

        /*
         * Adres SONDAKİ EĞİK ÇİZGİYLE verilmeli: arayüzler bütün varlıklarını
         * göreli istiyor ("scripts/main") ve çizgi düşerse hepsi bir üst
         * dizinden, yani oturum kimliği OLMADAN isteniyor; ekran bembeyaz
         * kalıyor.
         */
        return redirect()->away(InterfaceSession::sayfaAdresi(
            (string) $kayit['token'],
            InterfaceType::from((string) $kayit['tur']),
            Credential::query()->find($kayit['credential_id']),
            (int) ($kayit['port'] ?? 0),
        ))
            /*
             * Hedef konağı arayüzün KENDİSİ bu çerezden okuyor (ESXi Host
             * Client); adı onun belirlediği addır ve betikten okunabilmeli.
             */
            ->withCookie(Cookie::make(InterfaceSession::KONAK_COOKIE, $kayit['konak'], $omur, '/', null, true, false))
            /*
             * Oturumun kimliği. HttpOnly: cihazın betiği okuyamaz. Konak-özel
             * (alan verilmez): panelin alan adına hiç gitmez.
             */
            ->withCookie(Cookie::make(InterfaceSession::COOKIE, $kayit['token'], $omur, '/', null, true, true))
            ->withCookies($this->cihazCerezleri($kayit, $omur));
    }

    /**
     * Arayüzün giriş sayfası — cihazdan alınır, açılış betiği eklenir.
     *
     * Sayfanın KENDİSİ cihazdan gelir: Host Client sürümüyle birlikte
     * değişiyor ve panelde kopyasını tutmak, ilk ESXi yükseltmesinde sessizce
     * bozulacak bir kopya tutmak olurdu.
     */
    public function sayfa(Request $request, ?string $token = null): Response
    {
        /*
         * Ayrı kökende arayüz KÖKTE duruyor ve adreste oturum kimliği yok;
         * kimliği çerez taşıyor. Panelin kendi kökeninde ise adres önekli.
         */
        $kayit = $this->dogrula($request, $token ?? (string) $request->cookie(InterfaceSession::COOKIE));
        $tur = InterfaceType::from((string) $kayit['tur']);

        try {
            $yanit = Http::withOptions(['verify' => false])
                ->timeout(20)
                ->get('https://'.$kayit['hedef'].$tur->cihazYolu());
        } catch (Throwable) {
            abort(503, $tur->etiket().' cihazda açılamadı: ulaşılamıyor.');
        }

        abort_unless($yanit->successful(), 503, $tur->etiket().' yanıt vermedi.');

        return response($this->betigiEkle($yanit->body(), $kayit))
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * TEK KULLANIMLIK kimlik.
     *
     * İlk okumadan sonra uç kapanır: sayfa yeniden çizilse bile parola bir
     * daha verilmez. Kasadaki değer kullanıcının ekranına ve panosuna
     * uğramadan doğrudan arayüzün giriş alanına gider.
     */
    public function kimlik(Request $request, ?string $token = null): JsonResponse
    {
        $kayit = $this->dogrula($request, $token ?? (string) $request->cookie(InterfaceSession::COOKIE));

        /*
         * Anahtar KAYITTAKİ kimlikten kurulur, yol parametresinden değil:
         * ayrı kökende adreste kimlik yok (çerezden geliyor) ve parametre boş
         * kalıyordu. Boş kalınca anahtar bütün oturumlarda AYNI oluyor ve ilk
         * açılan oturum diğerlerinin kimliğini de yakıyordu.
         */
        $anahtar = 'eys:arayuz-kimlik-verildi:'.$kayit['token'];

        abort_if(Cache::has($anahtar), 410, 'Kimlik zaten bir kez verildi.');

        Cache::put($anahtar, true, $this->oturumlar->omur());

        $credential = Credential::query()->findOrFail($kayit['credential_id']);
        $sahip = User::query()->find($kayit['user_id']);

        /*
         * Yetki HER istekte yeniden sorulur: oturum açıldıktan sonra kişinin
         * yetkisi geri alınmış ya da hesabı kapatılmış olabilir.
         */
        /* Yetki HER istekte yeniden sorulur; sorulan yetenek "arayuz". */
        abort_if($sahip === null || ! $sahip->isActive() || $sahip->cannot('arayuz', $credential), 403);

        $tur = InterfaceType::from((string) $kayit['tur']);

        /*
         * PAROLA TARAYICIYA GİDİYORSA PAROLA GÖRME YETKİSİ ŞARTTIR.
         *
         * 'arayuz' yeteneği (oturum + görme) parolayı tarayıcıya vermeyi
         * kapsamaz: policy'nin gerekçesi "parola tarayıcıya hiç gitmiyor"
         * idi, ama ESXi'de giriş tarayıcıda yapılıyor ve bu uç parolayı
         * düz metin döndürüyor. Yetkisi 'field.password' olmayan kullanıcı
         * parola gösterme ucundan 403 alıyorsa buradan da alamamalı.
         * VAMI'de parola zaten gönderilmiyor, o yüzden ona
         * ek yetki sorulmaz. Okuma, parola gösterimi gibi kayda geçer.
         */
        if ($tur !== InterfaceType::Vami) {
            abort_if($sahip->cannot('reveal', $credential), 403);

            $credential->loadMissing('group');

            $this->activity->record(
                ActivityAction::Revealed,
                "{$credential->group?->name} · {$credential->name} parolası arayüz girişi için tarayıcıya verildi",
                $credential,
                actor: $sahip,
            );
        }

        /*
         * VAMI'de PAROLA HİÇ GÖNDERİLMEZ.
         *
         * Arayüz oturum jetonunu localStorage'da tutuyor ve jetonu REST
         * ucundan alıyor; o çağrıyı panel SUNUCUDA yapıp yalnızca jetonu
         * veriyor. Parola tarayıcıya hiç uğramıyor — ESXi'de zorunlu olan
         * şeyi burada yapmak gerekmiyor, çünkü orada girişi arayüzün kendi
         * betiği yapmak zorunda (CSRF jetonu istemcide üretiliyor).
         */
        if ($tur === InterfaceType::Vami) {
            return response()
                ->json([
                    'kullanici' => (string) $credential->username,
                    'jeton' => $this->vamiJetonu($kayit, $credential),
                ])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        return response()
            ->json([
                'konak' => $kayit['konak'],
                'kullanici' => (string) $credential->username,
                'parola' => (string) $credential->password,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    /**
     * Arayüzün kendi betiğinin okuması gereken çerezleri tarayıcıya bırakır.
     *
     * Gerekçesi ve ölçümü InterfaceType::tarayiciCerezleri() içinde. Kısaca: iLO
     * oturumun varlığını sunucuya sormuyor, tarayıcıda yokluyor.
     *
     * ŞİFRELENMEZ (bkz. bootstrap/app.php): bunlar cihazın çerezleri, panelin
     * değil. HttpOnly de DEĞİL: arayüzün betiği okuyabilmeli — zaten sebebi bu.
     *
     * Cihazın yolladığı her çerez değil, YALNIZCA türün açıkça saydıkları
     * geçer.
     *
     * @param  array<string, mixed>  $kayit
     * @return array<int, \Symfony\Component\HttpFoundation\Cookie>
     */
    private function cihazCerezleri(array $kayit, int $omur): array
    {
        $izinli = InterfaceType::from((string) $kayit['tur'])->tarayiciCerezleri();

        if ($izinli === []) {
            return [];
        }

        $cerezler = [];

        foreach (explode(';', (string) ($kayit['cerez'] ?? '')) as $parca) {
            [$ad, $deger] = array_pad(explode('=', trim($parca), 2), 2, null);

            if ($deger === null || ! in_array((string) $ad, $izinli, true)) {
                continue;
            }

            $cerezler[] = Cookie::make((string) $ad, $deger, $omur, '/', null, true, false);
        }

        return $cerezler;
    }

    /**
     * VAMI oturum jetonunu SUNUCUDA alır.
     *
     * Arayüz jetonu `POST /rest/com/vmware/cis/session` çağrısından, Basic
     * kimlikle alıyor ve localStorage'a yazıyor. Çağrıyı panel yapınca parola
     * tarayıcıya hiç çıkmıyor; betiğe yalnızca jeton gidiyor.
     *
     * @param  array<string, mixed>  $kayit
     */
    private function vamiJetonu(array $kayit, Credential $credential): string
    {
        try {
            /*
             * GÖVDE BOŞ OLMALI, "[]" DEĞİL.
             *
             * Http::post() veri verilmeyince gövdeye boş bir dizi yazıyor ve
             * VAMI onu "Unexpected error" ile 500'lüyor (aynı çağrı ham boş
             * gövdeyle 200 dönüyor). Arayüzün kendisi de
             * boş dize gönderiyor.
             */
            $yanit = Http::withOptions(['verify' => false])
                ->withBasicAuth((string) $credential->username, (string) $credential->password)
                ->withHeaders(['Accept' => 'application/json'])
                ->withBody('', 'application/json')
                ->timeout(20)
                ->post('https://'.$kayit['hedef'].'/rest/com/vmware/cis/session');
        } catch (Throwable) {
            abort(503, 'Appliance yönetim arayüzüne ulaşılamadı.');
        }

        $jeton = $yanit->json('value');

        abort_unless($yanit->successful() && is_string($jeton) && $jeton !== '', 502, 'Appliance oturumu açılamadı.');

        return $jeton;
    }

    /** @return array<string, mixed> */
    private function dogrula(Request $request, string $token): array
    {
        $kayit = $this->oturumlar->bul($token);

        abort_if($kayit === null, 404, 'Oturum kapanmış.');

        /*
         * Kimlik PANELİN OTURUMUNDAN DEĞİL vekil kökenindeki çerezten gelir:
         * ayrı alan adında panelin çerezi buraya hiç ulaşmıyor, ulaşmamalı da.
         * Çerez HttpOnly ve konak-özeldir; cihazın betiği okuyamaz.
         *
         * KURAL "AYNI OTURUM" DEĞİL "AYNI KİŞİ".
         *
         * Adres ile çerezin aynı oturumu göstermesini aramak yetmez: her
         * arayüz kendi portunda açılır ve aynı tarayıcıda birden çok oturum
         * yaşar; çerez yalnızca EN SON açılanı gösterir, dolayısıyla öteki
         * oturumların kendi betiği bile reddedilirdi (iLO konsolunda
         * "403 /cihaz-oturum/<jeton>/acilis.js").
         *
         * Korunan şey aynı: başkasının bağlantısını ele geçiren biri kendi
         * çereziyle onu açamaz — çerezdeki oturumun SAHİBİ tutmuyorsa 403.
         * PORT TEK BAŞINA KANIT DEĞİLDİR: otuz değerden biri, tahmin
         * edilebilir ve adres çubuğunda görünür. Kanıt çerezdir.
         */
        $cerez = $request->cookie(InterfaceSession::COOKIE);
        $cerezKaydi = is_string($cerez) ? $this->oturumlar->bul($cerez) : null;

        abort_unless(
            $cerezKaydi !== null && (int) $cerezKaydi['user_id'] === (int) $kayit['user_id'],
            403,
        );

        $this->oturumlar->canlandir($kayit);

        return $kayit;
    }

    /**
     * Açılış betiğini sayfaya ekler.
     *
     * Betik yalnızca formu doldurup gönderir; Host Client'ın kendi akışına
     * hiç karışmaz. Giriş başarısızsa cihazın kendi hata metni görünür —
     * panelin uydurduğu bir mesaj değil.
     */
    /** @param  array<string, mixed>  $kayit */
    private function betigiEkle(string $html, array $kayit): string
    {
        $tur = InterfaceType::from((string) $kayit['tur']);
        $taban = InterfaceSession::tabanYolu((string) $kayit['token']);

        /*
         * <base> ŞART. Host Client bütün varlıklarını GÖRELİ istiyor
         * ("scripts/main", "bower_components/..."). Adres sondaki eğik çizgiyi
         * bir yerde kaybederse -- Laravel'in url() üreticisi kırpıyordu, bir
         * ters vekil de kırpabilir -- göreli yollar bir üst dizinden, yani
         * oturum kimliği OLMADAN istenir; hiçbiri bulunamaz ve ekran bembeyaz
         * kalır. Taban adresi belgeye yazınca çizgi olsa da olmasa da doğru
         * yere gidiyorlar.
         */
        /*
         * Açılış betiği AYRI DOSYA, satır içi DEĞİL.
         *
         * Cihazların sayfası kendi Güvenlik İlkesini (CSP) taşıyor ve satır içi
         * betiğe izin vermiyor: VAMI'de — "Executing inline
         * script violates the following Content Security Policy directive
         * 'default-src 'self' data:'". İlkeyi gevşetmek yerine betik AYNI
         * KÖKENDEN veriliyor; 'self' ona zaten izin veriyor. Cihazın kendi
         * koruması olduğu gibi kalıyor.
         */
        $ekler = '<base href="'.e($taban).'">';

        /* Açılış betiği yalnızca girişi TARAYICIDA yapılan arayüzlerde var. */
        /*
         * Betik HER TÜRDE eklenir. İçinde açılış işi olmayabilir (girişi
         * sunucu yapan arayüzlerde yok) ama ayrılış haberi hepsinde var.
         */
        $ekler .= '<script src="'.e(InterfaceSession::acilisAdresi((string) $kayit['token'])).'"></script>';

        $yer = mb_stripos($html, '<head>');
        $html = $yer === false
            ? $ekler.$html
            : mb_substr($html, 0, $yer + 6).$ekler.mb_substr($html, $yer + 6);

        return $html;
    }

    /**
     * Açılış betiği.
     *
     * Sayfanın içine değil AYRI bir adrese konuyor: cihazın Güvenlik İlkesi
     * satır içi betiği reddediyor, aynı kökenden geleni kabul ediyor.
     *
     * HER TÜRDE verilir. Açılış işi yalnızca ESXi ve VAMI'de var (ötekilerde
     * girişi sunucu yapıyor) ama AYRILIŞ HABERİ hepsinde gerekiyor: cihazda
     * açtığımız oturumu cihazda kapatmak bize düşüyor.
     */
    public function acilis(Request $request, ?string $token = null): Response
    {
        $kayit = $this->dogrula($request, $token ?? (string) $request->cookie(InterfaceSession::COOKIE));
        $tur = InterfaceType::from((string) $kayit['tur']);

        $icerik = view('credentials.interface.page-leave', [
            'ayrilisAdresi' => InterfaceSession::ayrilisAdresi(),
            /* vSphere konsolu açılır pencere yerine sekmede açılsın. */
            'sekmeyeAc' => $tur->vsphereMi(),
            /* iLO'nun HTML5 konsol düğmesi kendi başına tetiklenmiyor. */
            'iloKonsolu' => $tur === InterfaceType::Ilo,
            /*
             * Adres çubuğu önek dışına düşerse betik onu geri yazsın.
             *
             * Cihazın MUTLAK bağlantıları (Plesk'in "/admin/subscription/list"
             * gibi) tarayıcıyı önekin dışına götürüyor. Sayfa yine doğru
             * geliyor — oturumu Referer söylüyor — ama bir sonraki isteğin
             * Referer'ı artık öneksiz oluyor ve zincir kopuyor. Betik adresi
             * geri yazınca zincir onarılıyor.
             */
            'onekYolu' => InterfaceSession::ayriKoken() && $tur->yolOnekiKullanir()
                && ($cihaz = Credential::query()->find($kayit['credential_id'])) !== null
                ? InterfaceSession::cihazOneki($cihaz)
                : null,
        ])->render();

        if ($tur->acilisGorunumu() !== null) {
            $icerik .= "\n".view($tur->acilisGorunumu(), [
                'kimlikAdresi' => InterfaceSession::kimlikAdresi((string) $kayit['token']),
            ])->render();
        }

        return response($icerik)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    /**
     * "Sayfadan ayrıldım" haberi (sendBeacon).
     *
     * Oturumu KAPATMAZ, sessiz sayar; gerekçesi InterfaceSession::ayrildi'de.
     * Yanıt gövdesizdir: beacon'ın yanıtı zaten okunmuyor.
     */
    public function ayril(Request $request): Response
    {
        $kayit = $this->oturumlar->bul((string) $request->cookie(InterfaceSession::COOKIE));

        if ($kayit !== null) {
            $this->oturumlar->ayrildi($kayit);
        }

        return response()->noContent();
    }
}
