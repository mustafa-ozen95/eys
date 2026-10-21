@props(['items' => []])

{{-- $items: ['label' => ..., 'url' => ..., 'active' => bool] --}}
<nav {{ $attributes->class('tabs') }} aria-label="Sekmeler">
    @foreach ($items as $item)
        <a href="{{ $item['url'] }}"
           class="tabs__link"
           @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
