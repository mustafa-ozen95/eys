<x-layout.app :title="$user->name.' yetkileri'">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head :title="$user->name" :subtitle="$user->email">
                <x-slot:actions>
                    @if ($user->role)
                        <x-ui.badge :variant="$user->role->badge">{{ $user->role->label }}</x-ui.badge>
                    @endif

                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.flash />

            @if ($user->isAdmin())
                <div class="alert alert--info">
                    Bu kullanıcı yönetici: bütün gruplara ve bütün alanlara zaten erişir.
                    Aşağıdaki seçimler rolü değiştirilirse geçerli olur.
                </div>
            @endif

            <form method="POST" data-yerinde action="{{ route('users.permissions.update', $user) }}"
                  data-confirm="{{ $user->name }} kullanıcısının yetkileri değişecek."
                  data-confirm-title="Yetkileri kaydet"
                  data-confirm-verify="{{ $user->name }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="resource" value="{{ $resource }}">

                <x-ui.panel title="Bütün gruplar"
                            :subtitle="'“Rolden gelsin” seçili olan yetkide '.($user->role?->label ?? 'rol').' ne diyorsa o geçerlidir. '
                                .'İzin, rolün vermediğini ekler; yasak, verdiğini keser.'">
                    <div class="row row--wrap permission-wildcards">
                        @foreach ($actions as $action)
                            <label class="field field--cell">
                                <span class="field__label">
                                    {{ $actionLabels[$action] }}
                                    @if ($roleWildcard[$action])
                                        <span class="text-sm text-muted">· rolde var</span>
                                    @endif
                                </span>
                                <select class="select select--cell" name="wildcard[{{ $action }}]">
                                    @foreach ($stateOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($wildcard[$action] === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Grup bazlı istisnalar"
                            subtitle="“Rolden gelsin” o grupta rolün dediğini uygular. “Yasak”, rol ya da genel izin vermiş olsa bile o grupta keser."
                            flush>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Grup</th>
                                    @foreach ($recordActions as $action)
                                        <th>{{ $actionLabels[$action] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($records as $record)
                                    <tr>
                                        <td data-label="Grup">
                                            <div>{{ $record['title'] }}</div>
                                            <div class="text-sm text-muted">{{ $record['subtitle'] }}</div>
                                        </td>

                                        {{--
                                            data-label ŞART: dar ekranda tablo bloklara dönüşür
                                            ve sütun başlıkları kaybolur. Etiketsiz bırakıldığında
                                            ekranda adsız "Devral" kutuları kalıyor, hangisinin
                                            "Parola" olduğu anlaşılmıyordu.
                                        --}}
                                        @foreach ($recordActions as $action)
                                            <td data-label="{{ $actionLabels[$action] }}">
                                                <select class="select select--cell"
                                                        name="cells[{{ $action }}][{{ $record['id'] }}]"
                                                        aria-label="{{ $record['title'] }} · {{ $actionLabels[$action] }}">
                                                    @foreach ($stateOptions as $value => $label)
                                                        <option value="{{ $value }}"
                                                            @selected($record['states'][$action] === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>

                                                {{-- Rolün ne dediği: seçim değil, bilgi. --}}
                                                @if ($record['role'][$action])
                                                    <span class="text-sm text-muted">rolde var</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($recordActions) + 1 }}">
                                            <x-ui.empty title="Grup yok" />
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.panel>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Yetkileri kaydet</button>
                    <a href="{{ route('users.index') }}" class="btn btn--secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
