@if ($goster)
    {{--
        YALNIZCA YÖNETİCİYE: güncellemeyi o başlatır. Ayrıntı ve yeni
        commit'lerin listesi Ayarlar → Güncelleme'de.
    --}}
    <div class="alert alert--info alert--spaced" role="status">
        <strong>Panelin yeni sürümü var.</strong>
        Çalışan sürüm {{ $geride }} commit geride.
        <a href="{{ route('update.index') }}">Ayarlar → Güncelleme</a>
    </div>
@endif
