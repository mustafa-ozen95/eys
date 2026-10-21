@props(['name', 'type' => 'text', 'value' => null, 'mono' => false, 'id' => null])

<input {{ $attributes->except('id')->class(['input', 'input--mono' => $mono]) }}
       type="{{ $type }}"
       name="{{ $name }}"
       id="{{ $id ?? $name }}"
       value="{{ old($name, $value) }}"
       aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}">
