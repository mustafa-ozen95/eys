{{--
    500 — SEBEBİ YÖNETİCİYE YAZILIR.

    "Beklenmeyen bir hata oluştu, kayıtlar incelenmeli" cümlesi kimseye bir
    şey anlatmıyordu: yönetici de ekrana bakıp ayrıca kayıtlar ekranını açmak
    zorunda kalıyordu; yönetici "HTTP 500" yerine hatanın sebebini görmeli.

    Sebep YALNIZCA YÖNETİCİYE gösterilir. İstisna metni dosya yolu, sorgu
    parçası ya da cihaz adresi taşıyabiliyor; sıradan kullanıcıya bunları
    göstermenin faydası yok, riski var. Ötekilere hatanın kaydedildiği
    söylenir — asıl yapmaları gereken şey bu.
--}}
<x-layout.gate title="500">
    <div class="gate__mark gate__mark--uyari mono">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <path d="M12 3 3 7v5c0 5 3.8 8.4 9 9 5.2-.6 9-4 9-9V7l-9-4Z" stroke-linejoin="round" />
            <path d="M12 8.5v4.2M12 16.2h.01" stroke-linecap="round" />
        </svg>
        500
    </div>

    <p class="gate__lead">
        <strong>Beklenmeyen bir hata oluştu.</strong><br>
        İstek tamamlanamadı; hata kayıtlara yazıldı.
    </p>

    @if (auth()->user()?->isAdmin() && isset($exception))
        <p class="gate__yol mono">
            {{ class_basename($exception) }}: {{ Str::limit($exception->getMessage(), 200) }}
        </p>

        <p class="gate__lead gate__lead--kucuk">
            Tam dökümü — dosya, satır ve yığın izi — kayıtlar ekranında.
        </p>
    @endif

    <a href="{{ url('/') }}" class="btn btn--primary btn--block">Panele dön</a>
</x-layout.gate>
