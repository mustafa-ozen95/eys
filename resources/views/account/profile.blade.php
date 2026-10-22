<x-layout.app title="Hesabım">
    <div class="page">
        <div class="page__inner">
            <x-ui.page-head title="Hesabım" subtitle="Kendi bilgileriniz ve parolanız." />

            <x-ui.flash />

            <x-ui.panel title="Bilgiler">
                <form method="POST" data-inline action="{{ route('account.profile.save') }}" class="form-narrow">
                    @csrf
                    @method('PUT')

                    <x-form.field name="name" label="Ad">
                        <x-form.input name="name" :value="$kullanici->name" required />
                    </x-form.field>

                    <x-form.field name="email" label="E-posta"
                                  :hint="($epostaKilitli ?? false) ? 'Merkezle giriş açıkken e-postayı yönetici değiştirir.' : 'Girişte kullandığınız adres.'">
                        <x-form.input name="email" type="email" :value="$kullanici->email" mono required
                                      :readonly="$epostaKilitli ?? false" />
                    </x-form.field>

                    <button type="submit" class="btn btn--primary btn--sm">Kaydet</button>
                </form>
            </x-ui.panel>

            <x-ui.panel title="Parola" subtitle="Değiştirdiğinizde diğer cihazlardaki oturumlarınız kapanır.">
                <form method="POST" data-inline action="{{ route('account.password') }}" class="form-narrow">
                    @csrf
                    @method('PUT')

                    {{-- Mevcut parola şart: oturumu ele geçiren biri hesabı devralamasın. --}}
                    <x-form.field name="mevcut" label="Mevcut parola">
                        <x-form.input name="mevcut" type="password" autocomplete="current-password" required />
                    </x-form.field>

                    <x-form.field name="password" label="Yeni parola" hint="En az 10 karakter, harf ve rakam içermeli.">
                        <x-form.input name="password" type="password" autocomplete="new-password" required />
                    </x-form.field>

                    <x-form.field name="password_confirmation" label="Yeni parola (tekrar)">
                        <x-form.input name="password_confirmation" type="password" autocomplete="new-password" required />
                    </x-form.field>

                    <button type="submit" class="btn btn--primary btn--sm">Parolayı değiştir</button>
                </form>
            </x-ui.panel>

            <x-ui.panel title="Yetkileriniz"
                        subtitle="Rolünüzü ve grup izinlerinizi buradan değiştiremezsiniz; onları yönetici verir.">
                <p class="text-sm text-muted">
                    Rol: <strong>{{ $kullanici->role?->label ?? '—' }}</strong>
                </p>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
