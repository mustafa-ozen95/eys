@props(['user' => null, 'roles' => [], 'statuses' => [], 'defaultRole' => null])

<x-ui.panel title="Hesap">
    <div class="field-row">
        <x-form.field name="name" label="Ad">
            <x-form.input name="name" :value="$user?->name" required maxlength="120" />
        </x-form.field>

        <x-form.field name="email" label="E-posta">
            <x-form.input name="email" type="email" :value="$user?->email" required maxlength="190" mono />
        </x-form.field>
    </div>

    <div class="field-row">
        <x-form.field name="role" label="Rol"
                      hint="Rol NE yapılabileceğini söyler; HANGİ grupta yapılabileceğini izinler ekranı söyler.">
            {{--
                Kapalılık BAĞLI ÖZNİTELİKLE verilir, @disabled ile değil.
                Yönerge bileşen etiketinden ÖNCE derleniyor; araya giren
                <?php ?> bloğu yüzünden Blade etiketi artık bileşen olarak
                tanımıyor ve tarayıcıya ham <x-form.select> gidiyor — kutu
                ekranda hiç çizilmiyordu.
            --}}
            <x-form.select name="role" :options="$roles"
                           :selected="$user?->role?->uuid ?? $defaultRole"
                           :disabled="$user !== null && ! auth()->user()->can('updateRole', $user)" />
        </x-form.field>

        <x-form.field name="status" label="Durum" hint="Pasif hesap doğru parolayla da giriş yapamaz.">
            <x-form.select name="status" :options="$statuses"
                           :selected="$user?->status?->value ?? App\Enums\UserStatus::Active->value" />
        </x-form.field>
    </div>

    @if ($user !== null && ! auth()->user()->can('updateRole', $user))
        {{--
            Kendi rolünü kimse değiştiremez. Kutu kapalı ama asıl engel
            sunucudadır (bkz. UserPolicy::updateRole, UserRequest::data).
        --}}
        <input type="hidden" name="role" value="{{ $user->role?->uuid }}">
        <p class="text-sm text-muted">Kendi rolünüzü değiştiremezsiniz.</p>
    @endif
</x-ui.panel>

<x-ui.panel title="Parola"
            :subtitle="$user ? 'Boş bırakılırsa parola değişmez.' : 'En az 12 karakter; harf, rakam ve simge içermeli.'">
    <div class="field-row">
        <x-form.field name="password" label="Parola">
            <x-form.input name="password" type="password" value="" autocomplete="new-password"
                          :required="$user === null" />
        </x-form.field>

        <x-form.field name="password_confirmation" label="Parola (tekrar)">
            <x-form.input name="password_confirmation" type="password" value=""
                          autocomplete="new-password" :required="$user === null" />
        </x-form.field>
    </div>
</x-ui.panel>
