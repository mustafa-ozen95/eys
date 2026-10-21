@props(['name' => null, 'options' => [], 'selected' => null, 'id' => null])

{{--
    $options: değer => etiket. Değer yerine dizi verilirse optgroup açılır:
    grup başlığı => (değer => etiket).

    $name boş bırakılabilir: kutu ekranda dursun ama form ile GÖNDERİLMESİN
    istenen yerler vardır (ör. elle girişe geçilebilen bir seçici, elle giriş
    açıkken). Adı olmayan kutunun eski girdisi de yoktur; seçili değeri
    çağıran belirler.
--}}
<select {{ $attributes->except('id')->class('select') }}
        @if ($name !== null) name="{{ $name }}" @endif
        id="{{ $id ?? $name }}"
        aria-invalid="{{ $name !== null && $errors->has($name) ? 'true' : 'false' }}">
    @foreach ($options as $value => $label)
        @if (is_array($label))
            <optgroup label="{{ $value }}">
                @foreach ($label as $altDeger => $altEtiket)
                    <option value="{{ $altDeger }}"
                            @selected((string) ($name === null ? $selected : old($name, $selected)) === (string) $altDeger)>{{ $altEtiket }}</option>
                @endforeach
            </optgroup>
        @else
            <option value="{{ $value }}"
                    @selected((string) ($name === null ? $selected : old($name, $selected)) === (string) $value)>{{ $label }}</option>
        @endif
    @endforeach
</select>
