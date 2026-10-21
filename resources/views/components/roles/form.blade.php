@props([
    'role' => null,
    'badges' => [],
    'actions' => [],
    'actionLabels' => [],
    'groups' => [],

    // Hazır şablon: ['all' => [...], 'groups' => [grup adı => [...]]]
    'template' => ['all' => [], 'groups' => []],
])

<x-ui.panel title="Rol">
    <div class="field-row">
        <x-form.field name="label" label="Ad">
            <x-form.input name="label" :value="$role?->label" required maxlength="60" />
        </x-form.field>

        <x-form.field name="slug" label="Kısa ad"
                      :hint="$role?->is_system
                          ? 'Yerleşik rolün kısa adı değişmez: kod ve şablonlar ona bu adla bakıyor.'
                          : 'Yalnızca küçük harf, rakam ve tire.'">
            <x-form.input name="slug" :value="$role?->slug" mono maxlength="40"
                          :required="! $role?->is_system" :disabled="(bool) $role?->is_system" />
        </x-form.field>
    </div>

    <x-form.field name="description" label="Açıklama">
        <x-form.input name="description" :value="$role?->description" maxlength="190" />
    </x-form.field>

    <div class="field-row">
        <x-form.field name="badge" label="Renk">
            <x-form.select name="badge" :options="$badges" :selected="$role?->badge?->value" />
        </x-form.field>

        <x-form.field name="position" label="Sıra">
            <x-form.input name="position" type="number" :value="$role?->position ?? 0" min="0" max="9999" />
        </x-form.field>
    </div>

    <div class="field">
        <span class="field__label">Varsayılan</span>
        <label class="checkbox">
            <input type="checkbox" name="is_default" value="1"
                   @checked(old('is_default', $role?->is_default))
                   @disabled((bool) $role?->is_admin)>
            <span>Yeni kullanıcılara bu rol verilsin</span>
        </label>
        @if ($role?->is_admin)
            <span class="field__hint">Yönetici rolü varsayılan olamaz: “kullanıcı ekle” yetkisi, yönetici üretme yetkisi değildir.</span>
        @endif
    </div>
</x-ui.panel>

@if ($role?->is_admin)
    <div class="alert alert--info">
        Yönetici rolü bütün kontrolleri geçer; hazır yetki listesi tutmaz.
    </div>
@else
    <x-ui.panel title="Bütün gruplarda"
                subtitle="Şablon uygulandığında bu yetkiler her grupta verilir.">
        <div class="row row--wrap permission-wildcards">
            @foreach ($actions as $action)
                <label class="checkbox">
                    <input type="checkbox" name="template_all[]" value="{{ $action }}"
                           @checked(in_array($action, old('template_all', $template['all']), true))>
                    <span>{{ $actionLabels[$action] }}</span>
                </label>
            @endforeach
        </div>
    </x-ui.panel>

    <x-ui.panel title="Yalnızca seçili gruplarda"
                subtitle="Üstteki listeye EK yetkiler. “Her yerde görsün, parolayı yalnızca ağ gruplarında açsın” böyle kurulur."
                flush>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Grup</th>
                        @foreach ($actions as $action)
                            <th>{{ $actionLabels[$action] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group)
                        <tr>
                            <td data-label="Grup">
                                <div>{{ $group->name }}</div>
                                <div class="text-sm text-muted">{{ $group->credentials_count }} kayıt</div>
                            </td>

                            @foreach ($actions as $action)
                                <td data-label="{{ $actionLabels[$action] }}">
                                    <label class="checkbox">
                                        {{--
                                            Alan adı UUID taşır: sıralı birincil
                                            anahtar DOM'a çıkmaz.
                                        --}}
                                        <input type="checkbox"
                                               name="template_groups[{{ $group->uuid }}][]"
                                               value="{{ $action }}"
                                               @checked(in_array($action, old("template_groups.{$group->uuid}", $template['groups'][$group->name] ?? []), true))>
                                        <span class="visually-hidden">{{ $group->name }} · {{ $actionLabels[$action] }}</span>
                                    </label>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.panel>
@endif
