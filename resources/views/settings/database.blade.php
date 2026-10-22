<x-layout.app title="Veritabanı · Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar"
                            subtitle="Panelin veritabanı: nerede duruyor ve uzak bir sunucuya taşınması." />

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            <x-ui.panel title="Şu anki veritabanı">
                <dl class="facts">
                    <div class="facts__row">
                        <dt class="facts__key">Kaynak</dt>
                        <dd class="facts__value">
                            {{ $kaynak['etiket'] }}
                            <span class="text-sm text-muted">· {{ $kaynak['aciklama'] }}</span>
                        </dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key">Adres</dt>
                        <dd class="facts__value mono">{{ $adres }}</dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key">Sürüm</dt>
                        <dd class="facts__value">{{ $bilgi['surum'] ?? 'okunamadı' }}</dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key">Tablo</dt>
                        <dd class="facts__value">{{ $bilgi['tablo'] ?? '—' }}</dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key">Boyut</dt>
                        <dd class="facts__value">{{ $boyut }}</dd>
                    </div>
                </dl>
            </x-ui.panel>

            @if ($durum)
                <x-ui.panel title="Son taşıma">
                    <dl class="facts">
                        <div class="facts__row">
                            <dt class="facts__key">Durum</dt>
                            <dd class="facts__value"><x-ui.badge :variant="$asama['renk']">{{ $asama['etiket'] }}</x-ui.badge></dd>
                        </div>
                        @if (! empty($durum['aciklama']))
                            <div class="facts__row">
                                <dt class="facts__key">Ayrıntı</dt>
                                <dd class="facts__value">{{ $durum['aciklama'] }}</dd>
                            </div>
                        @endif
                        <div class="facts__row">
                            <dt class="facts__key">Hedef</dt>
                            <dd class="facts__value mono">{{ $durum['hedef'] ?? '—' }}</dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">İsteyen</dt>
                            <dd class="facts__value">{{ $durum['isteyen'] ?? '—' }} · {{ $durum['istenme'] ?? '—' }}</dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Son değişiklik</dt>
                            <dd class="facts__value">{{ $durum['zaman'] ?? '—' }}</dd>
                        </div>
                    </dl>
                </x-ui.panel>
            @endif

            @if ($tasinabilir)
                <x-ui.panel title="Uzak veritabanına taşı"
                            subtitle="Hedef veritabanını ve kullanıcısını önce uzak sunucuda açın; veritabanı boş olmalı.">
                    <form method="POST" action="{{ route('database.test') }}">
                        @csrf
                        <div class="field-row">
                            <x-form.field name="host" label="Sunucu">
                                <x-form.input name="host" :value="$sinanan['host'] ?? null" mono required maxlength="255"
                                              placeholder="db.ornek.local" autocomplete="off" />
                            </x-form.field>
                            <x-form.field name="port" label="Port">
                                <x-form.input name="port" type="number" :value="$sinanan['port'] ?? 3306" mono required min="1" max="65535" />
                            </x-form.field>
                        </div>
                        <div class="field-row">
                            <x-form.field name="database" label="Veritabanı">
                                <x-form.input name="database" :value="$sinanan['database'] ?? null" mono required maxlength="64" autocomplete="off" />
                            </x-form.field>
                            <x-form.field name="username" label="Kullanıcı">
                                <x-form.input name="username" :value="$sinanan['username'] ?? null" mono required maxlength="80" autocomplete="off" />
                            </x-form.field>
                            <x-form.field name="password" label="Parola" hint="Saklanmaz; sınama ve taşıma için kullanılır.">
                                <x-form.input name="password" type="password" required maxlength="200" autocomplete="new-password" />
                            </x-form.field>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn">Bağlantıyı sına</button>
                        </div>
                    </form>

                    @if ($sonuclar)
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Sınama</th>
                                        <th>Sonuç</th>
                                        <th>Ayrıntı</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sonuclar as $madde)
                                        <tr>
                                            <td data-label="Sınama">{{ $madde['ad'] }}</td>
                                            <td data-label="Sonuç">
                                                @if ($madde['tamam'])
                                                    <x-ui.badge variant="green">geçti</x-ui.badge>
                                                @else
                                                    <x-ui.badge variant="red">geçmedi</x-ui.badge>
                                                @endif
                                            </td>
                                            <td data-label="Ayrıntı" class="text-sm">{{ $madde['ayrinti'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($sinanan)
                        {{--
                            Taşı ayrı bir form: sınanan bağlantı oturumda duruyor,
                            parola yeniden istenmiyor. Sunucu sınamaları yine de
                            baştan yapıyor.
                        --}}
                        <div class="form-actions">
                            <form method="POST" action="{{ route('database.migrate') }}"
                                  data-confirm="Panel yeniden başlayacak; şu anki veritabanı {{ $sinanan['adres'] }} hedefine kopyalanacak ve panel bundan sonra orada çalışacak. Kopya bitene kadar panele erişilemez, oturumlar kapanır. Doğrulamak için veritabanı adını yazın."
                                  data-confirm-title="Veritabanını taşı"
                                  data-confirm-variant="danger"
                                  data-confirm-verify="{{ $sinanan['database'] }}">
                                @csrf
                                <button type="submit" class="btn btn--primary">{{ $sinanan['adres'] }} hedefine taşı</button>
                            </form>
                        </div>
                    @endif
                </x-ui.panel>
            @endif

            <x-ui.panel title="Nasıl çalışır">
                <ul class="text-sm stack">
                    <li>Taşıma panel kapalıyken yapılır: panel yeniden başlar, açılışta şu anki veritabanı hedefe kopyalanır. Kopya alınırken kimse yazmadığı için veri tutarlıdır.</li>
                    <li>Kopyadan sonra her tablonun satır sayısı iki tarafta karşılaştırılır. Tutmazsa ya da bir adım başarısız olursa panel şu anki veritabanıyla açılır ve sebep bu sekmede görünür.</li>
                    <li>Taşıma tamamlandıktan sonra panel yalnızca uzak veritabanını kullanır. Uzak sunucu kapanırsa panel açılmayı bekler; eski veritabanı taşıma anındaki hâliyle durduğu için ona kendiliğinden dönülmez.</li>
                    <li>Bağlantı panelin depolama hacminde, uygulama anahtarıyla şifreli durur ve kurulumdaki DB_* ayarlarının yerine geçer.</li>
                    <li>Uzak veritabanının yedeğini o sunucunun yöneticisi alır; panelin hacim yedeği artık veritabanını içermez.</li>
                </ul>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
