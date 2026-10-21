@props(['rows' => []])

{{-- $rows: ['Anahtar' => 'değer'] — teknik değerler mono gösterilir --}}
<dl {{ $attributes->class('facts') }}>
    @foreach ($rows as $key => $value)
        <div class="facts__row">
            <dt class="facts__key">{{ $key }}</dt>
            <dd class="facts__value">{{ $value ?? '—' }}</dd>
        </div>
    @endforeach
</dl>
