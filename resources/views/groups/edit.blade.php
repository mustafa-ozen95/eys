<x-layout.app :title="$group->name">
    <div class="page">
        <div class="page__inner">
            <x-ui.page-head :title="$group->name" subtitle="Grup bilgileri" />

            <x-ui.flash />

            <form method="POST" data-inline action="{{ route('groups.update', $group) }}">
                @csrf
                @method('PUT')

                <x-groups.form :group="$group" :kinds="$kinds" />

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Güncelle</button>
                    <a href="{{ route('groups.index', ['acik' => $group->uuid]) }}" class="btn btn--secondary">Vazgeç</a>
                </div>
            </form>

            {{-- Alt gruplar burada DEĞİL: Gruplar sayfasında, grubun bölümünde yönetilir. --}}
        </div>
    </div>
</x-layout.app>
