<x-layout.app title="API jetonları · Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar"
                            subtitle="Dışarıya açılan okuma arayüzünün anahtarları. Jeton sahibi adına konuşur ve onun yetkilerini AŞAMAZ." />

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            <x-ui.panel title="Jetonlar"
                        subtitle="Sır yalnızca çıkarıldığı anda gösterilir. Kaybolursa geri gösterilemez; yenisi üretilir.">
                <x-slot:actions>
                    <a href="{{ route('api-tokens.docs') }}" class="btn btn--sm">Belge</a>
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="token-add">Jeton çıkar</button>
                </x-slot:actions>

                @forelse ($tokens as $token)
                    <div class="grid-row grid-row--static setting-row">
                        <span>
                            <a href="{{ route('api-tokens.show', $token) }}">{{ $token->name }}</a>
                            <div class="mono text-sm text-muted">{{ $token->prefix }}…</div>
                        </span>
                        <span>
                            @if ($token->revoked_at)
                                <x-ui.badge variant="red">iptal</x-ui.badge>
                            @elseif ($token->isExpired())
                                <x-ui.badge variant="orange">süresi doldu</x-ui.badge>
                            @else
                                <x-ui.badge variant="green">geçerli</x-ui.badge>
                            @endif
                        </span>
                        <span class="text-muted truncate">
                            {{ $token->user?->name ?? '—' }} ·
                            {{ count($token->abilityMap()) }} kaynak ·
                            {{ ($token->allowed_ips ?? []) === [] ? 'her adresten' : count($token->allowed_ips).' adres kuralı' }}
                            @if ($token->last_used_at)
                                · son kullanım {{ $token->last_used_at->diffForHumans() }}
                            @endif
                        </span>
                        <span class="text-right">
                            <a href="{{ route('api-tokens.show', $token) }}" class="btn btn--sm">Düzenle</a>
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-muted">Henüz jeton çıkarılmadı.</p>
                @endforelse
            </x-ui.panel>
        </div>
    </div>

    <x-ui.modal id="token-add" title="API jetonu çıkar" wide>
        <form method="POST" data-inline action="{{ route('api-tokens.store') }}"
              data-confirm="Yeni bir API jetonu çıkarılacak. Sır yalnızca bir kez gösterilir."
              data-confirm-title="Jeton çıkar">
            @csrf
            <div class="modal__body stack">
                <x-form.field name="name" label="Jeton adı" id="token-name"
                              hint="Nerede kullanıldığı yazılsın; iptal ederken hangisi olduğu bilinmeli.">
                    <x-form.input name="name" id="token-name" required placeholder="izleme-sistemi" />
                </x-form.field>

                {{--
                    Jeton sahibi adına konuşur ve onun yetkilerini aşamaz.
                    Sonradan değiştirilemez.
                --}}
                <x-form.field name="user" label="Sahibi" id="token-owner"
                              hint="Jeton bu kullanıcının yetkilerini taşır ve onları aşamaz. Sonradan değiştirilemez.">
                    <x-form.select name="user" id="token-owner" :options="$users" />
                </x-form.field>

                <x-form.field name="lifetime_days" label="Ömür (gün)" id="token-lifetime"
                              hint="0 ya da boş: süresiz. Süresiz jeton unutulur, unutulan jeton sızar.">
                    <x-form.input type="number" name="lifetime_days" id="token-lifetime" min="0" max="3650"
                                  :value="$defaultLifetime ?: null" />
                </x-form.field>

                <x-form.field name="allowed_ips" label="İzinli adresler" id="token-addresses"
                              hint="Her satıra bir IP ya da ağ (192.0.2.0/24). Boş bırakılırsa her yerden kabul edilir.">
                    <x-form.textarea name="allowed_ips" id="token-addresses" rows="3" class="input--mono" />
                </x-form.field>

                <x-form.field name="abilities" label="Yetkiler" id="token-abilities"
                              hint="Jeton yalnızca işaretlenenleri yapabilir; sahibinin yetkisi ayrıca sınırlar.">
                    <div class="stack">
                        @foreach ($resources as $resource)
                            <div class="grid-row grid-row--static role-source">
                                <span>{{ $resource['label'] }}</span>
                                <span class="row row--wrap">
                                    @foreach ($resource['actions'] as $action)
                                        <label class="checkbox">
                                            <input type="checkbox"
                                                   name="abilities[{{ $resource['key'] }}][]"
                                                   value="{{ $action }}">
                                            <span>{{ $actionLabels[$action] ?? $action }}</span>
                                        </label>
                                    @endforeach
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-form.field>
            </div>
            <x-ui.modal-actions submit="Çıkar" />
        </form>
    </x-ui.modal>
</x-layout.app>
