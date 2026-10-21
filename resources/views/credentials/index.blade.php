<x-layout.app title="Erişim bilgileri">
    <div class="columns">
        {{-- Gruplar: Grup A, Yedekleme, Portal... Yetkisi olmayan grup listede yoktur. --}}
        <div class="column">
            <div class="column__head">
                <span class="eyebrow">Gruplar</span>
                <span class="column__count">{{ $groups->count() }}</span>
            </div>

            <div class="column__body">
                {{--
                    HEPSİ: gruptan bağımsız, kasanın tamamı. Arama ya da
                    platform süzgeci açıkken de burası seçili görünür, çünkü
                    o durumda da grup daraltması kalkmış olur.
                --}}
                @if ($groups->isNotEmpty())
                    <a href="{{ route('credentials.index', ['grup' => $allGroups]) }}"
                       class="row-item"
                       @if ($selectedGroup === null) aria-selected="true" @endif>
                        <span class="row-item__label">Hepsi</span>
                        <span class="row-item__meta">{{ $allCount }}</span>
                    </a>
                @endif

                @forelse ($groups as $group)
                    <a href="{{ route('credentials.index', ['grup' => $group->uuid]) }}"
                       class="row-item"
                       @if ($selectedGroup?->is($group)) aria-selected="true" @endif>
                        <span class="row-item__label">{{ $group->name }}</span>
                        <span class="row-item__meta">{{ $group->credentials_count }}</span>
                    </a>

                    {{--
                        ALT GRUPLAR KENAR ÇUBUĞUNDA YOK.
                        Kalabalığı azaltmak için geldiler; menüye girseler
                        kalabalık geri gelirdi. Bölmeler listenin içinde
                        başlık olarak durur, başlığa tıklayınca daralır.
                    --}}
                @empty
                    <x-ui.empty title="Grup yok" text="Size henüz bir grup yetkisi verilmemiş." />
                @endforelse
            </div>
        </div>

        {{-- Seçili grubun kayıtları; arama ve süzgeç bütün gruplara yayılır. --}}
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">{{ $selectedGroup?->name ?? ($query ? 'Arama sonucu' : 'Hepsi') }}</span>
                <span class="column__count">{{ $rows->count() }}</span>

                {{--
                    Arama ve platform süzgeci TEK form, ama ayrı kutular.
                    Seçici arama kutusunun içine konduğunda giriş alanını sıfır
                    genişliğe eziyordu (bkz. components/form.css, .filter).
                --}}
                <form method="GET" class="row row--wrap filter-bar">
                    {{-- Aramayı başlatılan yer: arama silinince oraya dönülür (bkz. CredentialListing · aramaGrubu). --}}
                    @if ($aramaGrubu)
                        <input type="hidden" name="grup" value="{{ $aramaGrubu }}">
                    @endif

                    <x-ui.search name="q" :value="$query" :standalone="false"
                                 placeholder="Ad, adres, uplink veya kullanıcı" label="Kayıt filtrele" />

                    <x-form.select name="platform" :options="$platforms" :selected="$platform"
                                   onchange="this.form.submit()" aria-label="Platforma göre süz" />

                    <button type="submit" class="btn btn--sm">Filtrele</button>
                </form>

                <span class="spacer"></span>

                {{--
                    TELEFONDA TOPLU ARAÇLAR KATLI GELİR.

                    Güncelleme ve çıktı şeritleri başlıkta dört satır tutuyor
                    ve ekranın üçte birini kaplıyordu. Dar
                    ekranda bir düğmenin arkasına alınır; geniş ekranda sarmal
                    görünmez (display: contents). Betik yok: onay kutusu
                    tutuyor, CSS açıp kapıyor (bkz. bulk.css).
                --}}
                <input type="checkbox" id="toplu-araclar-ac" class="toplu-araclar__kutu" hidden>
                <label for="toplu-araclar-ac" class="btn btn--sm toplu-araclar__dugme">Toplu işlemler</label>

                <div class="toplu-araclar">
                @can('rotateAny', App\Models\Credential::class)
                    {{--
                        Toplu döndürme. Onay penceresi gerekçeyi sorar ve forma
                        ekler; sunucu gerekçesiz isteği zaten reddeder.
                        Düğme seçim boşken kapalı durur.
                    --}}
                    <form method="POST" action="{{ route('credentials.rotate.bulk') }}" id="toplu-dondur"
                          class="bulk" data-bulk-form
                          data-yerinde
                          data-confirm="Seçili kayıtların parolası UZAKTAKİ MAKİNELERDE değiştirilecek."
                          data-confirm-title="Toplu parola güncelleme"
                          data-confirm-variant="danger">
                        @csrf

                        {{--
                            "Tümü" başlık satırında değil BURADA: başlık satırı
                            telefonda gizleniyor ve seçim oradayken dar ekranda
                            hepsini seçmenin yolu kalmıyordu.
                        --}}
                        {{--
                            Hazır kayıt yoksa kutu KAPALI gelir.
                            Açık bırakıldığında tıklanıyor ama işaretleyecek
                            satır bulamayıp kendini hemen geri kapatıyordu;
                            ekran bozuk görünüyordu. Karar sunucuda: hazır
                            sayısını zaten o biliyor.
                        --}}
                        <label @class(['bulk__all', 'bulk__all--empty' => $rotationReady === 0])>
                            <input type="checkbox" data-bulk-all
                                   @disabled($rotationReady === 0)
                                   @if ($rotationReady === 0) title="Bu listede güncellemeye hazır kayıt yok." @endif
                                   aria-label="Güncellenebilir kayıtların hepsini seç">
                            {{-- Listeye bakan "kaçı hazır" sorusunu tek yerden görsün. --}}
                            <span>Tümü <span class="bulk__ready">{{ $rotationReady }}/{{ $rows->count() }} hazır</span></span>
                        </label>

                        {{-- Sayı parantezin içine YAPIŞIK durmalı; satır sonu boşluk bırakıyordu. --}}
                        <button type="submit" class="btn btn--sm" data-bulk-submit disabled
                        >Seçilenleri güncelle (<span data-bulk-count>0</span>)</button>

                    </form>
                @endcan

                @can('exportAny', App\Models\Credential::class)
                    {{--
                        TOPLU PAROLA ÇIKTISI.

                        Kapsam satır seçimi DEĞİL, ekrandaki süzgeçtir:
                        listedeki onay kutuları döndürmeye ait ve
                        döndürülemeyen kayıtlarda kapalı duruyor — oysa o
                        kayıtların parolası pekâlâ çıktıya girebilir
                        (gerekçesi PasswordExportRequest'te).

                        Form YERİNDE GÖNDERİLMEZ: yanıt bir dosya ve tarayıcı
                        onu kendi indirmeli.
                    --}}
                    {{--
                        Listenin çıktısı: grup görünümünde tek düğme budur.
                        Hepsi'de yalnızca arama/süzgeç listeyi daraltmışsa
                        çizilir; daraltmamışsa "Tüm kasanın çıktısı" ile aynı
                        dosyayı verirdi; aynı sayılı iki düğme gereksizdir.
                    --}}
                    @if ($selectedGroup !== null || $rows->count() < $allCount)
                    <form method="POST" action="{{ route('credentials.export') }}"
                          class="bulk"
                          data-confirm="{{ $rows->count() }} kayıtlık bu listenin PAROLALARI bir Excel dosyasına yazılıp bilgisayarınıza inecek. Dosya panelin dışına çıkar; oradan sonrası panelin denetiminde değildir."
                          data-confirm-title="Parolaları dosyaya çıkar"
                          data-confirm-variant="danger">
                        @csrf

                        <input type="hidden" name="kapsam" value="liste">
                        <input type="hidden" name="grup" value="{{ $selectedGroup?->uuid }}">
                        <input type="hidden" name="q" value="{{ $query }}">
                        <input type="hidden" name="platform" value="{{ $platform }}">

                        <button type="submit" class="btn btn--sm" @disabled($rows->count() === 0)
                        >Excel çıktısı ({{ $rows->count() }})</button>
                    </form>
                    @endif

                    {{--
                        Kasanın TAMAMI: ekrandaki gruba, aramaya ve süzgece
                        bakmaz. Ayrı form, ayrı onay ve YAZILI doğrulama —
                        yanlışlıkla basılacak bir düğme değil.

                        Yalnızca HEPSİ görünümünde: grup görünümünde "tüm
                        kasa" düğmesi yanıltıcı olurdu.
                    --}}
                    @if ($selectedGroup === null && $allCount > 0)
                    <form method="POST" action="{{ route('credentials.export') }}"
                          class="bulk"
                          data-confirm="Görebildiğiniz BÜTÜN kayıtların ({{ $allCount }}) parolası bir Excel dosyasına yazılıp bilgisayarınıza inecek. Bu işlem ekrandaki grubu ve süzgeci kapsamaz — kasanın tamamıdır."
                          data-confirm-title="Tüm kasayı dosyaya çıkar"
                          data-confirm-variant="danger"
                          data-confirm-verify="TÜMÜ">
                        @csrf

                        <input type="hidden" name="kapsam" value="tumu">

                        <button type="submit" class="btn btn--sm btn--danger"
                        >Tüm kasanın çıktısı ({{ $allCount }})</button>
                    </form>
                    @endif
                @endcan
                </div>

                @if ($eklenebilir)
                    {{--
                        Bağlantı hem düğme hem adrestir: betik yüklüyse pencereyi
                        yerinde açar, yüklü değilse (ya da pencere o an sayfada
                        basılı değilse) aynı ekranı pencere açık olarak getirir.

                        Düğme, BAKILAN GRUBA yazma yetkisi varsa çıkar
                        (bkz. CredentialListing · 'eklenebilir').
                    --}}
                    <a href="{{ route('credentials.index', ['grup' => $selectedGroup?->uuid, 'pencere' => 'ekle']) }}"
                       class="btn btn--primary btn--sm" data-modal-open="kayit-ekle">Yeni kayıt</a>
                @endif
            </div>

            {{--
                Toplu tur kartı KAYAN listenin DIŞINDA: liste gövdesi sağda
                kaydırma çubuğuna yer ayırıyor ve kart içerideyken solda
                yapışık, sağda boşluklu duruyordu.
                Dışarıda iki yanı eşit durur ve liste kaysa da görünür kalır.
            --}}
            @if ($topluTur)
                @include('credentials.partials.bulk-progress', ['tur' => $topluTur])
            @endif

            <div class="column__body">
                {{--
                    Kimlik sütunları yoksa ızgara da daralır: boş bırakılan
                    iki kolon, adres ve platformun daralmasından başka bir
                    işe yaramazdı.
                --}}
                <div @class(['grid-row__head', 'credential-row', 'credential-row__head', 'credential-row--kimliksiz' => ! $kimlikSutunlari, 'credential-row--uplink' => $uplinkSutunu])>
                    <span class="credential-row__sec"></span>
                    <span class="credential-row__ad">Kayıt</span>
                    <span class="credential-row__tur">Tür</span>
                    <span class="credential-row__platform">Platform</span>
                    <span class="credential-row__adres">Adres</span>
                    @if ($uplinkSutunu)
                        <span class="credential-row__uplink">Uplink</span>
                    @endif
                    @if ($kimlikSutunlari)
                        <span class="credential-row__kullanici">Kullanıcı</span>
                        <span class="credential-row__parola">Parola</span>
                    @endif
                </div>

                {{--
                    BÖLÜMLER: grubun alt grupları varsa satırlar başlıklar
                    altında dizilir (bkz. CredentialListing · bolumler).
                    Başlık TIKLANMAZ, yalnızca listeler.
                --}}
                {{--
                    ALT GRUP BÖLÜMLERİ ana grubun içinde sürüklenerek sıralanır,
                    kayıtlar da kendi bölümlerinin içinde (iki ayrı liste: bir
                    kayıt başka bölüme, bir bölüm başka gruba bırakılamaz).
                    Yalnızca yönetici; her bırakış onay penceresi açar
                    (bkz. sorting.js). "Alt grupsuz" en sonda sabit durur.
                --}}
                <div class="credential-bolumler" @if ($bolumSirasi) data-sira="{{ $bolumSirasi }}" data-sira-alan="sira" @endif>
                    @foreach ($bolumler as $bolum)
                        @if ($bolum['uuid'] !== null)
                            <div class="credential-bolum-kap" @if ($bolumSirasi) draggable="true" data-sira-kimlik="{{ $bolum['uuid'] }}" @endif>
                                @include('credentials.partials.section')
                            </div>
                        @endif
                    @endforeach
                </div>

                @foreach ($bolumler as $bolum)
                    @if ($bolum['uuid'] === null)
                        @include('credentials.partials.section')
                    @endif
                @endforeach
            </div>
        </div>

        @if ($selected)
            <aside class="detail">
                <div class="detail__head">
                    <div class="detail__title detail__title--plain">{{ $selected['name'] }}</div>
                    <p class="detail__subtitle">{{ $selected['groupName'] }}</p>

                    @if ($selected['canRotate'] && ($selected['runningRotation'] || $selected['pendingRotation']))
                        {{--
                            SÜREN TUR EN ÜSTTE.

                            Çubuk önce "Parola güncelleme" bölümünün içindeydi;
                            orası panelin en altı ve dar ekranda ERİŞİM, NOT,
                            KONSOL ve PORTAL bölümlerinin arkasında kalıyor.
                            Dar bir telefon ekranında (412×915) çubuk
                            görüntü alanının tamamen dışındaydı — kullanıcı
                            kaydırmadan işin sürdüğünü göremiyordu, ki çubuğun
                            tek varlık sebebi buydu.

                            İlk doluluk sunucudan gelir; gerisini yoklama
                            devralır (bkz. rotation-progress.js).
                        --}}
                        <div class="ilerleme detail__ilerleme"
                             data-ilerleme
                             data-ilerleme-adres="{{ route('credentials.rotate.progress', $selected['model']) }}">
                            <div class="ilerleme__ust">
                                <span class="ilerleme__metin" data-ilerleme-metin>{{ $selected['runningRotation']?->stage->running() ?? 'Sıraya alındı, işçi bekleniyor…' }}</span>
                                <span class="ilerleme__adim" data-ilerleme-adim>{{ $selected['runningRotation'] ? $selected['runningRotation']->completedSteps().'/'.$selected['runningRotation']->totalSteps() : '0/'.$selected['pendingSteps'] }}</span>
                            </div>

                            <div class="ilerleme__yol"
                                 role="progressbar"
                                 aria-label="Parola güncelleme ilerlemesi"
                                 aria-valuemin="0"
                                 aria-valuemax="100"
                                 aria-valuenow="{{ $selected['runningRotation']?->progress() ?? 4 }}">
                                <div class="ilerleme__cubuk" data-ilerleme-cubuk data-baslangic="{{ $selected['runningRotation']?->progress() ?? 4 }}"></div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="detail__body">
                    <div class="detail__section">
                        <span class="eyebrow">Erişim</span>
                        <x-ui.facts class="detail__block" :rows="$selected['facts']" />

                        {{--
                            GÖREMEDİĞİ ALAN HİÇ ÇİZİLMEZ.

                            "Kayıtlı ama yetkiniz yok" diye bir satır
                            basılmaz. İki sorunu olurdu: kullanıcının
                            yapabileceği bir şey yok (yetkiyi kendisi
                            veremiyor) ve ekranı, işine yaramayan iki satırla
                            uzatır. Kimlik bilgisi zaten gerekmiyor —
                            panel arayüzü ve konsolu parolayı GÖSTERMEDEN
                            açıyor.

                            "KAYITLI DEĞİL" DE ÇİZİLMEZ artık — ama yalnızca
                            İKİSİ BİRDEN boşsa. Lisans ve üyelik kayıtlarının
                            çoğunda ne kullanıcı adı ne parola var; orada iki
                            başlık altına "yok" yazmak ekranı, hiçbir şey
                            anlatmayan dört satırla uzatıyordu. Kaydın yarısı
                            doluysa eksik olan yarı yine söylenir: o zaman
                            "yok" bir bilgi, çünkü orada bir şey OLMASI
                            beklenir.
                        --}}
                        @if ($selected['kimlikVar'] && ($selected['username'] || ! $selected['hasUsername']))
                            <div class="detail__block">
                                <span class="eyebrow">Kullanıcı adı</span>

                                @if ($selected['username'])
                                    <x-ui.copyable label="Kullanıcı adı">{{ $selected['username'] }}</x-ui.copyable>
                                @else
                                    <p class="text-muted text-sm">Kullanıcı adı yok.</p>
                                @endif
                            </div>
                        @endif

                        @if ($selected['kimlikVar'] && ($selected['canReveal'] || ! $selected['hasPassword']))
                            <div class="detail__block">
                                <span class="eyebrow">Parola</span>

                                @if (! $selected['hasPassword'])
                                    <p class="text-muted text-sm">Kayıtlı parola yok.</p>
                                @else
                                    {{--
                                        Parola sayfa kaynağında yok: göz
                                        simgesine basılınca ayrı bir istekle
                                        alınır ve o istek sunucuda kayda geçer.
                                    --}}
                                    <x-ui.secret :source="route('ajax.credentials.password', $selected['model'])" />
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($selected['notes'])
                        <div class="detail__section">
                            <span class="eyebrow">Not</span>
                            <p class="credential-note">{{ $selected['notes'] }}</p>
                        </div>
                    @endif

                @if ($selected['canConnect'])
                    <div class="detail__section">
                        <span class="eyebrow">Konsol</span>

                        <x-ui.hint baslik="Konsol nasıl açılıyor?">
                            Bağlantıyı panel kurar; ekran görüntü olarak tarayıcınıza taşınır.
                            Parola size gösterilmez ve girmeniz gerekmez — bu yüzden konsol açmak
                            parolayı görme yetkisi istemez. Açılış ve kapanış kayda geçer.
                        </x-ui.hint>

                        @if ($selected['consoleReady'])
                            <p class="text-muted text-sm">
                                {{ $selected['consoleProtocol'] }} oturumu tarayıcıda açılır; parola ekrana ve
                                panoya HİÇ uğramaz. Açılış ve kapanış kayda geçer.
                                @if ($selected['gerekceIster']) Bu kayıt kritik işaretli: açmadan önce gerekçe sorulur. @endif
                            </p>

                            <button type="button" class="btn btn--sm detail__block"
                                    data-uzak-baglan="{{ route('ajax.remote.connect') }}"
                                    data-uzak-kimlik="{{ $selected['uuid'] }}"
                                    {{--
                                        Onay penceresi YALNIZCA gerekçe sorulacaksa açılır.
                                        Soracak bir şeyi olmayan pencere, "Tamam"a basmaktan
                                        başka bir iş yaptırmıyordu; koruma değil, tıklamaydı.
                                        Yazılı onay isteyen tehlikeli işlemlerde (döndürme,
                                        silme) pencere yerinde duruyor.
                                    --}}
                                    @if ($selected['gerekceIster'])
                                        data-confirm="{{ $selected['name'] }} için konsol açılacak."
                                        data-confirm-title="Konsolu aç"
                                    @endif
                                    >Konsolu aç</button>
                        @else
                            <p class="text-muted text-sm">
                                Bu kayıt konsoldan açılamıyor: yalnızca SSH ve uzak masaüstü kayıtları,
                                adresi ve kimlik bilgisi tam olanlar açılabilir.
                            </p>
                        @endif
                    </div>
                @endif

                {{--
                    İKİ AYRI YETENEK, iki ayrı yol.

                    Arayüz vekilinde oturum SUNUCUDA açılıyor ve parola
                    tarayıcıya hiç gitmiyor; orada parolayı GÖRME yetkisi
                    aramak, var olmayan bir sızıntıya karşı önlem almak olurdu
                    — üstelik özelliğin amacı tam tersi: parolayı bilmeden
                    erişebilmek. Portal fırlatmada ise parola tarayıcıdan
                    gönderiliyor, orada görme yetkisi aranıyor.

                    Gerekçe: CredentialPolicy::arayuz.
                --}}
                @if ($selected['arayuzTuru'] ? $selected['canArayuz'] : $selected['canSession'])
                    <div class="detail__section">
                        <span class="eyebrow">{{ $selected['arayuzTuru'] ? 'Arayüz oturumu' : 'Portal oturumu' }}</span>

                        <x-ui.hint baslik="Bu bağlantı nasıl kuruluyor?">
                            {{--
                                KISA TUTULUR. Burada yolun tekniğini anlatmak, meraklı bir kullanıcıya
                                "parola şurada, bak" demekten farksız olurdu. Kullanıcının
                                bilmesi gereken tek şey parolayı yazıp yazmayacağı ve işlemin kayda
                                geçtiği.
                            --}}
                            @if ($selected['arayuzTuru'])
                                Oturumu panel sizin için açar; parola size hiç gösterilmez ve
                                girmeniz gerekmez. Giriş, çıkış ve yapılan işlemler kayda geçer.
                            @else
                                Panel hedefin giriş adımını sizin adınıza tamamlar; parola ekrana ve
                                panoya basılmaz. Bu yol parolayı görme yetkisi gerektirir.
                                Her açış kayda geçer.
                            @endif
                        </x-ui.hint>

                        @if ($selected['arayuzTuru'])
                            {{--
                                Bu arayüzler forma gönderilebilen bir giriş
                                kabul etmiyor (ESXi CSRF jetonunu istemcide
                                üretiyor, VAMI jetonu localStorage'da tutuyor;
                                ölçümler InterfaceType'nde). Bu yüzden arayüzün
                                KENDİSİ panelin kökeninden vekilleniyor ve
                                oturum orada açılıyor. Parola yine ekrana
                                çıkmaz.
                            --}}
                            <p class="text-muted text-sm">
                                Arayüz panel üzerinden açılır ve oturum sizin için açılır; parola ekrana ve
                                panoya HİÇ uğramaz. Giriş, çıkış ve arayüzde yapılan işlemler kayda geçer.
                                @if ($selected['gerekceIster']) Bu kayıt kritik işaretli: açmadan önce gerekçe sorulur. @endif
                            </p>

                            <form method="POST" action="{{ route('credentials.arayuz', $selected['model']) }}"
                                  target="_blank" rel="noopener" class="detail__block"
                                  data-yeni-pencere data-pencere-adi="{{ $selected['model']->uuid }}"
                                  @if ($selected['gerekceIster'])
                                      data-confirm="{{ $selected['name'] }} için {{ $selected['arayuzTuru']->etiket() }} açılacak."
                                      data-confirm-title="Oturum aç"
                                  @endif
                                  >
                                @csrf
                                <button type="submit" class="btn btn--sm">Oturum aç</button>
                            </form>
                        @elseif ($selected['portalReady'])
                            <p class="text-muted text-sm">
                                Parola ekrana ve panoya HİÇ uğramaz; doğrudan cihazın giriş ucuna
                                gönderilir. Her açış kayda geçer.
                                @if ($selected['gerekceIster']) Bu kayıt kritik işaretli: açmadan önce gerekçe sorulur. @endif
                            </p>

                            {{--
                                target="_blank": fırlatma sayfası ayrı sekmede açılır ve orada kalır.

                                data-portal-landing yalnızca giriş isteğine sayfa değil veri
                                döndüren cihazlarda dolu olur (iLO'da JSON); o sekme, çerez
                                yazıldıktan sonra buradaki adrese devredilir. vCenter'da
                                gerekmez: kimlik sağlayıcının yanıtı kendi kendini yollayan
                                bir formdur ve tarayıcıyı arayüze o götürür.
                            --}}
                            <form method="POST" action="{{ route('credentials.session', $selected['model']) }}"
                                  target="_blank" rel="noopener" class="detail__block"
                                  data-yeni-pencere data-pencere-adi="{{ $selected['model']->uuid }}"
                                  @if ($selected['portalLanding']) data-portal-landing="{{ $selected['portalLanding'] }}" @endif
                                  @if ($selected['portalLandingBekle'] ?? null) data-portal-landing-bekle="{{ $selected['portalLandingBekle'] }}" @endif
                                  @if ($selected['portalOnceki']) data-portal-onceki="{{ $selected['portalOnceki'] }}" @endif
                                  @if ($selected['gerekceIster'])
                                      data-confirm="{{ $selected['name'] }} için portal oturumu açılacak."
                                      data-confirm-title="Portal oturumu aç"
                                  @endif
                                  >
                                @csrf
                                <button type="submit" class="btn btn--sm">Oturum aç</button>
                            </form>
                        @elseif ($selected['webUrl'])
                            {{--
                                Giriş formu da, bilinen bir giriş ucu da olmayan
                                arayüzler. vCenter ARTIK buraya düşmez: oturumu
                                SAML kimlik sağlayıcısından açılıyor (bkz.
                                PortalLogin::vcenter). ESXi ve VAMI de düşmez:
                                onların arayüzü vekilleniyor (bkz. InterfaceType).
                                Geriye kayıtta
                                giriş adresi yazılmamış sıradan web arayüzleri
                                kalıyor. Onlarda da "giriş adresini girin" DENMEZ:
                                arayüzün form girişi olup olmadığını buradan
                                bilemeyiz ve yanlış yol tarif etmiş oluruz.
                                Yapılabilecek en dürüst şey arayüzü açmak,
                                parolayı da panodan vermek.
                            --}}
                            <p class="text-muted text-sm">
                                Bu kayıt için tek tıkla oturum açılamıyor.
                                Arayüzü açıp parolayı yukarıdaki kopyala düğmesiyle yapıştırabilirsiniz.
                            </p>

                            <a href="{{ $selected['webUrl'] }}" target="_blank" rel="noopener noreferrer"
                               class="btn btn--sm detail__block">Arayüzü aç</a>
                        @else
                            <p class="text-muted text-sm">
                                Bu kayıt için giriş formu tanımlı değil. Düzenle ekranından giriş adresini ve
                                alan adlarını girin.
                            </p>
                        @endif
                    </div>
                @elseif ($selected['webUrl'])
                    {{--
                        OTURUM YETKİSİ OLMAYANA DA ARAYÜZÜ AÇ.

                        Yukarıdaki bölüm oturum AÇMAKLA ilgili: vekil arayüzü ya
                        da portal fırlatma. İkisi de parolayı kullanıyor, o
                        yüzden yetki istiyorlar. Ama bir adresi yeni sekmede
                        açmak parola kullanmaz — düz bir bağlantıdır ve kaydın
                        adresini zaten görebilen biri onu kopyalayıp yapıştırmak
                        dışında bir şey yapmıyor.

                        Düğme bölümün İÇİNDE durduğu sürece oturum yetkisi
                        olmayan hiç göremiyordu: sürücüsü olmayan sıradan bir
                        web arayüzü için (ör. bir izleme paneli) elde bağlantı vardı ama
                        tıklanacak yer yoktu.

                        webUrl zaten "adresi görme" yetkisine bağlı
                        (CredentialListing::webUrl); yetkisi olmayana null
                        döner ve bu blok hiç çizilmez.
                    --}}
                    <div class="detail__section">
                        <span class="eyebrow">Arayüz</span>

                        <x-ui.hint baslik="Bu düğme ne yapıyor?">
                            Adresi yeni sekmede açar, o kadar. Oturum açmaz: kullanıcı adını ve parolayı
                            karşı tarafa kendiniz girersiniz.
                        </x-ui.hint>

                        <p class="text-muted text-sm">
                            Bu kayıt için panelden oturum açılamıyor; arayüz yeni sekmede açılır.
                        </p>

                        <a href="{{ $selected['webUrl'] }}" target="_blank" rel="noopener noreferrer"
                           class="btn btn--sm detail__block">Arayüzü aç</a>
                    </div>
                @endif

                @if ($selected['canRotate'])
                    <div class="detail__section">
                        <span class="eyebrow">Parola güncelleme</span>

                        @if ($selected['dondurmeGereksinimi'])
                            <x-ui.hint baslik="Bu kayıtta parola güncelleme neyi gerektiriyor?">
                                {{ $selected['dondurmeGereksinimi'] }}
                            </x-ui.hint>
                        @endif

                        @if ($selected['stuckRotation'])
                            {{--
                                Makinede kurtarma hesabı duruyor. Bu, açıkta
                                bekleyen fazladan bir yetkidir; ekran bunu
                                susarak geçmez.
                            --}}
                            <div class="alert alert--danger detail__block">
                                <div><strong>Yarıda kalmış tur:</strong> {{ $selected['stuckRotation']->stage->label() }}</div>
                                <div class="text-sm">{{ $selected['stuckRotation']->stage->hint() }}</div>
                                <div class="text-sm mono">Kurtarma hesabı: {{ $selected['stuckRotation']->recovery_username }}</div>
                            </div>

                            {{-- Kapatma işi sıradayken düğme yok: aşağıdaki ilerleme çubuğu bekler, iki kez basılmaz. --}}
                            @unless ($selected['pendingRotation'])
                            <form method="POST"
                                  action="{{ route('credentials.rotate.repair', [$selected['model'], $selected['stuckRotation']]) }}"
                                  class="detail__block"
                                  data-yerinde
                                  data-confirm="Kurtarma hesabı makineden silinecek ve kasadaki parola makinedekine hizalanacak."
                                  data-confirm-title="Turu kapat"
                                  data-confirm-verify="{{ $selected['confirmVerify'] }}"
                                  @unless ($selected['gerekceIster']) data-confirm-reasonless @endunless>
                                @csrf
                                <button type="submit" class="btn btn--danger btn--sm">Turu kapat</button>
                            </form>
                            @endunless
                        @endif

                        @if ($selected['runningRotation'] || $selected['pendingRotation'])
                            {{--
                                Tur SÜRÜYOR. Düğme gösterilmez: ikinci kez
                                başlatmak kilide takılıp "zaten sürüyor" diye
                                düşerdi ve kullanıcı bunu hata sanardı.

                                Çubuğun kendisi panelin BAŞINDA duruyor (bkz.
                                detail__head); burada yalnızca düğme gizlenir.
                            --}}
                            <p class="text-muted text-sm">Tur sürüyor; bitince sonucu burada göreceksiniz.</p>
                        @elseif ($selected['rotationBlocked'])
                            <p class="text-muted text-sm">{{ $selected['rotationBlocked'] }}</p>
                        @else
                            <p class="text-muted text-sm">
                                Uzaktaki makinede parola değiştirilir, yeni parolayla giriş denenir ve
                                ancak doğrulanırsa kasaya yazılır.
                                @if ($selected['rotatedAt'])
                                    Son güncelleme: {{ $selected['rotatedAt']->translatedFormat('d.m.Y H:i') }}.
                                @endif
                            </p>

                            <form method="POST" action="{{ route('credentials.rotate', $selected['model']) }}"
                                  class="detail__block"
                                  data-yerinde
                                  data-confirm="{{ $selected['name'] }} kaydının parolası UZAKTAKİ MAKİNEDE değiştirilecek."
                                  data-confirm-title="Parolayı güncelle"
                                  data-confirm-verify="{{ $selected['confirmVerify'] }}"
                                  @unless ($selected['gerekceIster']) data-confirm-reasonless @endunless>
                                @csrf
                                <button type="submit" class="btn btn--sm">Parolayı güncelle</button>
                            </form>
                        @endif

                        {{--
                            Yalnızca SON tur. Geçmişin tamamı kayıtlar
                            ekranında, bu kayda süzülmüş olarak durur.
                        --}}
                        @if ($selected['rotations']->isNotEmpty())
                            <dl class="facts detail__block">
                                @foreach ($selected['rotations'] as $rotation)
                                    <div class="facts__row">
                                        <dt class="facts__key">{{ $rotation->created_at->translatedFormat('d.m.Y H:i') }}</dt>
                                        <dd class="facts__value">
                                            <x-ui.badge :variant="$rotation->status->badge()">{{ $rotation->status->label() }}</x-ui.badge>
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        @can('viewAny', App\Models\ActivityLog::class)
                            <a href="{{ route('logs.index', ['kayit' => $selected['uuid']]) }}"
                               class="btn btn--sm detail__block">Tümü</a>
                        @endcan
                    </div>
                @endif
                </div>

                <div class="detail__foot">
                    @if ($selected['canUpdate'])
                        {{-- Süzgeç adreste taşınır: betiksiz gidilen yol da aynı listeye dönmeli. --}}
                        <a href="{{ route('credentials.index', ['grup' => $selectedGroup?->uuid, 'q' => $query ?: null, 'platform' => $platform ?: null, 'kayit' => $selected['uuid'], 'pencere' => 'duzenle']) }}"
                           class="btn btn--sm" data-modal-open="kayit-duzenle">Düzenle</a>
                    @endif

                    @if ($selected['canDelete'])
                        <x-ui.delete-form :action="route('credentials.destroy', $selected['model'])"
                                          confirm="{{ $selected['name'] }} kaydı silinecek."
                                          title="Kaydı sil"
                                          verify="{{ $selected['confirmVerify'] }}"
                                          :reasonless="! $selected['gerekceIster']" />
                    @endif
                </div>
            </aside>
        @endif
    </div>

    {{--
        Pencereler ızgaranın dışında durur: içeride kalsalardı kaydırılan
        sütunun taşma kuralları sabit konumlu kutuyu kırpardı.

        Doğrulama hatasından sonra YALNIZCA hatayı veren pencere basılır.
        İkisi birden basılsaydı eski girdi ikisini de doldururdu ve kullanıcı
        ekleme formunun değerlerini düzenleme formunda görürdü — oradan
        kaydetmek başka bir kaydın üzerine yazmak olurdu.
    --}}
    @if ($eklenebilir)
        @if ($window !== 'duzenle')
            <x-credentials.modal id="kayit-ekle"
                                 window="ekle"
                                 title="Yeni erişim kaydı"
                                 :action="route('credentials.store')"
                                 :open="$window === 'ekle'"
                                 :selected-group="$selectedGroup?->uuid"
                                 :group-options="$groupOptions"
                                 :alt-grup-options="$altGrupOptions"
                                 :alt-grup-haritasi="$altGrupHaritasi"
                                 :access-types="$accessTypes"
                                 :operating-systems="$operatingSystems"
                                 :can-restrict="$canRestrict"
                                 :restrictable-roles="$restrictableRoles"
                                 :rotation-drivers="$rotationDrivers"
                                 :rotation-credential-options="$rotationCredentialOptions"
                                 :makine-hesabi-surucileri="$makineHesabiSurucileri" />
        @endif
    @endif

    @if ($selected && $selected['canUpdate'] && $window !== 'ekle')
        <x-credentials.modal id="kayit-duzenle"
                             window="duzenle"
                             :title="$selected['name']"
                             :action="route('credentials.update', $selected['model'])"
                             method="PUT"
                             submit="Güncelle"
                             :open="$window === 'duzenle'"
                             :credential="$selected['model']"
                             :visible-fields="$selected['fields']"
                             :locked-fields="$selected['kilitliAlanlar']"
                             :group-options="$updateGroupOptions"
                             :alt-grup-options="$altGrupOptions"
                             :alt-grup-haritasi="$altGrupHaritasi"
                             :access-types="$accessTypes"
                             :operating-systems="$operatingSystems"
                             {{--
                                 Kısıt ve ek izin kutucukları BURADAN geçiyor.
                                 Geçilmediği sürece bileşenin varsayılanı
                                 (canRestrict = false) kazanıyor ve iki bölüm
                                 de hiç çizilmiyordu: yetki vardı, ekranı
                                 yoktu.
                             --}}
                             :can-restrict="$canRestrict"
                             :restrictable-roles="$restrictableRoles"
                             :restricted-role-uuids="$selected['restrictedRoleUuids']"
                             :granted-role-uuids="$selected['grantedRoleUuids']"
                             :konsol-role-uuids="$selected['konsolRoleUuids']"
                             :gorme-role-uuids="$selected['gormeRoleUuids']"
                             :rol-gorunum="$selected['rolGorunum']"
                             :portal-role-uuids="$selected['portalRoleUuids']"
                             :rotation-drivers="$rotationDrivers"
                             {{--
                                 DÜZENLEME PENCERESİ KAYDIN KENDİ GRUBUNU öne
                                 alır: aramada ve "hepsi" görünümünde bakılan
                                 bir grup yoktur ama kaydın grubu vardır.
                             --}}
                             :rotation-credential-options="$updateRotationCredentialOptions"
                             :makine-hesabi-surucileri="$makineHesabiSurucileri" />
    @endif
</x-layout.app>
