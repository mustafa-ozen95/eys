@props(['group' => null, 'kinds' => []])

<x-ui.panel title="Grup bilgileri">
    <div class="field-row">
        <x-form.field name="name" label="Ad" hint="Listede sol sütunda görünür: “Grup A”, “Yedekleme”, “Portal”.">
            <x-form.input name="name" :value="$group?->name" required maxlength="120" />
        </x-form.field>

        <x-form.field name="kind" label="Tür" hint="Yalnızca renk ve sıralama içindir; yetki taşımaz.">
            <x-form.select name="kind" :options="$kinds" :selected="$group?->kind?->value" />
        </x-form.field>
    </div>

    <x-form.field name="description" label="Açıklama">
        <x-form.input name="description" :value="$group?->description" maxlength="190" />
    </x-form.field>

    {{-- Sıra burada sorulmaz: listede sürükleyerek verilir (bkz. sorting.js). --}}
</x-ui.panel>
