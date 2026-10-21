@props([
    'id',
    'window',
    'title',
    'action',
    'method' => 'POST',
    'submit' => 'Kaydet',
    'open' => false,
    'credential' => null,
    'groupOptions' => [],
    'altGrupOptions' => [],
    'altGrupHaritasi' => [],
    'accessTypes' => [],
    'operatingSystems' => [],
    'canRestrict' => false,
    'restrictableRoles' => null,
    'restrictedRoleUuids' => [],
    'grantedRoleUuids' => [],
    'konsolRoleUuids' => [],
    'gormeRoleUuids' => [],
    'rolGorunum' => [],
    'portalRoleUuids' => [],
    'rotationDrivers' => [],
    'rotationCredentialOptions' => [],
    'makineHesabiSurucileri' => [],
    'selectedGroup' => null,
    'visibleFields' => [],
    'lockedFields' => [],
])

{{--
    Kayıt formu ayrı sayfa DEĞİL, listenin üstünde açılan penceredir: kullanıcı
    hangi grupta olduğunu ve neyin yanına eklediğini görmeden form doldurmaz.

    Ekleme ve düzenleme aynı bileşeni kullanır; içindeki form da aynıdır
    (x-credentials.form). Kural kopyalanmaz, paylaştırılır.
--}}
<x-ui.modal :id="$id" :title="$title" wide :open="$open">
    {{--
        KAYDETMEK SAYFAYI YENİLEMEZ.

        Form normal gönderiliyordu ve tarayıcı tam sayfa yenilemesi yapıyordu:
        ekran beyaza düşüyor, tema ve betikler baştan yükleniyor, açık olan
        sağ panel ile kaydırma konumu kayboluyordu. Panelde bunun karşılığı
        zaten var — `data-yerinde` formu fetch ile gönderiyor ve dönen sayfayı
        gövdede değiştiriyor (bkz. navigation.js). Doğrulama hatasında da
        aynı yol işliyor: sunucu pencereyi geri açan sayfayı üretiyor.
    --}}
    <form method="POST" action="{{ $action }}" autocomplete="off" data-yerinde>
        @csrf

        @if ($method !== 'POST')
            @method($method)
        @endif

        {{--
            Doğrulama hatasında tarayıcı listeye geri döner ve adres çubuğu
            değişmemiştir; hangi pencerenin geri açılacağını yalnızca bu alan
            söyleyebilir. Doğrulamaya ve modele girmez.
        --}}
        <input type="hidden" name="_pencere" value="{{ $window }}">

        <div class="modal__body">
            <x-ui.errors class="modal__alert" />

            <x-credentials.form :can-restrict="$canRestrict" :restrictable-roles="$restrictableRoles" :restricted-role-uuids="$restrictedRoleUuids" :granted-role-uuids="$grantedRoleUuids" :konsol-role-uuids="$konsolRoleUuids" :gorme-role-uuids="$gormeRoleUuids" :rol-gorunum="$rolGorunum" :portal-role-uuids="$portalRoleUuids" :credential="$credential"
                                :group-options="$groupOptions"
                                :alt-grup-options="$altGrupOptions"
                                :alt-grup-haritasi="$altGrupHaritasi"
                                :access-types="$accessTypes"
                                :operating-systems="$operatingSystems"
                                :rotation-drivers="$rotationDrivers"
                                :rotation-credential-options="$rotationCredentialOptions"
                                :makine-hesabi-surucileri="$makineHesabiSurucileri"
                                :selected-group="$selectedGroup"
                                :visible-fields="$visibleFields"
                                :locked-fields="$lockedFields" />
        </div>

        <x-ui.modal-actions :submit="$submit" />
    </form>
</x-ui.modal>
