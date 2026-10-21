@props([
    'action',
    'confirm',
    'title' => 'Sil',
    'verify' => null,
    'label' => 'Sil',
    'small' => true,
    'reasonless' => false,
])

{{--
    Silme formu.

    Her silme aynı beş parçadan oluşur: form, csrf, DELETE, onay öznitelikleri
    ve kırmızı düğme. Biri onay özniteliğini unutursa silme sorusuz yapılır —
    bu yüzden kalıp bileşende durur, unutulacak bir adım kalmasın.

    Yazılı onay (verify) silmede STANDARTTIR; yalnızca kaydın adı yoksa
    boş bırakılır.

    Gerekçe (reasonless) VARSAYILAN OLARAK İSTENİR; kapatan taraf bunu
    bilerek yapar. Kapatıldığında sunucudaki doğrulama da AYNI kararı
    vermelidir — ekranda gizlenen ama sunucuda zorunlu kalan bir alan,
    kullanıcıyı dolduramadığı bir alan yüzünden reddeder.
--}}
{{-- Yerinde gönderilir: silme sayfayı yenileyip kaydırmayı ve açık paneli kaybettirmesin. --}}
<form method="POST" action="{{ $action }}"
      data-yerinde
      data-confirm="{{ $confirm }}"
      data-confirm-title="{{ $title }}"
      data-confirm-variant="danger"
      @if ($verify) data-confirm-verify="{{ $verify }}" @endif
      @if ($reasonless) data-confirm-reasonless @endif
      {{ $attributes }}>
    @csrf
    @method('DELETE')

    <button type="submit" @class(['btn', 'btn--danger', 'btn--sm' => $small])>{{ $label }}</button>
</form>
