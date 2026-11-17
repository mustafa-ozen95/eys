<x-layout.app title="Sistem güncellemeleri">
    {{--
        Erişim bilgileri ekranının düzeni: solda gruplar (en üstte Hepsi),
        ortada makineler, sağda seçili makinenin kartı ve güncelleme geçmişi.
        Seçim adreste (?grup=, ?kayit=); dar ekranda şerit doğru bölmeye iner
        (columns.js).
    --}}
    <div class="columns">
        <div class="column">
            <div class="column__head">
                <span class="eyebrow">Gruplar</span>
                <span class="column__count">{{ count($groups) }}</span>
            </div>

            {{--
                Grup sırası Gruplar ekranıyla ORTAK (aynı uç); sürükleme yalnızca
                grupları sıralayabilene. Hepsi sabit en üstte, sıraya girmez.
            --}}
            <div class="column__body" @if ($canOrder) data-order="{{ route('groups.reorder') }}" data-order-field="sira" @endif>
                @if ($groups !== [])
                    <a href="{{ route('os-updates.index', ['grup' => $allGroups]) }}"
                       class="row-item"
                       @if ($selectedGroup === null) aria-selected="true" @endif>
                        <span class="row-item__label">Hepsi</span>
                        <span class="row-item__meta">{{ $allCount }}</span>
                    </a>
                @endif

                @forelse ($groups as $group)
                    <a href="{{ route('os-updates.index', ['grup' => $group['param']]) }}"
                       class="row-item"
                       @if ($group['orderId']) draggable="true" data-order-id="{{ $group['orderId'] }}" @endif
                       @if ($selectedGroup && $selectedGroup['param'] === $group['param']) aria-selected="true" @endif>
                        @if ($group['orderId'])
                            <span class="handle" aria-hidden="true" title="Sürükleyerek sırala">⣿</span>
                        @endif
                        <span class="row-item__label">{{ $group['name'] }}</span>
                        @if ($group['critical'] > 0)
                            <x-ui.badge variant="red" title="Kritik güncelleme bekleyen makine">{{ $group['critical'] }}</x-ui.badge>
                        @endif
                        <span class="row-item__meta">{{ $group['count'] }}</span>
                    </a>
                @empty
                    <x-ui.empty title="Makine yok"
                                text="SSH bilgisi (adres, kullanıcı adı, parola) olan etkin bir Linux kaydı yok. Kullanıcı adı ya da parolası boş kayıtlar listelenmez." />
                @endforelse
            </div>
        </div>

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">{{ $listTitle }}</span>
                <span class="column__count">{{ $rows->count() }}</span>
                <span class="text-sm text-muted truncate">{{ $listSummary }}</span>

                <x-ui.hint baslik="Bu ekran ne yapıyor?">
                    SSH ya da RDP ile kayıtlı Linux makineler (işletim sistemi belirtilmemiş
                    SSH kayıtları dahil); bağlantı SSH ile yapılır. Denetim yalnızca okur;
                    kurulum gerekçe ister, kilitli makineye hiçbir yoldan kurulum yapılmaz.
                    {{ $checkText }}.
                </x-ui.hint>

                <span class="spacer"></span>

                {{-- Kurma/denetleme yetkisi yoksa (ör. yalnızca kilit yetkisi) toplu araçlar hiç çizilmez. --}}
                @if ($canAct)
                <input type="checkbox" id="bulk-tools-toggle" class="bulk-tools__box" hidden>
                <label for="bulk-tools-toggle" class="btn btn--sm bulk-tools__button">Toplu işlemler</label>

                <div class="bulk-tools">
                        {{-- Telefonda iki denetim düğmesini "Toplu işlemler"in altına, kendi satırına indirir. --}}
                        <span class="bulk-tools__break" aria-hidden="true"></span>

                        @if ($checkable > 0)
                            {{-- Okuyan bir işlem: gerekçe istenmez. --}}
                            <form method="POST" action="{{ route('os-updates.check') }}" class="bulk" data-inline
                                  data-confirm="Yetkiniz olan {{ $checkable }} makineye bağlanılıp bekleyen güncellemeler okunacak. Hiçbir şey kurulmaz."
                                  data-confirm-title="Şimdi denetle"
                                  data-confirm-reasonless>
                                @csrf
                                <button type="submit" class="btn btn--sm">Hepsini şimdi denetle</button>
                            </form>
                        @endif

                        {{--
                            Aynı seçimle denetim: kutular kurulum formuna bağlı,
                            seçim onayda buraya kopyalanır (bulk.js, data-bulk-copy).
                            secilen=1: seçim boş gelirse sunucu reddeder, hepsini denetlemez.
                        --}}
                        <form method="POST" action="{{ route('os-updates.check') }}" class="bulk"
                              data-inline data-bulk-copy
                              data-confirm="Seçili makinelere bağlanılıp bekleyen güncellemeler okunacak. Hiçbir şey kurulmaz."
                              data-confirm-title="Seçilenleri denetle"
                              data-confirm-reasonless>
                            @csrf
                            <input type="hidden" name="secilen" value="1">
                            <button type="submit" class="btn btn--sm" data-bulk-submit disabled
                            >Seçilenleri denetle (<span data-bulk-count>0</span>)</button>
                        </form>

                        {{--
                            Kurulum: seçim bu listedeki satırlardan. Kilitli ve
                            yetkisiz satırların kutusu kapalıdır; karar yine
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
                </div>
                @endif
            </div>

            {{-- Kurulum ve denetim ayrı kartlar; ikisi aynı anda sürebilir. Kayan listenin dışında. --}}
            @foreach ($rounds as $round)
                @include('credentials.partials.bulk-progress', ['tur' => $round])
            @endforeach

            <div class="column__body">
                <div class="grid-row__head os-update-row os-update-row__head">
                    <span class="os-update-row__select"></span>
                    <span class="os-update-row__name">Makine</span>
                    <span class="os-update-row__pending">Bekleyen</span>
                    <span class="os-update-row__checked">Son denetim</span>
                    <span class="os-update-row__apply">Son kurulum</span>
                </div>

                @forelse ($sections as $bolum)
                    {{-- Hepsi görünümünde grup başlığı; grup seçiliyken başlık yok. --}}
                    @if ($bolum['name'] !== null)
                        <div class="credential-section">
                            {{-- Alt grubun seçilebilen makinelerinin hepsi (bulk.js, data-bulk-group-all). --}}
                            @if ($canAct)
                                <span class="os-update-row__select">
                                    <input type="checkbox" data-bulk-group-all="{{ $loop->index }}"
                                           @disabled($bolum['selectable'] === 0)
                                           title="{{ $bolum['selectable'] }}/{{ $bolum['count'] }} seçilebilir"
                                           aria-label="{{ $bolum['name'] }}: seçilebilen makinelerin hepsini seç">
                                </span>
                            @endif
                            <span class="credential-section__name">{{ $bolum['name'] }}</span>
                        </div>
                    @endif

                    @foreach ($bolum['rows'] as $row)
                        <div class="grid-row os-update-row"
                             @if ($selected && $selected['uuid'] === $row['uuid']) aria-selected="true" @endif>
                            <span class="os-update-row__select">
                                @if (! $canAct)
                                    {{-- Yalnızca görme yetkisi: seçilecek bir iş yok, kutu da yok. --}}
                                @elseif ($row['selectable'])
                                    <input type="checkbox" form="os-update-form" name="kayitlar[]"
                                           value="{{ $row['uuid'] }}" data-bulk-item
                                           data-bulk-group="{{ $loop->parent->index }}"
                                           aria-label="{{ $row['name'] }}">
                                @else
                                    {{-- Kapalı ve nedenini söylüyor: neden seçilemediği satırda okunsun. --}}
                                    <input type="checkbox" disabled title="{{ $row['blocked'] }}"
                                           aria-label="{{ $row['name'] }} seçilemez: {{ $row['blocked'] }}">
                                @endif
                            </span>

                            <a href="{{ route('os-updates.index', ['grup' => $groupParam, 'kayit' => $row['uuid']]) }}"
                               class="os-update-row__name os-update-row__link">
                                <span class="truncate">{{ $row['name'] }}</span>
                                {{-- Denetimin makineden okuduğu ad (/etc/os-release). --}}
                                @if ($row['os'])
                                    <span class="text-sm text-muted truncate">{{ $row['os'] }}</span>
                                @endif
                            </a>

                            <span class="os-update-row__pending row row--wrap row--tight">
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
                                @if ($row['bootLow'])
                                    <x-ui.badge variant="orange" :title="$row['bootLow']">/boot dolu</x-ui.badge>
                                @endif
                                @if ($row['locked'])
                                    <x-ui.badge variant="gray">Kilitli</x-ui.badge>
                                @endif
                                @if ($row['excluded'])
                                    <x-ui.badge variant="gray" :title="$row['excludeReason']">Hariç</x-ui.badge>
                                @endif
                            </span>

                            <span class="os-update-row__checked text-sm text-muted truncate">
                                @if ($row['checkError'])
                                    <x-ui.badge variant="orange" :title="$row['checkError']">Denetlenemedi</x-ui.badge>
                                @else
                                    <span title="{{ $row['checkedAt'] }}">{{ $row['checkedAgo'] ?? '—' }}</span>
                                @endif
                            </span>

                            <span class="os-update-row__apply">
                                @if ($row['apply'])
                                    <x-ui.badge :variant="$row['apply']['variant']" :title="$row['apply']['at']">{{ $row['apply']['label'] }}</x-ui.badge>
                                @else
                                    <span class="text-sm text-muted">—</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                @empty
                    <x-ui.empty title="Makine yok"
                                text="SSH bilgisi (adres, kullanıcı adı, parola) olan etkin bir Linux kaydı yok. Kullanıcı adı ya da parolası boş kayıtlar listelenmez." />
                @endforelse
            </div>
        </div>

        @if ($selected)
            <aside class="detail">
                <div class="detail__head">
                    <div class="detail__title detail__title--plain">{{ $selected['name'] }}</div>
                    <p class="detail__subtitle">{{ $selected['groupName'] }}</p>
                </div>

                <div class="detail__body">
                    <div class="detail__section">
                        <span class="eyebrow">Durum</span>
                        <div class="row row--wrap row--tight detail__block">
                            @if (! $selected['checked'])
                                <x-ui.badge variant="gray">Denetlenmedi</x-ui.badge>
                            @elseif ($selected['pending'] === 0)
                                <x-ui.badge variant="green">Güncel</x-ui.badge>
                            @else
                                @if ($selected['critical'] > 0)
                                    <x-ui.badge variant="red">{{ $selected['critical'] }} kritik</x-ui.badge>
                                @endif
                                @if ($selected['normal'] > 0)
                                    <x-ui.badge variant="yellow">{{ $selected['normal'] }} normal</x-ui.badge>
                                @endif
                            @endif
                            @if ($selected['reboot'])
                                <x-ui.badge variant="blue">Yeniden başlatma gerekli</x-ui.badge>
                            @endif
                            @if ($selected['apply'])
                                <x-ui.badge :variant="$selected['apply']['variant']">Son kurulum: {{ $selected['apply']['label'] }}</x-ui.badge>
                            @endif
                        </div>

                        @if ($selected['checkError'])
                            <div class="alert alert--danger detail__block text-sm">{{ $selected['checkError'] }}</div>
                        @endif
                        @if ($selected['bootLow'])
                            <div class="alert alert--warning detail__block text-sm">{{ $selected['bootLow'] }}</div>
                        @endif
                        @if ($selected['apply'] && $selected['apply']['error'])
                            <div class="alert alert--danger detail__block text-sm">{{ $selected['apply']['error'] }}</div>
                        @endif

                        <x-ui.facts class="detail__block" :rows="$selected['facts']" />
                    </div>

                    @if ($selected['locked'])
                        <div class="detail__section">
                            <span class="eyebrow">Kilit</span>
                            <p class="text-sm detail__block">
                                {{ $selected['lockReason'] }}
                                @if (! $selected['lockHere']) · «{{ $selected['lockFrom'] }}» kaydından @endif
                                @if ($selected['lockBy']) · {{ $selected['lockBy'] }}, {{ $selected['lockAt'] }} @endif
                            </p>
                        </div>
                    @endif

                    @if ($selected['excluded'])
                        <div class="detail__section">
                            <span class="eyebrow">Güncellemeden hariç</span>
                            <p class="text-sm detail__block">
                                {{ $selected['excludeReason'] }} · Bu kayıtla makineye bağlanılmaz; makine kardeş kayıttan güncellenir.
                            </p>
                        </div>
                    @endif

                    @if ($selected['packages'] !== [])
                        <div class="detail__section">
                            <span class="eyebrow">Bekleyen paketler</span>
                            <ul class="package-list__items detail__block">
                                @foreach ($selected['packages'] as $paket)
                                    <li class="package-list__item">
                                        <span class="package-list__name">{{ $paket['name'] }}</span>
                                        <span class="package-list__version">{{ $paket['version'] }}</span>
                                        @if ($paket['critical']) <x-ui.badge variant="red">kritik</x-ui.badge> @endif
                                    </li>
                                @endforeach
                                @if ($selected['packageMore'] > 0)
                                    <li class="package-list__item text-muted">… {{ $selected['packageMore'] }} paket daha</li>
                                @endif
                            </ul>
                        </div>
                    @endif

                    {{--
                        Geçmiş etkinlik kaydından: kurulumlar ve kilit değişiklikleri.
                        Denetimler yazılmaz; sonuncusu yukarıda "Son denetim".
                    --}}
                    <div class="detail__section">
                        <span class="eyebrow">Güncelleme geçmişi</span>

                        @if (! $selected['historyVisible'])
                            <p class="text-muted text-sm detail__block">Bu makinenin geçmişini görme yetkiniz yok.</p>
                        @elseif ($selected['history'] === [])
                            <p class="text-muted text-sm detail__block">Henüz kurulum yapılmadı ya da kilit konmadı. Denetimler geçmişe yazılmaz; sonuncusu yukarıda.</p>
                        @else
                            <dl class="facts detail__block">
                                @foreach ($selected['history'] as $olay)
                                    <div class="facts__row">
                                        <dt class="facts__key" title="{{ $olay['ago'] }}">{{ $olay['at'] }}</dt>
                                        <dd class="facts__value">
                                            <div class="stack stack--tight">
                                                <div><x-ui.badge :variant="$olay['variant']">{{ $olay['label'] }}</x-ui.badge></div>
                                                <span>{{ $olay['summary'] }}</span>
                                                <span class="text-sm text-muted">{{ $olay['actor'] }}@if ($olay['reason']) · {{ $olay['reason'] }}@endif</span>
                                            </div>
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        @if ($selected['logsLink'])
                            <a href="{{ $selected['logsLink'] }}" class="btn btn--sm detail__block">Tümü</a>
                        @endif
                    </div>
                </div>

                @if ($selected['canApply'] || $selected['canReboot'] || $selected['canLock'])
                    <div class="detail__foot">
                        @if ($selected['canApply'] && ! $selected['excluded'])
                            <form method="POST" action="{{ route('os-updates.check') }}" data-inline>
                                @csrf
                                <input type="hidden" name="kayitlar[]" value="{{ $selected['uuid'] }}">
                                <button type="submit" class="btn btn--sm">Denetle</button>
                            </form>
                        @endif

                        @if ($selected['canLock'] && (! $selected['locked'] || $selected['lockHere']))
                            <form method="POST" action="{{ route('os-updates.lock', $selected['uuid']) }}"
                                  data-inline
                                  data-confirm-title="{{ $selected['locked'] ? 'Kilidi kaldır' : 'Güncelleme kilidi' }}"
                                  @if ($selected['locked'])
                                      data-confirm="{{ $selected['name'] }} kilidi kaldırılacak; bu makineye yeniden güncelleme kurulabilir."
                                      data-confirm-variant="danger"
                                  @else
                                      data-confirm="{{ $selected['name'] }} kilitlenecek: toplu seçim dahil hiçbir yoldan güncelleme kurulmaz. Denetim sürer."
                                  @endif>
                                @csrf
                                <input type="hidden" name="kilit" value="{{ $selected['locked'] ? 0 : 1 }}">
                                <button type="submit" class="btn btn--sm">{{ $selected['locked'] ? 'Kilidi kaldır' : 'Kilitle' }}</button>
                            </form>
                        @endif

                        {{-- Kayıt bazlı; kilit makine bazlı. Aynı makinede yetkisiz/yanlış kimlikli ikinci kayıt için. --}}
                        @if ($selected['canLock'])
                            <form method="POST" action="{{ route('os-updates.exclude', $selected['uuid']) }}"
                                  data-inline
                                  data-confirm-title="{{ $selected['excluded'] ? 'Güncellemeye dahil et' : 'Güncellemeden hariç tut' }}"
                                  @if ($selected['excluded'])
                                      data-confirm="{{ $selected['name'] }} yeniden güncellemeye dahil edilecek: bu kayıtla makineye bağlanılabilir."
                                      data-confirm-variant="danger"
                                  @else
                                      data-confirm="{{ $selected['name'] }} güncellemeden hariç tutulacak: bu kayıtla makineye bağlanılmaz (kurulum, yeniden başlatma, denetim). Makine kardeş kayıttan güncellenir; kilit konmaz."
                                  @endif>
                                @csrf
                                <input type="hidden" name="haric" value="{{ $selected['excluded'] ? 0 : 1 }}">
                                <button type="submit" class="btn btn--sm">{{ $selected['excluded'] ? 'Dahil et' : 'Hariç tut' }}</button>
                            </form>
                        @endif

                        @if ($selected['canReboot'])
                            <form method="POST" action="{{ route('os-updates.reboot', $selected['uuid']) }}"
                                  data-inline
                                  data-confirm-title="Yeniden başlat"
                                  data-confirm="{{ $selected['name'] }} bir dakika sonra yeniden başlatılacak; makinedeki hizmetler birkaç dakika kesilir."
                                  data-confirm-variant="danger">
                                @csrf
                                <button type="submit" class="btn btn--sm">Yeniden başlat</button>
                            </form>
                        @endif
                    </div>
                @endif
            </aside>
        @endif
    </div>
</x-layout.app>
