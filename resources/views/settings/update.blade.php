<x-layout.app title="Güncelleme · Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar"
                            subtitle="Panelin sürümü ve GitHub'daki son başarılı derleme." />

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            <x-ui.panel title="Sürüm">
                <dl class="facts">
                    <div class="facts__row">
                        <dt class="facts__key">Çalışan sürüm</dt>
                        <dd class="facts__value mono" title="{{ $calisan }}">{{ $calisanKisa }}</dd>
                    </div>
                    <div class="facts__row">
                        <dt class="facts__key">Depo</dt>
                        <dd class="facts__value mono">{{ $depo }}</dd>
                    </div>
                    @if ($son)
                        <div class="facts__row">
                            <dt class="facts__key">Durum</dt>
                            <dd class="facts__value">
                                <x-ui.badge :variant="$durum['renk']">{{ $durum['etiket'] }}</x-ui.badge>
                                @if (! empty($son['aciklama']))
                                    <span class="text-sm text-muted">· {{ $son['aciklama'] }}</span>
                                @endif
                            </dd>
                        </div>
                        @if ($son['eski'])
                            <div class="facts__row">
                                <dt class="facts__key"></dt>
                                <dd class="facts__value text-sm text-muted">Bu sonuç önceki sürümle alınmış; panel o zamandan beri güncellendi. Yeniden kontrol edin.</dd>
                            </div>
                        @endif
                        @if (! empty($son['son']))
                            <div class="facts__row">
                                <dt class="facts__key">Son başarılı derleme</dt>
                                <dd class="facts__value"><span class="mono" title="{{ $son['son'] }}">{{ $son['son_kisa'] }}</span> · {{ $son['son_tarih'] ?? '—' }}</dd>
                            </div>
                        @endif
                        <div class="facts__row">
                            <dt class="facts__key">Son kontrol</dt>
                            <dd class="facts__value">{{ $son['zaman'] ?? '—' }} · {{ $son['tetikleyen'] ?? '—' }}</dd>
                        </div>
                    @else
                        <div class="facts__row">
                            <dt class="facts__key">Son kontrol</dt>
                            <dd class="facts__value">henüz yapılmadı</dd>
                        </div>
                    @endif
                </dl>

                <form method="POST" action="{{ route('guncelleme.denetle') }}" class="form-actions">
                    @csrf
                    <button type="submit" class="btn btn--primary">Şimdi kontrol et</button>
                </form>
            </x-ui.panel>

            @if ($son && ! empty($son['commitler']))
                <x-ui.panel title="Yeni commit'ler"
                            subtitle="Çalışan sürümden sonra gelenler, en yenisi üstte.">
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Commit</th>
                                    <th>Başlık</th>
                                    <th>Tarih</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($son['commitler'] as $commit)
                                    <tr>
                                        <td data-label="Commit" class="mono">{{ $commit['kisa'] }}</td>
                                        <td data-label="Başlık">{{ $commit['baslik'] }}</td>
                                        <td data-label="Tarih" class="text-sm">{{ $commit['tarih'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($son['daha'] > 0)
                        <p class="text-sm text-muted">ve {{ $son['daha'] }} commit daha.</p>
                    @endif
                </x-ui.panel>
            @endif

            <x-ui.panel title="Nasıl çalışır">
                <ul class="text-sm stack">
                    <li>Kontrol her sabah kendiliğinden yapılır; düğme aynı kontrolü hemen yapar. Yeni sürüm varsa yöneticiler panelin üstünde uyarı görür.</li>
                    <li>Karşılaştırma, deponun main dalındaki son başarılı imaj derlemesiyle yapılır. Derlemesi bitmemiş ya da düşmüş bir commit kurulamaz; yeni sürüm sayılmaz.</li>
                    <li>Yön de ölçülür: çalışan sürüm son derlemeden yeniyse bu "güncelleme" değil, geri alma olurdu; ekran bunu ayrıca söyler.</li>
                    <li>Bu ekran yalnızca bakar. Güncellemenin kendisi sunucudan, yedek alınarak ve onayla yapılır.</li>
                </ul>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
