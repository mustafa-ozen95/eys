<x-layout.app title="Hatalar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Hata kayıtları"
                            subtitle="Kayıt silinmez, yalnızca çözüldü işaretlenir. Aynı hata tekrar ederse yeniden açılır.">
                <x-slot:actions>
                    <span class="text-sm text-muted">{{ $openCount }} açık</span>
                </x-slot:actions>
            </x-ui.page-head>

            <x-logs.tabs />

            <x-ui.flash />

            <form method="GET" class="row row--wrap filter-bar">
                <x-ui.search name="q" :value="$query" placeholder="Mesaj, tür veya konum"
                             label="Hata filtrele" :standalone="false" />

                <x-form.select name="durum" :options="$durumlar" :selected="$durum" />

                <button type="submit" class="btn btn--sm">Filtrele</button>
            </form>

            @forelse ($errors as $error)
                <x-ui.panel>
                    <x-slot:title>{{ class_basename($error->type) }}</x-slot:title>

                    <x-slot:actions>
                        @if ($error->isReopened())
                            <x-ui.badge variant="red">Yeniden açıldı</x-ui.badge>
                        @elseif ($error->isResolved())
                            <x-ui.badge variant="green">Çözüldü</x-ui.badge>
                        @else
                            <x-ui.badge variant="yellow">Açık</x-ui.badge>
                        @endif

                        @if (! $error->isResolved() || $error->isReopened())
                            <form method="POST" data-yerinde action="{{ route('errors.resolve', $error) }}"
                                  data-confirm="Bu hata çözüldü olarak işaretlenecek."
                                  data-confirm-title="Çözüldü işaretle"
                                  data-confirm-reasonless>
                                @csrf
                                <button type="submit" class="btn btn--sm">Çözüldü</button>
                            </form>
                        @endif
                    </x-slot:actions>

                    <p class="error-message">{{ $error->message }}</p>

                    <x-ui.facts :rows="[
                        'Kez' => (string) $error->occurrences,
                        'İlk' => $error->first_seen_at->translatedFormat('d.m.Y H:i'),
                        'Son' => $error->last_seen_at->translatedFormat('d.m.Y H:i'),
                        'Konum' => $error->context_label,
                        'Dosya' => $error->file ? str($error->file)->after(base_path().'/')->value().':'.$error->line : null,
                        'Kişi' => $error->actor_name,
                        'IP' => $error->ip_address,
                        'Çözen' => $error->resolver?->name,
                    ]" />

                    @if ($error->trace)
                        <details class="error-trace">
                            <summary>Yığın izi</summary>
                            {{-- Argümansız üretilir; parola taşıyamaz. --}}
                            <pre class="error-trace__body">{{ $error->trace }}</pre>
                        </details>
                    @endif
                </x-ui.panel>
            @empty
                <x-ui.panel>
                    <x-ui.empty title="Hata yok"
                                :text="$durum === 'acik' ? 'Açık hata bulunmuyor.' : 'Hiç hata kaydedilmemiş.'" />
                </x-ui.panel>
            @endforelse

            {{ $errors->links() }}
        </div>
    </div>
</x-layout.app>
