@props(['title' => null, 'wide' => false])

<!doctype html>
<html lang="tr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--
        Tema çerezinin alan adı. Merkez ve paneller ayrı köklerdir; üst alan
        adı verilince seçim hepsinde paylaşılır (bkz. config/tema.php).
    --}}
    <meta name="tema-alan" content="{{ config('tema.cerez_alani') }}">

    {{-- Giriş ekranı arama motorlarına ait değil. --}}
    <meta name="robots" content="noindex, nofollow">

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
{{--
    TEMA DÜĞMESİ GİRİŞ EKRANINDA DA DURUR.
    
    Düğme yalnızca üst çubuktaydı; giriş ekranı üst çubuk taşımıyor ve tema
    seçimi ancak içeri girdikten sonra yapılabiliyordu. Oysa ekranı en uzun
    süre bakılan hâliyle gören ilk yer burası.
--}}
<button type="button" class="btn btn--ghost btn--icon btn--sm gate__tema"
        data-theme-toggle aria-label="Temayı değiştir">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"
              stroke-linecap="round" />
    </svg>
</button>
@if ($wide)
    {{ $slot }}
@else
    <main class="gate">
        <div class="gate__panel">{{ $slot }}</div>
    </main>
@endif

<x-ui.toast-host />
</body>
</html>
