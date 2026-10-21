{{--
    TOPLU TURUN TEK GÖSTERGESİ.

    Kayıtları tek tek açıp bakmak yerine partinin tamamı burada izlenir.
    Her parça bir makinedir: hangisinin bittiği,
    hangisinin sürdüğü ve hangisinin sorunlu olduğu tek bakışta okunur.

    İlk çizim sunucudan gelir; tur sürerken yoklama devralır ve AYNI sınıf
    adlarıyla yeniden çizer (bkz. toplu-ilerleme.js). Sayıların hepsi tur
    kayıtlarından okunur; gösterge tahmin etmez.
--}}
<section class="toplu toplu--{{ $tur['sonuc'] }}"
         role="status" aria-live="polite" aria-label="Toplu parola güncelleme"
         data-toplu-kok
         data-toplu-yuzde="{{ $tur['durum']['yuzde'] }}"
         @if ($tur['durum']['calisiyor'])
             data-toplu-ilerleme
             data-toplu-ilerleme-adres="{{ route('credentials.rotate.bulk.progress', $tur['parti']) }}"
         @endif>
    <div class="toplu__bas">
        <div class="toplu__halka" aria-hidden="true">
            <span class="toplu__yuzde" data-toplu-yuzde-metin>
                @if ($tur['durum']['calisiyor'])
                    %{{ $tur['durum']['yuzde'] }}
                @elseif ($tur['sorunSayisi'] === 0)
                    <svg viewBox="0 0 24 24" class="toplu__isaret"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                @else
                    <svg viewBox="0 0 24 24" class="toplu__isaret"><path d="M12 7v6M12 16.5v.5"/></svg>
                @endif
            </span>
        </div>

        <div class="toplu__baslik">
            <span class="toplu__ust">Toplu parola güncelleme</span>
            <span class="toplu__ana">
                <strong data-toplu-biten>{{ $tur['durum']['biten'] }}</strong><span class="toplu__toplam">/{{ $tur['durum']['toplam'] }} makine</span>
                <span class="toplu__durum" data-toplu-metin>{{ $tur['durum']['calisiyor'] ? '' : $tur['durum']['metin'] }}</span>
            </span>
        </div>

        <span class="toplu__simdi" data-toplu-simdi @if (! $tur['durum']['suren']) hidden @endif>
            <span class="toplu__nabiz" aria-hidden="true"></span>
            <span>Şu an</span>
            <strong data-toplu-suren>{{ $tur['durum']['suren'] }}</strong>
        </span>

        @unless ($tur['durum']['calisiyor'])
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
        @foreach ($tur['durum']['kayitlar'] as $k)
            <li class="toplu__parca toplu__parca--{{ $k['durum'] }}" title="{{ $k['ad'] }} · {{ $tur['etiketler'][$k['durum']] }}"></li>
        @endforeach
    </ol>

    <div class="toplu__alt">
        <span class="toplu__lejant" data-toplu-lejant>
            @foreach ($tur['lejant'] as $satir)
                <span class="toplu__lej"><i class="toplu__renk toplu__parca--{{ $satir['anahtar'] }}"></i>{{ $satir['sayi'] }} {{ mb_strtolower($satir['etiket']) }}</span>
            @endforeach
        </span>

        @if (! $tur['durum']['calisiyor'] && $tur['durum']['sorunlu'] !== [])
            {{-- Sorunlu makineler ADIYLA: hangisine bakılacağını aramak zorunda kalınmasın. --}}
            <span class="toplu__sorunlu">
                @foreach ($tur['durum']['sorunlu'] as $s)
                    <span class="toplu__cip" title="{{ $s['durum'] }}">{{ $s['ad'] }}</span>
                @endforeach
            </span>
        @endif
    </div>
</section>
