@props(['title' => null])

<!doctype html>
<html lang="tr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        {{-- Ekran kilidi (bkz. session-lock.js): hareketsiz sekme bu süre sonunda oturumu kapatır. --}}
        <meta name="session-lifetime" content="{{ (int) config('session.lifetime') }}">
        <meta name="session-logout" content="{{ route('logout') }}">
        <meta name="session-login" content="{{ route('login') }}">
    @endauth

    {{--
        Tema çerezinin alan adı. Merkez ve paneller ayrı köklerdir; üst alan
        adı verilince seçim hepsinde paylaşılır (bkz. config/theme.php).
    --}}
    <meta name="theme-domain" content="{{ config('theme.cookie_domain') }}">
    <meta name="robots" content="noindex, nofollow">

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    {{-- Ana ekrana eklenen kısayol da simgeyi görsün. --}}
    <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="shell">
    <x-nav.topbar />

    <x-ui.certificate-warning />
    <x-ui.update-warning />

    {{ $slot }}
</div>

<x-ui.toast-host />
<x-ui.confirm-modal />
</body>
</html>
