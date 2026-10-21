<x-layout.app title="Yeni grup">
    <div class="page">
        <div class="page__inner">
            <x-ui.page-head title="Yeni grup" />

            <x-ui.flash />

            <form method="POST" data-yerinde action="{{ route('groups.store') }}">
                @csrf
                <x-groups.form :kinds="$kinds" />

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Kaydet</button>
                    <a href="{{ route('groups.index') }}" class="btn btn--secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
