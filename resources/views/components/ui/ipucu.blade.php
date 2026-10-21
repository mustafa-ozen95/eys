@props(['baslik' => 'Bu nasıl çalışır?'])

{{--
    Soru işareti — yanındaki başlığın "bu nasıl oluyor" açıklaması.

    JS YOK: <details> öğesi açılıp kapanmayı kendisi yapar. Betik yüklenmese
    de çalışır ve klavyeyle erişilebilir (summary odak alır, Enter açar).

    Açıklama VARSAYILAN OLARAK KAPALI: ekranı her seferinde uzatmasın, ama
    soran kişi cevabı bir tık ötede bulsun. Burada anlatılan şey kaydın
    türüne göre değişiyor; metni çağıran yer verir.
--}}
<details class="ipucu">
    <summary class="ipucu__isaret" title="{{ $baslik }}" aria-label="{{ $baslik }}">?</summary>
    <div class="ipucu__govde">{{ $slot }}</div>
</details>
