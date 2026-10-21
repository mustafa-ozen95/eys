{{--
    Ekranın bildirim bloğu: başarı, hata ve doğrulama mesajları.

    Aynı üç blok her sayfada elle yazılıyordu. Biçim tek yerden gelmeli;
    aksi hâlde bir ekranda hata kırmızı, diğerinde gri olur.
--}}
@if (session('status'))
    <div class="alert alert--success gate__alert">{{ session('status') }}</div>
@endif

@if (session('error'))
    <div class="alert alert--danger gate__alert">{{ session('error') }}</div>
@endif

<x-ui.errors class="gate__alert" />
