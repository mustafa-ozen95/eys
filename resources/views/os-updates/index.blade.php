<x-layout.app title="Sistem güncellemeleri">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Sistem güncellemeleri"
                            subtitle="SSH bilgisi olan Linux kayıtları (işletim sistemi belirtilmemiş SSH kayıtları dahil). Denetim yalnızca okur; kurulum gerekçe ister, kilitli makineye hiçbir yoldan kurulum yapılmaz.">
                <x-slot:actions>
                    {{-- Açıklama düğmenin altında: yan yana dururken satır hizası kayıyordu. --}}
                    <div class="stack stack--tight">
                        @if ($checkable > 0)
                            {{-- Okuyan bir işlem: gerekçe istenmez. --}}
                            <form method="POST" action="{{ route('os-updates.check') }}" data-inline
                                  data-confirm="Yetkiniz olan {{ $checkable }} makineye bağlanılıp bekleyen güncellemeler okunacak. Hiçbir şey kurulmaz."
                                  data-confirm-title="Şimdi denetle"
                                  data-confirm-reasonless>
                                @csrf
                                <button type="submit" class="btn btn--sm">Hepsini şimdi denetle</button>
                            </form>
                        @endif

                        <span class="text-sm text-muted">
                            {{ $checkText }}
                        </span>
                    </div>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.flash />

            {{-- Kurulum ve denetim ayrı kartlar; ikisi aynı anda sürebilir. --}}
            @foreach ($rounds as $round)
                @include('credentials.partials.bulk-progress', ['tur' => $round])
            @endforeach

            <div class="figures">
                <div class="figure">
                    <div class="figure__value">{{ $figures['makine'] }}</div>
                    <div class="figure__label">Makine</div>
                </div>
                <div class="figure">
                    <div @class(['figure__value', 'figure__value--red' => $figures['kritik'] > 0, 'figure__value--green' => $figures['kritik'] === 0])>{{ $figures['kritik'] }}</div>
                    <div class="figure__label">Kritik bekleyen</div>
                </div>
                <div class="figure">
                    <div @class(['figure__value', 'figure__value--yellow' => $figures['bekleyen'] > 0])>{{ $figures['bekleyen'] }}</div>
                    <div class="figure__label">Güncelleme bekleyen</div>
                </div>
                <div class="figure">
                    <div class="figure__value figure__value--gray">{{ $figures['kilitli'] }}</div>
                    <div class="figure__label">Kilitli</div>
                </div>
                <div class="figure">
                    <div @class(['figure__value', 'figure__value--blue' => $figures['yenidenBaslat'] > 0])>{{ $figures['yenidenBaslat'] }}</div>
                    <div class="figure__label">Yeniden başlatma bekleyen</div>
                </div>
            </div>

            @can('osUpdateAny', App\Models\Credential::class)
                {{--
                    Toplu kurulum: tablonun hemen üstünde, sola yaslı tek satır;
                    "Tümü" kutusu satırların seçim kutularıyla aynı sütunda.
                    Kilitli ve yetkisiz satırların kutusu kapalıdır; karar yine
                    sunucuda (OsUpdateLauncher).
                --}}
                <form method="POST" action="{{ route('os-updates.apply') }}" id="os-update-form"
                      class="bulk" data-bulk-form data-inline
                      data-confirm="Seçili makinelere sistem güncellemesi kurulacak."
                      data-bulk-confirm-one="{name} makinesine sistem güncellemesi KURULACAK. Yeniden başlatma yapılmaz."
                      data-bulk-confirm-many="Seçili {n} makineye sistem güncellemesi KURULACAK. Yeniden başlatma yapılmaz."
                      data-confirm-title="Sistem güncellemesi kur"
                      data-confirm-variant="danger">
                    @csrf

                    <label @class(['bulk__all', 'bulk__all--empty' => $selectable === 0])>
                        <input type="checkbox" data-bulk-all @disabled($selectable === 0)
                               aria-label="Kurulabilir makinelerin hepsini seç">
                        <span>Tümü <span class="bulk__ready">{{ $selectable }}/{{ $rows->count() }} seçilebilir</span></span>
                    </label>

                    <x-form.select name="mod" :options="$modes" class="select--cell"
                                   selected="kritik" aria-label="Kurulum türü" />

                    <button type="submit" class="btn btn--sm" data-bulk-submit disabled
                    >Seçilenlere kur (<span data-bulk-count>0</span>)</button>
                </form>
            @endcan

            <x-ui.panel flush>
                @if ($rows->isEmpty())
                    <x-ui.empty title="Makine yok"
                                text="SSH bilgisi (adres, kullanıcı adı, parola) olan etkin bir Linux kaydı yok. Kullanıcı adı ya da parolası boş kayıtlar listelenmez." />
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Makine</th>
                                <th>Bekleyen</th>
                                <th>Son denetim</th>
                                <th>Son kurulum</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td data-label="Seç">
                                        @if ($row['selectable'])
                                            <input type="checkbox" form="os-update-form" name="kayitlar[]"
                                                   value="{{ $row['uuid'] }}" data-bulk-item
                                                   aria-label="{{ $row['name'] }}">
                                        @else
                                            {{-- Kapalı ve nedenini söylüyor: neden seçilemediği satırda okunsun. --}}
                                            <input type="checkbox" disabled title="{{ $row['blocked'] }}"
                                                   aria-label="{{ $row['name'] }} seçilemez: {{ $row['blocked'] }}">
                                        @endif
                                    </td>

                                    <td data-label="Makine">
                                        {{-- Tek blok: telefonda hücre yatay diziliyor, parçalar dağılıyordu. --}}
                                        <div>
                                            <span class="satir-baslik">{{ $row['name'] }}</span>
                                            <span class="text-sm text-muted">{{ $row['group'] }}</span>
                                            {{-- Denetimin makineden okuduğu ad (/etc/os-release). --}}
                                            @if ($row['os'])
                                                <div class="text-sm text-muted">{{ $row['os'] }}</div>
                                            @endif
                                            @if ($row['locked'])
                                                <div>
                                                    <x-ui.badge variant="gray">Kilitli</x-ui.badge>
                                                    <span class="text-sm text-muted">
                                                        {{ $row['lockReason'] }}
                                                        @if (! $row['lockHere']) · «{{ $row['lockFrom'] }}» kaydından @endif
                                                        @if ($row['lockBy']) · {{ $row['lockBy'] }}, {{ $row['lockAt'] }} @endif
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    </td>

                                    <td data-label="Bekleyen">
                                        {{-- Rozetler bir satırda, paket listesi altında ayrı (yan yana yapışıyorlardı);
                                             tek yığın, çünkü telefonda hücre yatay diziliyor. --}}
                                        <div class="stack stack--tight">
                                            <div class="row row--wrap row--tight">
                                                @if (! $row['checked'])
                                                    <x-ui.badge variant="gray">Denetlenmedi</x-ui.badge>
                                                @elseif ($row['pending'] === 0)
                                                    <x-ui.badge variant="green">Güncel</x-ui.badge>
                                                @else
                                                    @if ($row['critical'] > 0)
                                                        <x-ui.badge variant="red">{{ $row['critical'] }} kritik</x-ui.badge>
                                                    @endif
                                                    @if ($row['normal'] > 0)
                                                        <x-ui.badge variant="yellow">{{ $row['normal'] }} normal</x-ui.badge>
                                                    @endif
                                                @endif

                                                @if ($row['reboot'])
                                                    <x-ui.badge variant="blue">Yeniden başlatma gerekli</x-ui.badge>
                                                @endif
                                            </div>

                                            @if ($row['packages'] !== [])
                                                <details class="package-list">
                                                    <summary>Paket listesi</summary>
                                                    <ul class="package-list__items">
                                                        @foreach ($row['packages'] as $paket)
                                                            <li class="package-list__item">
                                                                <span class="package-list__name">{{ $paket['name'] }}</span>
                                                                <span class="package-list__version">{{ $paket['version'] }}</span>
                                                                @if ($paket['critical']) <x-ui.badge variant="red">kritik</x-ui.badge> @endif
                                                            </li>
                                                        @endforeach
                                                        @if ($row['packageMore'] > 0)
                                                            <li class="package-list__item text-muted">… {{ $row['packageMore'] }} paket daha</li>
                                                        @endif
                                                    </ul>
                                                </details>
                                            @endif
                                        </div>
                                    </td>

                                    <td data-label="Son denetim" class="mono text-muted">
                                        @if ($row['checkedAt'])
                                            <span title="{{ $row['checkedAt'] }}">{{ $row['checkedAgo'] }}</span>
                                            @if ($row['manager']) · {{ $row['manager'] }} @endif
                                        @else
                                            —
                                        @endif
                                        @if ($row['checkError'])
                                            <div><x-ui.badge variant="orange">{{ $row['checkError'] }}</x-ui.badge></div>
                                        @endif
                                    </td>

                                    <td data-label="Son kurulum">
                                        @if ($row['apply'])
                                            <x-ui.badge :variant="$row['apply']['variant']">{{ $row['apply']['label'] }}</x-ui.badge>
                                            <span class="mono text-sm text-muted">{{ $row['apply']['at'] }}</span>
                                            @if ($row['apply']['error'])
                                                <div class="text-sm text-muted">{{ $row['apply']['error'] }}</div>
                                            @endif
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>

                                    <td data-label="İşlem" class="text-right">
                                        @if ($row['canApply'])
                                            <button type="submit" form="denetle-{{ $row['uuid'] }}" class="btn btn--sm btn--ghost">Denetle</button>
                                        @endif
                                        @if ($row['canLock'] && (! $row['locked'] || $row['lockHere']))
                                            <button type="submit" form="kilit-{{ $row['uuid'] }}" class="btn btn--sm">
                                                {{ $row['locked'] ? 'Kilidi kaldır' : 'Kilitle' }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.panel>

            {{--
                Satır düğmelerinin formları tablonun DIŞINDA: form içinde form
                olamaz. Düğmeler `form=` özniteliğiyle buraya bağlanır.
            --}}
            @foreach ($rows as $row)
                @if ($row['canApply'])
                    <form method="POST" action="{{ route('os-updates.check') }}" id="denetle-{{ $row['uuid'] }}" data-inline hidden>
                        @csrf
                        <input type="hidden" name="kayitlar[]" value="{{ $row['uuid'] }}">
                    </form>
                @endif
                @if ($row['canLock'] && (! $row['locked'] || $row['lockHere']))
                    <form method="POST" action="{{ route('os-updates.lock', $row['uuid']) }}" id="kilit-{{ $row['uuid'] }}"
                          data-inline hidden
                          data-confirm-title="{{ $row['locked'] ? 'Kilidi kaldır' : 'Güncelleme kilidi' }}"
                          @if ($row['locked'])
                              data-confirm="{{ $row['name'] }} kilidi kaldırılacak; bu makineye yeniden güncelleme kurulabilir."
                              data-confirm-variant="danger"
                          @else
                              data-confirm="{{ $row['name'] }} kilitlenecek: toplu seçim dahil hiçbir yoldan güncelleme kurulmaz. Denetim sürer."
                          @endif>
                        @csrf
                        <input type="hidden" name="kilit" value="{{ $row['locked'] ? 0 : 1 }}">
                    </form>
                @endif
            @endforeach
        </div>
    </div>
</x-layout.app>
