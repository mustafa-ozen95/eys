@props(['name', 'label', 'hint' => 'Bu alanı görme yetkiniz yok. Kaydettiğinizde eski değeri olduğu gibi kalır.'])

{{--
    Görülemeyen alan.

    Değer sayfaya HİÇ girmez; kutu devre dışıdır, yani forma da katılmaz.
    Asıl koruma sunucudadır (bkz. CredentialRequest::gorulmeyeniKoru) —
    burası yalnızca kullanıcıya alanın neden boş olduğunu söyler.
--}}
<x-form.field :name="$name" :label="$label" :hint="$hint">
    <input class="input input--mono" type="text" id="{{ $name }}" value="•••" disabled>
</x-form.field>
