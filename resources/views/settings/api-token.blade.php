<x-layout.app :title="$token->name.' · API jetonları'">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head :title="$token->name" :subtitle="$token->user?->email">
                <x-slot:actions>
                    <span class="mono text-sm text-muted">{{ $token->prefix }}…</span>
                    @if ($token->revoked_at)
                        <x-ui.badge variant="red">iptal</x-ui.badge>
                    @elseif ($token->isExpired())
                        <x-ui.badge variant="orange">süresi doldu</x-ui.badge>
                    @else
                        <x-ui.badge variant="green">geçerli</x-ui.badge>
                    @endif
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            {{--
                Sır YALNIZCA burada, yalnızca bir kez görünür. Sayfa
                yenilendiğinde kaybolur; kaydedilmediyse yenisi üretilir.
            --}}
            @if (session('token_plain'))
                <x-ui.panel title="Jeton" subtitle="Bu değer bir daha gösterilmeyecek. Şimdi kopyalayın.">
                    <div class="stack">
                        <textarea id="token-secret" class="input input--mono" rows="2" readonly>{{ session('token_plain') }}</textarea>
                        <div class="row">
                            <button type="button" class="btn btn--sm" data-copy="#token-secret">Kopyala</button>
                        </div>
                        <p class="text-sm text-muted">
                            İstek başlığı: <span class="mono">Authorization: Bearer &lt;jeton&gt;</span>
                        </p>
                    </div>
                </x-ui.panel>
            @endif

            <form method="POST" data-inline action="{{ route('api-tokens.update', $token) }}"
                  data-confirm="{{ $token->name }} jetonunun yetkileri ve sınırları değişecek."
                  data-confirm-title="Jetonu kaydet"
                  data-confirm-verify="{{ $token->name }}">
                @csrf
                @method('PUT')

                <x-ui.panel title="Jeton">
                    <div class="form-grid">
                        <x-form.field name="name" label="Jeton adı" id="token-name">
                            <x-form.input name="name" id="token-name" :value="old('name', $token->name)" required />
                        </x-form.field>

                        {{-- Sahibi sonradan değişmez: aynı sır başka bir yetkiye açılırdı. --}}
                        <x-form.field name="owner_readonly" label="Sahibi" id="token-owner"
                                      hint="Değiştirilemez; başka bir sahip için yeni jeton çıkarılır.">
                            <x-form.input name="owner_readonly" id="token-owner"
                                          :value="$token->user?->name" disabled />
                        </x-form.field>

                        <x-form.field name="lifetime_days" label="Ömür (gün)" id="token-lifetime"
                                      :hint="($token->expires_at
                                          ? 'Şu anki bitiş: '.$token->expires_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i').'. Yeni bir sayı bugünden itibaren sayılır. '
                                          : 'Şu anda süresiz. ')
                                          .'0 yazmak süresiz yapar; süresiz jeton unutulur, unutulan jeton sızar.'">
                            <x-form.input type="number" name="lifetime_days" id="token-lifetime" min="0" max="3650"
                                          :value="$token->expires_at ? null : 0" />
                        </x-form.field>

                        <x-form.field name="allowed_ips" label="İzinli adresler" id="token-addresses"
                                      class="field--wide"
                                      hint="Her satıra bir IP ya da ağ. Boş bırakılırsa her yerden kabul edilir.">
                            <x-form.textarea name="allowed_ips" id="token-addresses" rows="3"
                                             class="input--mono" :value="$token->allowed_ips ?? []" />
                        </x-form.field>
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Yetkiler"
                            subtitle="Jeton yalnızca işaretlenenleri yapabilir. Sahibinin panel yetkisi ayrıca sınırlar: ikisinin kesişimi geçerlidir.">
                    @foreach ($resources as $resource)
                        <div class="grid-row grid-row--static role-source">
                            <span>{{ $resource['label'] }}</span>
                            <span class="row row--wrap">
                                @foreach ($resource['actions'] as $action)
                                    <label class="checkbox">
                                        <input type="checkbox"
                                               name="abilities[{{ $resource['key'] }}][]"
                                               value="{{ $action }}"
                                               @checked(in_array($action, $abilities[$resource['key']] ?? [], true))>
                                        <span>{{ $actionLabels[$action] ?? $action }}</span>
                                    </label>
                                @endforeach
                            </span>
                        </div>
                    @endforeach
                </x-ui.panel>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Kaydet</button>
                    @unless ($token->revoked_at)
                        <button type="submit" form="token-secret-rotate" class="btn">Yeni sır üret</button>
                    @endunless

                    @unless ($token->revoked_at)
                        <button type="submit" form="token-revoke" class="btn btn--danger">İptal et</button>
                    @endunless

                    <button type="submit" form="token-delete" class="btn btn--danger">Sil</button>
                </div>
            </form>

            <form method="POST" data-inline id="token-secret-rotate" hidden action="{{ route('api-tokens.rotate', $token) }}"
                  data-confirm="Yeni bir sır üretilecek. Bu jetonu kullanan her sistem ANINDA erişimini kaybeder."
                  data-confirm-title="Yeni sır üret" data-confirm-variant="danger"
                  data-confirm-verify="{{ $token->name }}">
                @csrf
                @method('PUT')
            </form>

            <form method="POST" data-inline id="token-revoke" hidden action="{{ route('api-tokens.revoke', $token) }}"
                  data-confirm="Jeton iptal edilecek ve bir daha kullanılamayacak. Kayıt için silinmeden durur."
                  data-confirm-title="Jetonu iptal et" data-confirm-variant="danger"
                  data-confirm-verify="{{ $token->name }}">
                @csrf
                @method('PUT')
            </form>

            <form method="POST" data-inline id="token-delete" hidden action="{{ route('api-tokens.destroy', $token) }}"
                  data-confirm="Jeton kaydı tamamen silinecek. İptal etmek çoğu durumda yeterlidir; silmek geçmişi de götürür."
                  data-confirm-title="Jetonu sil" data-confirm-variant="danger"
                  data-confirm-verify="{{ $token->name }}">
                @csrf
                @method('DELETE')
            </form>
        </div>
    </div>
</x-layout.app>
