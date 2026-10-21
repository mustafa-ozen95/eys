<x-layout.app title="Kullanıcılar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Kullanıcılar"
                            subtitle="Kayıt olma ekranı yoktur; hesabı yönetici açar ve grup iznini yönetici verir.">
                <x-slot:actions>
                    <a href="{{ route('users.create') }}" class="btn btn--primary btn--sm">Yeni kullanıcı</a>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.flash />

            <x-ui.search name="q" :value="$query" placeholder="Ad veya e-posta" label="Kullanıcı filtrele" />

            <x-ui.panel flush>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ad</th>
                            <th>E-posta</th>
                            <th>Rol</th>
                            <th>Durum</th>
                            <th class="text-right">Yetki</th>
                            <th>Son giriş</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td data-label="Ad">{{ $user->name }}</td>
                                <td data-label="E-posta" class="mono text-muted">{{ $user->email }}</td>
                                <td data-label="Rol">
                                    @if ($user->role)
                                        <x-ui.badge :variant="$user->role->badge">{{ $user->role->label }}</x-ui.badge>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td data-label="Durum"><x-ui.status-dot :status="$user->status" /></td>
                                <td data-label="Yetki" class="mono text-right">
                                    {{ $user->isAdmin() ? 'hepsi' : $user->permissions->where('action', 'view')->count() }}
                                </td>
                                <td data-label="Son giriş" class="mono text-muted">
                                    {{ $user->last_login_at?->translatedFormat('d.m.Y H:i') ?? '—' }}
                                </td>
                                <td>
                                    <div class="table__row-actions">
                                        <a href="{{ route('users.permissions.edit', $user) }}" class="btn btn--sm">Yetkiler</a>
                                        <a href="{{ route('users.edit', $user) }}" class="btn btn--sm">Düzenle</a>

                                        @can('delete', $user)
                                            <x-ui.delete-form :action="route('users.destroy', $user)"
                                                              confirm="{{ $user->name }} hesabı silinecek."
                                                              title="Kullanıcıyı sil"
                                                              verify="{{ $user->email }}" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.panel>

            {{ $users->links() }}
        </div>
    </div>
</x-layout.app>
