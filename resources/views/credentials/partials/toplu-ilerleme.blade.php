{{--
    TOPLU TURUN TEK GÖSTERGESİ.

    Kayıtları tek tek açıp bakmak yerine partinin tamamı burada izlenir.
    Her parça bir makinedir: hangisinin bittiği,
    hangisinin sürdüğü ve hangisinin sorunlu olduğu tek bakışta okunur.

    İlk çizim sunucudan gelir; tur sürerken yoklama devralır ve AYNI sınıf
    adlarıyla yeniden çizer (bkz. toplu-ilerleme.js). Sayıların hepsi tur
    kayıtlarından okunur; gösterge tahmin etmez.
--}}
@php
    $d = $tur['durum'];
    $sorunSayisi = $d['basarisiz'] + $d['dogrulanamadi'] + $d['baslamadi'];
    $sonuc = $d['calisiyor'] ? 'suruyor' : ($sorunSayisi === 0 ? 'basarili' : 'sorunlu');
    $etiketler = App\Services\Rotation\TopluTur::ETIKETLER;
@endphp
<section class="toplu toplu--{{ $sonuc }}"
         role="status" aria-live="polite" aria-label="Toplu parola güncelleme"
         data-toplu-kok
         data-toplu-yuzde="{{ $d['yuzde'] }}"
         @if ($d['calisiyor'])
             data-toplu-ilerleme
             data-toplu-ilerleme-adres="{{ route('credentials.rotate.bulk.progress', $tur['parti']) }}"
         @endif>
    <div class="toplu__bas">
        <div class="toplu__halka" aria-hidden="true">
            <span class="toplu__yuzde" data-toplu-yuzde-metin>
                @if ($d['calisiyor'])
                    %{{ $d['yuzde'] }}
                @elseif ($sorunSayisi === 0)
                    <svg viewBox="0 0 24 24" class="toplu__isaret"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                @else
                    <svg viewBox="0 0 24 24" class="toplu__isaret"><path d="M12 7v6M12 16.5v.5"/></svg>
                @endif
            </span>
        </div>

        <div class="toplu__baslik">
            <span class="toplu__ust">Toplu parola güncelleme</span>
            <span class="toplu__ana">
                <strong data-toplu-biten>{{ $d['biten'] }}</strong><span class="toplu__toplam">/{{ $d['toplam'] }} makine</span>
                <span class="toplu__durum" data-toplu-metin>{{ $d['calisiyor'] ? '' : $d['metin'] }}</span>
            </span>
        </div>

        <span class="toplu__simdi" data-toplu-simdi @if (! $d['suren']) hidden @endif>
            <span class="toplu__nabiz" aria-hidden="true"></span>
            <span>Şu an</span>
            <strong data-toplu-suren>{{ $d['suren'] }}</strong>
        </span>

        @unless ($d['calisiyor'])
            {{-- Kapatmak turlara dokunmaz; yalnızca kartı ekrandan kaldırır. --}}
            <form method="POST" action="{{ route('credentials.rotate.bulk.dismiss', $tur['parti']) }}" class="toplu__kapat" data-yerinde>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn--sm btn--ghost" aria-label="Göstergeyi kapat">Kapat</button>
            </form>
        @endunless
    </div>

    {{-- Her makine bir parça; üstüne gelince adı ve durumu görünür. --}}
    <ol class="toplu__iz" data-toplu-iz>
        @foreach ($d['kayitlar'] as $k)
            <li class="toplu__parca toplu__parca--{{ $k['durum'] }}" title="{{ $k['ad'] }} · {{ $etiketler[$k['durum']] }}"></li>
        @endforeach
    </ol>

    <div class="toplu__alt">
        <span class="toplu__lejant" data-toplu-lejant>
            @foreach ($etiketler as $anahtar => $etiket)
                @php($sayi = collect($d['kayitlar'])->where('durum', $anahtar)->count())
                @if ($sayi > 0)
                    <span class="toplu__lej"><i class="toplu__renk toplu__parca--{{ $anahtar }}"></i>{{ $sayi }} {{ mb_strtolower($etiket) }}</span>
                @endif
            @endforeach
        </span>

        @if (! $d['calisiyor'] && $d['sorunlu'] !== [])
            {{-- Sorunlu makineler ADIYLA: hangisine bakılacağını aramak zorunda kalınmasın. --}}
            <span class="toplu__sorunlu">
                @foreach ($d['sorunlu'] as $s)
                    <span class="toplu__cip" title="{{ $s['durum'] }}">{{ $s['ad'] }}</span>
                @endforeach
            </span>
        @endif
    </div>
</section>
