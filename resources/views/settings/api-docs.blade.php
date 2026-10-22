<x-layout.app title="API belgesi · Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="API belgesi"
                            subtitle="Uç listesi rotalardan üretilir; burada elle yazılmış ikinci bir liste yoktur.">
                <x-slot:actions>
                    <a href="{{ route('api-tokens.index') }}" class="btn btn--sm">Jetonlar</a>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.tabs :items="$sections" />

            <x-ui.panel title="Kimlik doğrulama"
                        subtitle="Oturum çerezi kabul edilmez; kimlik yalnızca jetondan gelir.">
                <div class="stack">
                    <p class="text-sm">Her istek <span class="mono">Authorization</span> başlığı taşır:</p>

                    <pre class="code-block mono"><code>curl -H "Authorization: Bearer {{ $prefix }}_&lt;sır&gt;" \
     -H "Accept: application/json" \
     {{ $baseUrl }}/records</code></pre>

                    <dl class="facts">
                        <div class="facts__row">
                            <dt class="facts__key">Taban adres</dt>
                            <dd class="facts__value mono">{{ $baseUrl }}</dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Hız sınırı</dt>
                            <dd class="facts__value">Dakikada {{ $rateLimit }} istek · <strong>jeton başına</strong>, IP başına değil</dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Kimlikler</dt>
                            <dd class="facts__value">Kayıtlar <span class="mono">uuid</span> taşır; sıralı birincil anahtar dışarı çıkmaz</dd>
                        </div>
                    </dl>
                </div>
            </x-ui.panel>

            {{--
                Jetonun yetkisi tek başına yetmez: sahibinin panel yetkisi de
                aynı işi kapsamak zorunda. Bu, belgeye ait bir ayrıntı değil,
                API'nin temel kuralı.
            --}}
            <x-ui.panel title="Yetki"
                        subtitle="Jeton sahibi adına konuşur ve onu AŞAMAZ.">
                <ul class="text-sm stack">
                    <li>Bir uca erişmek için jeton o kaynağın yetkisini taşımalı.</li>
                    <li>Jetonun <strong>sahibi</strong> de aynı yetkiye sahip olmalı. İkisinin kesişimi geçerlidir.</li>
                    <li>Sahibinin yetkisi daraltıldığında jeton da daralır; jeton unutulmuş bir arka kapıya dönüşmez.</li>
                    <li>Yöneticinin jetonu da yalnızca kendi listesindekini yapar.</li>
                </ul>
            </x-ui.panel>

            <x-ui.panel title="Yanıt kodları">
                <dl class="facts">
                    <div class="facts__row">
                        <dt class="facts__key mono">200</dt>
                        <dd class="facts__value">Başarılı.</dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key mono">401</dt>
                        <dd class="facts__value">Jeton yok, geçersiz, iptal edilmiş, süresi dolmuş ya da adres izinli değil.</dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key mono">403</dt>
                        <dd class="facts__value">Jetonun ya da sahibinin yetkisi yetmiyor.</dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key mono">404</dt>
                        <dd class="facts__value">Kayıt yok ya da görme yetkisi yok; ikisi ayırt edilmez.</dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key mono">429</dt>
                        <dd class="facts__value">Hız sınırı aşıldı. <span class="mono">Retry-After</span> başlığı bekleme süresini verir.</dd>
                    </div>
                </dl>
            </x-ui.panel>

            <x-ui.panel title="Uçlar" flush>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Uç</th>
                                <th>Gereken yetki</th>
                                <th>Parametreler</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($endpoints as $endpoint)
                                <tr>
                                    <td data-label="Uç">
                                        <div class="mono">
                                            <span class="text-muted">{{ $endpoint['method'] }}</span>
                                            {{ $endpoint['uri'] }}
                                        </div>
                                        <div class="text-sm">{{ $endpoint['summary'] }}</div>
                                        @if ($endpoint['description'])
                                            <div class="text-sm text-muted">{{ $endpoint['description'] }}</div>
                                        @endif
                                    </td>
                                    <td data-label="Gereken yetki">
                                        @if ($endpoint['ability'])
                                            <div class="mono text-sm">{{ $endpoint['ability'] }}</div>
                                            <div class="text-sm text-muted">{{ $endpoint['abilityLabel'] }}</div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td data-label="Parametreler">
                                        @forelse ($endpoint['parameters'] as $ad => $aciklama)
                                            <div class="text-sm">
                                                <span class="mono">{{ $ad }}</span>
                                                <span class="text-muted">— {{ $aciklama }}</span>
                                            </div>
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>

            <x-ui.panel title="Adres sınırı ve ömür"
                        subtitle="Jeton ekranından her jeton için ayrı ayarlanır.">
                <ul class="text-sm stack">
                    <li><strong>İzinli adresler:</strong> tek IP ya da ağ (<span class="mono">192.0.2.0/24</span>). Boş bırakılırsa her yerden kabul edilir.</li>
                    <li><strong>Ömür:</strong> gün cinsinden. <span class="mono">0</span> ya da boş bırakmak süresiz yapar; süresiz jeton unutulur, unutulan jeton sızar.</li>
                    <li><strong>Yeni sır:</strong> üretildiği anda eski jeton geçersiz olur.</li>
                    <li><strong>İptal:</strong> jetonu kullanılamaz kılar ama kaydı silmez; geçmiş okunabilir kalır.</li>
                </ul>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
