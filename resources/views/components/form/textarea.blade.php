@props(['name', 'value' => null, 'rows' => 3, 'id' => null])

{{--
    $value bir LİSTE de olabilir: "her satıra bir tane" biçiminde girilen
    alanlar (adres listeleri gibi) sunucuda diziye çevrilir ve doğrulama
    hatasından sonra old() da dizi döner. Diziyi satırlara açmak burada
    yapılır; her çağıranın ayrı ayrı düşünmesi gerekmez.

    (array) hem tek metni hem null'ı doğru karşılar: metin tek elemanlı
    diziye, null boş diziye döner.
--}}
<textarea {{ $attributes->except('id')->class('textarea') }}
          name="{{ $name }}"
          id="{{ $id ?? $name }}"
          rows="{{ $rows }}"
          aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}">{{ implode("\n", (array) old($name, $value)) }}</textarea>
