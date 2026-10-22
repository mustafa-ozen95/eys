@props(['source', 'username' => null])

{{--
    Gizli değer. Parola sayfa kaynağında HİÇ bulunmaz: göz simgesine
    basıldığında ayrı bir istekle alınır ve o istek sunucuda kayda geçer.

    $source: değeri döndüren POST adresi.
--}}
<span {{ $attributes->class('secret') }} data-secret data-secret-open="false" data-secret-source="{{ $source }}">
    @if ($username)
        <span class="mono text-muted">{{ $username }}</span>
        <span class="text-muted">·</span>
    @endif

    <span class="mono secret__value" data-secret-value>••••••••</span>

    <button type="button" class="btn btn--icon secret__toggle"
            data-secret-show aria-label="Parolayı göster">👁</button>

    {{--
        Kopyalama yalnızca değer açıkken görünür: kapalıyken kopyalanacak bir
        şey yok ve düğmenin orada durması "gizliyken de kopyalanabilir"
        izlenimi verirdi.
    --}}
    <button type="button" class="btn btn--icon secret__copy"
            data-secret-copy aria-label="Parolayı kopyala" hidden>⧉</button>
</span>
