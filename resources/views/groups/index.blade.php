<x-layout.app title="Gruplar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Gruplar"
                            subtitle="Grup aynı zamanda yetki sınırıdır: kullanıcıya kayıt değil, grup verilir. Sıra sürükleyerek verilir.">
                <x-slot:actions>
                    <a href="{{ route('groups.create') }}" class="btn btn--primary btn--sm">Yeni grup</a>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.flash />

            <x-ui.search name="q" :value="$query" placeholder="Grup adı" label="Grup filtrele" />

            {{--
                GRUPLAR KATLANIR BÖLÜMLER HÂLİNDE. Başlık grubu anlatır; açılınca
                alt grupları görünür ve orada yönetilir: ekleme ve düzenleme
                pencerede (bkz. partials/sub-window), sıra sürükleyerek.

                İki seviye de SÜRÜKLENEREK sıralanır (bkz. sorting.js): dış
                liste grupları, her bölümün içindeki tablo alt grupları.
                Bölüm `?acik=` ile açık gelir: alt grup ekleyince/değiştirince
                sayfa yerinde yenileniyor ve çalışılan bölüm kapanmamalı.
            --}}
            <div data-order="{{ route('groups.reorder') }}" data-order-field="sira">
                @forelse ($groups as $group)
                    <x-ui.collapsible :title="$group->name"
                                   :subtitle="$group->kind->label().' · '.$group->credentials_count.' kayıt · '.($viewerCounts[$group->uuid] ?? 0).' görebilen'"
                                   :open="$acikGrup === $group->uuid"
                                   draggable="true"
                                   :data-order-id="$group->uuid">
                        <x-slot:prefix>
                            {{-- Tutamak ayrı bir hedef değil, "buradan sürüklenir" ipucu. --}}
                            <span class="handle" aria-hidden="true">⣿</span>
                        </x-slot:prefix>

                        {{--
                            Eylemler başlıkta DEĞİL, gövdede: başlık <summary>
                            ve içindeki her tık bölümü açıp kapatıyor; bunu
                            satır içi betikle durdurmak CSP'ye takılır.
                        --}}
                        {{-- Düğmeler sağda: grup başlığıyla çakışmasın. --}}
                        <div class="panel__body row row--wrap row--end">
                            <a href="{{ route('groups.edit', $group) }}" class="btn btn--sm">Grubu düzenle</a>

                            <a href="{{ route('groups.index', ['acik' => $group->uuid, 'pencere' => 'ekle', 'grup' => $group->uuid]) }}"
                               class="btn btn--primary btn--sm">Yeni alt grup</a>

                            <x-ui.delete-form :action="route('groups.destroy', $group)"
                                              confirm="{{ $group->name }} grubu ve içindeki {{ $group->credentials_count }} kayıt silinecek."
                                              title="Grubu sil"
                                              label="Grubu sil"
                                              verify="{{ $group->name }}" />
                        </div>

                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Alt grup</th>
                                    <th class="text-right">Kayıt</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody data-order="{{ route('groups.sub.reorder', $group) }}" data-order-field="sira">
                                @forelse ($group->altGruplar as $altGrup)
                                    <tr draggable="true" data-order-id="{{ $altGrup->uuid }}">
                                        <td data-label="Alt grup">
                                            <span class="handle" aria-hidden="true">⣿</span>{{ $altGrup->name }}
                                        </td>
                                        <td data-label="Kayıt" class="mono text-right">{{ $altGrup->credentials_count }}</td>
                                        <td>
                                            <div class="table__row-actions">
                                                <a href="{{ route('groups.index', ['acik' => $group->uuid, 'pencere' => 'duzenle', 'alt' => $altGrup->uuid]) }}"
                                                   class="btn btn--sm">Düzenle</a>

                                                <x-ui.delete-form :action="route('groups.sub.destroy', [$group, $altGrup])"
                                                                  confirm="{{ $altGrup->name }} alt grubu kaldırılacak. İçindeki {{ $altGrup->credentials_count }} kayıt silinmez; grupta başlıksız kalır."
                                                                  title="Alt grubu kaldır"
                                                                  label="Kaldır"
                                                                  reasonless />
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-sm text-muted">
                                            Alt grup yok; kayıtlar tek liste hâlinde durur.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-ui.collapsible>
                @empty
                    <x-ui.panel>
                        <x-ui.empty title="Grup yok" text="İlk grubu ekleyerek başlayın." />
                    </x-ui.panel>
                @endforelse
            </div>
        </div>
    </div>

    @include('groups.partials.sub-window')
</x-layout.app>
