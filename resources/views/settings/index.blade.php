<x-layout.app title="Ayarlar">
    <div class="page">
        <div class="page__inner">
            <x-ui.page-head title="Ayarlar" :subtitle="$definition['description'] ?? null" />

            <x-ui.tabs :items="$tabs" />

            <x-ui.flash />

            <form method="POST" data-yerinde action="{{ route('settings.update') }}"
                  data-confirm="{{ $definition['label'] }} ayarları değişecek."
                  data-confirm-title="Ayarları kaydet"
                  data-confirm-reasonless>
                @csrf
                @method('PUT')
                <input type="hidden" name="group" value="{{ $group }}">

                <x-ui.panel :title="$definition['label']">
                    @foreach ($settings as $setting)
                        @if ($setting->type === 'bool')
                            <div class="field">
                                <span class="field__label">{{ $setting->label }}</span>
                                <label class="checkbox">
                                    <input type="checkbox" name="{{ $setting->field() }}" value="1"
                                           @checked(old($setting->field(), $values[$setting->key]))>
                                    <span>Açık</span>
                                </label>
                                @if ($setting->hint)
                                    <span class="field__hint">{{ $setting->hint }}</span>
                                @endif
                                @error($setting->field())
                                    <span class="field__error">{{ $message }}</span>
                                @enderror
                            </div>
                        @elseif ($setting->type === 'select')
                            <x-form.field :name="$setting->field()"
                                          :label="$setting->label"
                                          :hint="$setting->hint">
                                <select class="input" name="{{ $setting->field() }}" id="{{ $setting->field() }}">
                                    @foreach ($setting->options() as $deger => $etiket)
                                        <option value="{{ $deger }}"
                                                @selected(old($setting->field(), $values[$setting->key]) === $deger)>{{ $etiket }}</option>
                                    @endforeach
                                </select>
                            </x-form.field>
                        @else
                            <x-form.field :name="$setting->field()"
                                          :label="$setting->unit ? $setting->label.' ('.$setting->unit.')' : $setting->label"
                                          :hint="$setting->hint">
                                <x-form.input :name="$setting->field()"
                                              :type="$setting->type === 'int' ? 'number' : 'text'"
                                              :value="$values[$setting->key]"
                                              :mono="$setting->mono" />
                            </x-form.field>
                        @endif
                    @endforeach
                </x-ui.panel>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</x-layout.app>
