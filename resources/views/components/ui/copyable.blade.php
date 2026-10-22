@props(['label' => 'Değer'])

{{--
    Gizli olmayan ama kopyalanması gereken değer: parola kutusuyla aynı
    biçim, aynı yer — eksik olan tek şey maske. Kullanıcı adı sır değildir,
    ama oturum açarken parolayla birlikte kullanılır; iki ayrı yerde durması
    kopyalamayı elle seçmeye bırakıyordu.
--}}
<span {{ $attributes->class('secret secret--acik') }}>
    <span class="mono secret__value" data-copy-source>{{ $slot }}</span>

    <button type="button" class="btn btn--icon secret__copy"
            data-copy-text aria-label="{{ $label }} kopyala">⧉</button>
</span>
