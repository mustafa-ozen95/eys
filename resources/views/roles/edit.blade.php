<x-layout.app :title="$role->label">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head :title="$role->label" :subtitle="$role->description" />
            <x-ui.flash />

            <form method="POST" data-yerinde action="{{ route('roles.update', $role) }}">
                @csrf
                @method('PUT')

                <x-roles.form :role="$role" :badges="$badges" :actions="$actions"
                              :action-labels="$actionLabels" :groups="$groups" :template="$template" />

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Güncelle</button>
                    <a href="{{ route('roles.index') }}" class="btn btn--secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
