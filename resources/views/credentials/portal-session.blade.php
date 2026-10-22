<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Oturum açılıyor…</title>
</head>
<body>
{{--
    Geçici fırlatma sayfası.

    Parola yalnızca BU sayfanın gizli alanında bulunur ve sayfa açılır açılmaz
    hedef siteye gönderilip terk edilir. Kullanıcının ekranına, panosuna ve
    panelin hiçbir listesine uğramaz.

    Sayfa GET ile açılamaz (yalnızca POST rotası) ve önbelleğe alınmaz.
--}}
<p>{{ $credential->name }} için oturum açılıyor…</p>

{{--
    Alanlar ve gönderim kipi kayıttan değil TANIMLAYICIDAN gelir: kimi panel
    alan=değer ister, kimi JSON (bkz. PortalLogin).
--}}
<form id="session-form" method="POST" action="{{ $login->action }}"
      @if ($login->enctype) enctype="{{ $login->enctype }}" @endif>
    @foreach ($login->fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach

    <noscript>
        {{-- JavaScript kapalıysa kullanıcı kendi gönderir; parola yine görünmez. --}}
        <button type="submit">Devam et</button>
    </noscript>
</form>

{{--
    Gönderim AYRI BİR DOSYADAN: içerik CSP'si satır içi betikleri engelliyor.
    Dosya Vite paketinde değil — bu sayfa panelin düzenini kullanmayan, tek
    kullanımlık bir ara durak; oraya bütün paketi yüklemek anlamsız olurdu.
--}}
<script src="{{ asset('session-open.js') }}"></script>
</body>
</html>
