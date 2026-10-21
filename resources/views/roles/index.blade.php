<x-layout.app title="Roller">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Roller"
                            subtitle="Rol tek başına yetki değildir; hazır bir yetki şablonudur. Şablon uygulandıktan sonra kişiye özel istisnalar yetki ekranından verilir.">
                <x-slot:actions>
                    <a href="{{ route('roles.create') }}" class="btn btn--primary btn--sm">Yeni rol</a>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.tabs :items="$tabs" />

            <x-ui.flash />

            <x-ui.panel flush>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Rol</th>
                                <th>Kısa ad</th>
                                <th>Açıklama</th>
                                <th class="text-right">Kullanıcı</th>
                                <th>Hazır yetkiler</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($roles as $role)
                                <tr>
                                    <td data-label="Rol">
                                        <x-ui.badge :variant="$role->badge">{{ $role->label }}</x-ui.badge>
                                        @if ($role->is_default)
                                            <span class="text-sm text-muted">varsayılan</span>
                                        @endif
                                    </td>
                                    <td data-label="Kısa ad" class="mono text-muted">{{ $role->slug }}</td>
                                    <td data-label="Açıklama" class="text-muted">{{ $role->description ?: '—' }}</td>
                                    <td data-label="Kullanıcı" class="mono text-right">{{ $role->users_count }}</td>
                                    <td data-label="Hazır yetkiler" class="text-sm text-muted">
                                        {{ $role->is_admin ? 'her şeyi geçer' : $role->templateSummary }}
                                    </td>
                                    <td>
                                        <div class="table__row-actions">
                                            <a href="{{ route('roles.edit', $role) }}" class="btn btn--sm">Düzenle</a>

                                            @can('delete', $role)
                                                <x-ui.delete-form :action="route('roles.destroy', $role)"
                                                                  confirm="{{ $role->label }} rolü silinecek."
                                                                  title="Rolü sil"
                                                                  verify="{{ $role->slug }}" />
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
