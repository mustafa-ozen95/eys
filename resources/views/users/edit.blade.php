<x-layout.app :title="$user->name">
    <div class="page">
        <div class="page__inner">
            <x-ui.page-head :title="$user->name" :subtitle="$user->email">
                <x-slot:actions>
                    <a href="{{ route('users.permissions.edit', $user) }}" class="btn btn--sm">Grup izinleri</a>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.flash />

            <form method="POST" data-inline action="{{ route('users.update', $user) }}" autocomplete="off">
                @csrf
                @method('PUT')

                <x-users.form :user="$user" :roles="$roles" :statuses="$statuses" :default-role="$defaultRole" />

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Güncelle</button>
                    <a href="{{ route('users.index') }}" class="btn btn--secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
