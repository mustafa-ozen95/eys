<x-layout.app title="Sertifika · Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar"
                            subtitle="Panelin TLS sertifikası. Doğrulama DNS üzerinden yapılır; kaydı bir insan ekler." />

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            <x-ui.panel title="Şu anki sertifika">
                @if ($durum)
                    <dl class="facts">
                        <div class="facts__row">
                            <dt class="facts__key">Veren</dt>
                            <dd class="facts__value">{{ $durum['veren'] ?? 'bilinmiyor' }}</dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Bitiş</dt>
                            <dd class="facts__value mono">{{ $durum['bitis'] ?? '—' }}</dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Kalan</dt>
                            <dd class="facts__value">
                                {{ $durum['kalan_gun'] ?? '—' }} gün
                                @if (($durum['kalan_gun'] ?? 99) < 21)
                                    <x-ui.badge variant="orange">yenileme zamanı</x-ui.badge>
                                @endif
                            </dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Son kontrol</dt>
                            <dd class="facts__value">{{ $durum['kontrol'] ?? '—' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-muted">
                        Sertifika durumu okunamıyor. Kurulum dosyayı konteynere bağlamamış olabilir
                        (<span class="mono">/etc/dc/sertifika-durum.json</span>).
                    </p>
                @endif
            </x-ui.panel>

            {{--
                Sıra: tur başlat → kayıtları DNS'e ekle → onayla.

                Üçü de tek ekranda ve sırayla duruyor; ikinci adımı yapan kişi
                bir belgeye bakmak zorunda kalmasın.
            --}}
            <x-ui.panel title="Yeni tur"
                        subtitle="Sertifikayı yenilemek ya da yeni bir adres eklemek için.">
                <x-slot:actions>
                    @if (true)
                        <form method="POST" data-yerinde action="{{ route("sertifika.baslat") }}"
                              data-confirm="Sunucuda yeni bir doğrulama turu açılacak ve eklenecek TXT kayıtları üretilecek."
                              data-confirm-title="Yeni tur başlat">
                            @csrf
                            <button type="submit" class="btn btn--sm">Yeni tur başlat</button>
                        </form>
                    @endif
                </x-slot:actions>

                @if ($bekliyor)
                    <p class="text-sm">İstek sunucuya iletildi, işleniyor… Sayfayı birkaç saniye sonra yenileyin.</p>
                @endif

                @if ($islem)
                    <p class="text-sm {{ ($islem['durum'] ?? '') === 'hata' ? 'text-danger' : 'text-muted' }}">
                        Son işlem ({{ $islem['eylem'] ?? '—' }}):
                        {{ $islem['mesaj'] ?? '—' }}
                        <span class="text-muted">· {{ $islem['zaman'] ?? '' }} · {{ $islem['isteyen'] ?? '' }}</span>
                    </p>
                @endif
            </x-ui.panel>

            @if ($kayitlar !== null)
                <x-ui.panel title="DNS'e eklenecek kayıtlar"
                            subtitle="Sağlayıcı bölge adını kendisi ekliyorsa addan son kısmı (alan adınızı) çıkarın.">
                    @if ($kayitlar === [])
                        <p class="text-sm">
                            Eklenecek kayıt yok: sertifika sağlayıcısı adresleri zaten doğrulanmış sayıyor.
                            Doğrudan <strong>Onayla</strong> diyebilirsiniz.
                        </p>
                    @else
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Ad (TXT)</th>
                                        <th>Değer</th>
                                        <th>Yayıldı mı</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($yayilma as $kayit)
                                        <tr>
                                            <td data-label="Ad" class="mono">{{ $kayit['ad'] }}</td>
                                            <td data-label="Değer">
                                                <span class="mono text-sm" data-kopya-kaynak>{{ $kayit['deger'] }}</span>
                                                <button type="button" class="btn btn--ghost btn--sm"
                                                        data-kopyala-metin title="Panoya kopyala">Kopyala</button>
                                            </td>
                                            <td data-label="Yayıldı mı">
                                                @if ($kayit['yayildi'])
                                                    <x-ui.badge variant="green">görünüyor</x-ui.badge>
                                                @else
                                                    <x-ui.badge variant="orange">henüz yok</x-ui.badge>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (true)
                        <div class="form-actions">
                            {{--
                                Onayla: panel önce kayıtların yayıldığını KENDİ sorar,
                                sonra sunucuya "tamamla" der. Yayılmamış bir kayıtla
                                gitmek başarısız bir deneme ve baştan başlayan bir tur
                                demek olurdu.
                            --}}
                            <form method="POST" data-yerinde action="{{ route("sertifika.tamamla") }}"
                                  data-confirm="Kayıtların yayıldığı doğrulanacak ve sertifika alınacak."
                                  data-confirm-title="Onayla ve sertifikayı al">
                                @csrf
                                <button type="submit" class="btn btn--primary">Onayla</button>
                            </form>
                        </div>
                    @endif
                </x-ui.panel>
            @endif

            <x-ui.panel title="Neden elle">
                <ul class="text-sm stack">
                    <li>Panel iç ağdaysa sertifika sağlayıcısı adrese bağlanarak doğrulayamaz; bu yüzden DNS doğrulaması kullanılır.</li>
                    <li>Kaydı DNS'e bir insan ekler. Ekran kaydı gösterir, yayıldığını kendisi sorgular.</li>
                    <li><strong>Sertifikayı panel almaz.</strong> ACME hesabı ve özel anahtar sunucuda durur; panel yalnızca istek bırakır. Ele geçirilen bir panel, alan adları için sertifika üretemesin diye.</li>
                    <li>Sertifika birden çok paneli kapsayabilir ve ortak vekilde durur: tur hangi panelden başlatılırsa başlatılsın aynı işi yapar.</li>
                </ul>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
