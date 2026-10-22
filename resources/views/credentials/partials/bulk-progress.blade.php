{{--
    TOPLU TURUN TEK GÖSTERGESİ.

    Kayıtları tek tek açıp bakmak yerine partinin tamamı burada izlenir.
    Her parça bir makinedir: hangisinin bittiği,
    hangisinin sürdüğü ve hangisinin sorunlu olduğu tek bakışta okunur.

    İlk çizim sunucudan gelir; tur sürerken yoklama devralır ve AYNI sınıf
    adlarıyla yeniden çizer (bkz. bulk-progress.js). Sayıların hepsi tur
    kayıtlarından okunur; gösterge tahmin etmez.
--}}
<section class="bulk-round bulk-round--{{ $tur['result'] }}"
         role="status" aria-live="polite" aria-label="Toplu parola güncelleme"
         data-bulk-round-root
         data-bulk-round-percent="{{ $tur['status']['percent'] }}"
         @if ($tur['status']['running'])
             data-bulk-round-progress
             data-bulk-round-progress-url="{{ route('credentials.rotate.bulk.progress', $tur['round']) }}"
         @endif>
    <div class="bulk-round__head">
        <div class="bulk-round__ring" aria-hidden="true">
            <span class="bulk-round__percent" data-bulk-round-percent-text>
                @if ($tur['status']['running'])
                    %{{ $tur['status']['percent'] }}
                @elseif ($tur['problemCount'] === 0)
                    <svg viewBox="0 0 24 24" class="bulk-round__mark"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                @else
                    <svg viewBox="0 0 24 24" class="bulk-round__mark"><path d="M12 7v6M12 16.5v.5"/></svg>
                @endif
            </span>
        </div>

        <div class="bulk-round__title">
            <span class="bulk-round__top">Toplu parola güncelleme</span>
            <span class="bulk-round__main">
                <strong data-bulk-round-done>{{ $tur['status']['done'] }}</strong><span class="bulk-round__total">/{{ $tur['status']['total'] }} makine</span>
                <span class="bulk-round__status" data-bulk-round-text>{{ $tur['status']['running'] ? '' : $tur['status']['text'] }}</span>
            </span>
        </div>

        <span class="bulk-round__now" data-bulk-round-now @if (! $tur['status']['current']) hidden @endif>
            <span class="bulk-round__pulse" aria-hidden="true"></span>
            <span>Şu an</span>
            <strong data-bulk-round-current>{{ $tur['status']['current'] }}</strong>
        </span>

        @unless ($tur['status']['running'])
            {{-- Kapatmak turlara dokunmaz; yalnızca kartı ekrandan kaldırır. --}}
            <form method="POST" action="{{ route('credentials.rotate.bulk.dismiss', $tur['round']) }}" class="bulk-round__close" data-inline>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn--sm btn--ghost" aria-label="Göstergeyi kapat">Kapat</button>
            </form>
        @endunless
    </div>

    {{-- Her makine bir parça; üstüne gelince adı ve durumu görünür. --}}
    <ol class="bulk-round__trace" data-bulk-round-trace>
        @foreach ($tur['status']['records'] as $k)
            <li class="bulk-round__segment bulk-round__segment--{{ $k['status'] }}" title="{{ $k['name'] }} · {{ $tur['labels'][$k['status']] }}"></li>
        @endforeach
    </ol>

    <div class="bulk-round__bottom">
        <span class="bulk-round__legend" data-bulk-round-legend>
            @foreach ($tur['legend'] as $satir)
                <span class="bulk-round__legend-item"><i class="bulk-round__color bulk-round__segment--{{ $satir['key'] }}"></i>{{ $satir['count'] }} {{ mb_strtolower($satir['label']) }}</span>
            @endforeach
        </span>

        @if (! $tur['status']['running'] && $tur['status']['problems'] !== [])
            {{-- Sorunlu makineler ADIYLA: hangisine bakılacağını aramak zorunda kalınmasın. --}}
            <span class="bulk-round__problems">
                @foreach ($tur['status']['problems'] as $s)
                    <span class="bulk-round__chip" title="{{ $s['status'] }}">{{ $s['name'] }}</span>
                @endforeach
            </span>
        @endif
    </div>
</section>
