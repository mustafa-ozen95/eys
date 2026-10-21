{{--
    Kayıt listesinin BİR bölümü: alt grup başlığı ve satırları.
    credentials/index iki yerden çağırır: sürüklenebilir alt grup bölümleri
    ve en sondaki "Alt grupsuz" bölümü. Değişkenler çağıranın kapsamından gelir.
--}}
                    @if ($bolum['baslik'] !== null)
                        <div class="credential-bolum">
                            @if ($bolumSirasi)
                                <span class="tutamak" aria-hidden="true" title="Sürükleyerek sırala">⣿</span>
                            @endif
                            <span class="credential-bolum__ad">{{ $bolum['baslik'] }}</span>
                        </div>
                    @elseif (count($bolumler) > 1)
                        <div class="credential-bolum credential-bolum--diger">
                            <span class="credential-bolum__ad">Alt grupsuz</span>
                        </div>
                    @endif

                {{--
                    Her bölüm AYRI bir sürükleme listesi: satır yalnızca kendi
                    alt grubunun içinde yer değiştirir (bkz. sorting.js,
                    CredentialController · sirala). Sıra artık formda yazılmaz.
                --}}
                <div @if ($siralanabilir) data-sira="{{ route('credentials.sirala') }}" data-sira-alan="sira" @endif>
                @forelse ($bolum['rows'] as $row)
                    <div @class(['grid-row', 'credential-row', 'credential-row--passive' => ! $row['isActive'], 'credential-row--kimliksiz' => ! $kimlikSutunlari, 'credential-row--uplink' => $uplinkSutunu])
                         @if ($siralanabilir) draggable="true" data-sira-kimlik="{{ $row['uuid'] }}" @endif
                         @if ($selected && $selected['uuid'] === $row['uuid']) aria-selected="true" @endif>
                        <span class="credential-row__sec">
                            {{--
                                Kutu, formun DIŞINDA duruyor ama ona bağlı:
                                form="toplu-dondur". Listeyi forma sarmak,
                                satır bağlantılarını formun içine almak
                                olurdu.
                            --}}
                            @if ($row['rotationBlocked'] === null)
                                <input type="checkbox" form="toplu-dondur" name="kayitlar[]"
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
                           class="credential-row__ad credential-row__link">
                            {{-- Sürükleme tutamağı: satır sürüklenebilir olduğunu söylesin. --}}
                            @if ($siralanabilir)
                                <span class="tutamak" aria-hidden="true" title="Sürükleyerek sırala">⣿</span>
                            @endif
                            <span class="truncate">{{ $row['name'] }}</span>
                            @if ($row['group'])
                                <span class="text-sm text-muted truncate">{{ $row['group'] }}</span>
                            @endif
                        </a>
                        <span class="credential-row__tur">
                            <x-ui.badge :variant="$row['type']->badge()">{{ $row['type']->label() }}</x-ui.badge>
                        </span>
                        <span class="credential-row__platform text-sm text-muted truncate">{{ $row['platform'] }}</span>
                        <span class="mono text-muted credential-row__adres truncate">{{ $row['target'] }}</span>
                        @if ($uplinkSutunu)
                            <span class="mono text-muted credential-row__uplink truncate">{{ $row['uplink'] ?? '—' }}</span>
                        @endif
                        @if ($kimlikSutunlari)
                            <span class="mono text-muted credential-row__kullanici truncate">{{ $row['username'] ?? '—' }}</span>
                            <span class="text-sm text-muted credential-row__parola">{{ $row['password'] }}</span>
                        @endif
                    </div>
                @empty
                    <x-ui.empty :title="$query ? 'Eşleşen kayıt yok' : 'Kayıt yok'"
                                :text="$query ? 'Başka bir arama deneyin.' : 'Bu gruba henüz erişim bilgisi eklenmemiş.'" />
                @endforelse
                </div>
