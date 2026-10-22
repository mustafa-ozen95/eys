{{--
    403 — YETKİSİZ ERİŞİM DENEMESİ.

    Ötekilerden ayrı duruyor çünkü anlamı ayrı: 404 "burada bir şey yok"
    der, 403 "burada bir şey var ama SENİN DEĞİL" der. Aynı sade kalıpla
    gösterilince ikisi karışıyordu ve yetki reddi sıradan bir hata gibi
    görünüyordu.

    Sayfa suçlamıyor, hatırlatıyor: erişim kayıtlı bir panelde denendi ve
    kimin ne istediği görünür. Yol da yazılıyor — kullanıcı yanlış bağlantıya
    tıklamışsa hangisi olduğunu görsün, yöneticiye söyleyebilsin.
--}}
<x-layout.gate title="403">
    <div class="gate__mark gate__mark--warning mono">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <path d="M12 3 3 7v5c0 5 3.8 8.4 9 9 5.2-.6 9-4 9-9V7l-9-4Z" stroke-linejoin="round" />
            <path d="M12 8.5v4.2M12 16.2h.01" stroke-linecap="round" />
        </svg>
        403
    </div>

    <p class="gate__lead">
        <strong>Yetkisiz erişim denemesi.</strong><br>
        Bu sayfa var, ancak hesabınızın ona erişim yetkisi yok.
    </p>

    <p class="gate__path mono">{{ Str::limit(request()->path() === '/' ? '/' : '/'.request()->path(), 60) }}</p>

    <p class="gate__lead gate__lead--small">
        Yetkiyi rolünüz belirler. Gerekiyorsa panel yöneticisinden isteyin;
        yetkiler kayıt ve grup düzeyinde tek tek verilebiliyor.
    </p>

    <a href="{{ url('/') }}" class="btn btn--primary btn--block">Panele dön</a>
</x-layout.gate>
