{{--
    Kayıt listesinin BİR bölümü: alt grup başlığı ve satırları.
    credentials/index iki yerden çağırır: sürüklenebilir alt grup bölümleri
    ve en sondaki "Alt grupsuz" bölümü. Değişkenler çağıranın kapsamından gelir.
--}}
                    @if ($bolum['baslik'] !== null)
                        <div class="credential-section">
                            @if ($bolumSirasi)
                                <span class="handle" aria-hidden="true" title="Sürükleyerek sırala">⣿</span>
                            @endif
                            <span class="credential-section__name">{{ $bolum['baslik'] }}</span>
                        </div>
                    @elseif (count($bolumler) > 1)
                        <div class="credential-section credential-section--other">
                            <span class="credential-section__name">Alt grupsuz</span>
                        </div>
                    @endif

                {{--
                    Her bölüm AYRI bir sürükleme listesi: satır yalnızca kendi
                    alt grubunun içinde yer değiştirir (bkz. sorting.js,
                    CredentialController · sirala). Sıra artık formda yazılmaz.
                --}}
                <div @if ($siralanabilir) data-order="{{ route('credentials.reorder') }}" data-order-field="sira" @endif>
                @forelse ($bolum['rows'] as $row)
                    <div @class(['grid-row', 'credential-row', 'credential-row--passive' => ! $row['isActive'], 'credential-row--no-identity' => ! $kimlikSutunlari, 'credential-row--uplink' => $uplinkSutunu])
                         @if ($siralanabilir) draggable="true" data-order-id="{{ $row['uuid'] }}" @endif
                         @if ($selected && $selected['uuid'] === $row['uuid']) aria-selected="true" @endif>
                        <span class="credential-row__select">
                            {{--
                                Kutu, formun DIŞINDA duruyor ama ona bağlı:
                                form="bulk-rotate-form". Listeyi forma sarmak,
                                satır bağlantılarını formun içine almak
                                olurdu.
                            --}}
                            @if ($row['rotationBlocked'] === null)
                                <input type="checkbox" form="bulk-rotate-form" name="kayitlar[]"
                                       value="{{ $row['uuid'] }}" data-bulk-item
                                       aria-label="{{ $row['name'] }} kaydını seç">
                            @else
                                {{--
                                    Kutu gizlenmiyor, KAPALI duruyor ve nedenini
                                    söylüyor: gizlenseydi "neden bu satır
                                    seçilemiyor" sorusunun cevabı için kaydı tek
                                    tek açmak gerekirdi.
                                --}}
                                <input type="checkbox" disabled
                                       aria-label="{{ $row['name'] }} güncellenemez: {{ $row['rotationBlocked'] }}"
                                       title="{{ $row['rotationBlocked'] }}">
                            @endif
                        </span>

                        {{--
                            Grup, SATIRIN kendi grubudur: "Hepsi" görünümünde
                            seçili grup yok ve grupsuz bağlantı ilk gruba
                            düşerdi. Arama açıkken sunucu grubu zaten
                            yok sayar, sonuç listesi bozulmaz.
                        --}}
                        <a href="{{ route('credentials.index', ['grup' => $row['groupUuid'], 'q' => $query ?: null, 'platform' => $platform ?: null, 'kayit' => $row['uuid']]) }}"
                           class="credential-row__name credential-row__link">
                            {{-- Sürükleme tutamağı: satır sürüklenebilir olduğunu söylesin. --}}
                            @if ($siralanabilir)
                                <span class="handle" aria-hidden="true" title="Sürükleyerek sırala">⣿</span>
                            @endif
                            <span class="truncate">{{ $row['name'] }}</span>
                            @if ($row['group'])
                                <span class="text-sm text-muted truncate">{{ $row['group'] }}</span>
                            @endif
                        </a>
                        <span class="credential-row__type">
                            <x-ui.badge :variant="$row['type']->badge()">{{ $row['type']->label() }}</x-ui.badge>
                        </span>
                        <span class="credential-row__platform text-sm text-muted truncate">{{ $row['platform'] }}</span>
                        <span class="mono text-muted credential-row__address truncate">{{ $row['target'] }}</span>
                        @if ($uplinkSutunu)
                            <span class="mono text-muted credential-row__uplink truncate">{{ $row['uplink'] ?? '—' }}</span>
                        @endif
                        @if ($kimlikSutunlari)
                            <span class="mono text-muted credential-row__user truncate">{{ $row['username'] ?? '—' }}</span>
                            <span class="text-sm text-muted credential-row__password">{{ $row['password'] }}</span>
                        @endif
                    </div>
                @empty
                    <x-ui.empty :title="$query ? 'Eşleşen kayıt yok' : 'Kayıt yok'"
                                :text="$query ? 'Başka bir arama deneyin.' : 'Bu gruba henüz erişim bilgisi eklenmemiş.'" />
                @endforelse
                </div>
