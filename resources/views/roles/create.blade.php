<x-layout.app title="Yeni rol">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Yeni rol" />
            <x-ui.flash />

            <form method="POST" data-yerinde action="{{ route('roles.store') }}">
                @csrf
                <x-roles.form :badges="$badges" :actions="$actions"
                              :action-labels="$actionLabels" :groups="$groups" :template="$template" />

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Kaydet</button>
                    <a href="{{ route('roles.index') }}" class="btn btn--secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
