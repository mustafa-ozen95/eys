<x-layout.app title="Yeni kullanıcı">
    <div class="page">
        <div class="page__inner">
            <x-ui.page-head title="Yeni kullanıcı"
                            subtitle="Hesap açıldıktan sonra grup izinleri ekranına yönlendirilirsiniz." />

            <x-ui.flash />

            <form method="POST" data-inline action="{{ route('users.store') }}" autocomplete="off">
                @csrf
                <x-users.form :roles="$roles" :statuses="$statuses" :default-role="$defaultRole" />

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Kaydet</button>
                    <a href="{{ route('users.index') }}" class="btn btn--secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
