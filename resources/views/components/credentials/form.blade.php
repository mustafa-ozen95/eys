@props([
    'credential' => null,
    'groupOptions' => [],
    'altGrupOptions' => [],
    'altGrupHaritasi' => [],
    'accessTypes' => [],
    'operatingSystems' => [],
    'rotationDrivers' => [],
    'rotationCredentialOptions' => [],
    'makineHesabiSurucileri' => [],
    'selectedGroup' => null,
    'visibleFields' => [],

    /* Görünen ama değiştirilemeyen hedef alanları (Credential::kilitliHedefAlanlari). */
    'lockedFields' => [],

    /* Kayıt bazında rol kısıtı ve ek izin: yalnızca yöneticiye gösterilir. */
    'canRestrict' => false,
    'restrictableRoles' => null,
    'restrictedRoleUuids' => [],
    'grantedRoleUuids' => [],
    'konsolRoleUuids' => [],
    'gormeRoleUuids' => [],
    'rolGorunum' => [],
    'portalRoleUuids' => [],
])

@php
    $restrictableRoles = $restrictableRoles ?? collect();

    /*
     * KİLİTLİ HEDEF ALANI: değer görünür ama kutu SALT OKUNUR.
     * Kayıtta parola/döndürme kimliği var ve kullanıcının parola yetkisi
     * yok; adresi değiştirmek sırrı başka yere göndermek olurdu. Asıl
     * koruma sunucudadır (CredentialRequest::gorulmeyeniKoru).
     */
    $kilitNotu = 'Kayıtta parola var; adresi yalnızca parolayı görebilenler değiştirebilir.';

    /*
     * KOŞULLU ALANLAR (bkz. kosullu-alan.js).
     *
     * Form her alanı her kayıtta gösteriyordu: uzak masaüstü kaydında portal
     * formu, üyelik kaydında port, SSH kaydında RDP kipi. Gereksiz alan
     * kafa karıştırır; alan, açılır listedeki seçime ya da işaretlenen
     * kutuya göre görünür.
     *
     * Kurallar alanların hangi türde dolu olduğuna göre kuruldu: RDP kipi
     * yalnızca RDP'de, bağlantı adresi SSH/RDP/VNC dışında, portal alanları
     * yalnızca web ve panelde, adres ve port üyelik/lisans dışında dolu.
     * Yani bu kurallarla hiçbir dolu alan gizlenmiyor — ve betik ayrıca
     * DOLU ya da HATALI alanı hiçbir kuralla gizlemiyor.
     *
     * Sözdizimi: "alan:değer,değer" · "alan:!değer" (değilse) · "alan:*"
     * (doluysa / işaretliyse) · koşullar "|" ile VEYA'lanır.
     */
    $konsolTurleri = 'ssh,rdp,vnc';
    $adressizTurler = 'membership,license';
    $portalTurleri = 'panel,web';

    /*
     * Parola güncelleme: platformu döndürülebilen kayıtlar, web ve panel
     * (Plesk, mailcow, rspamd sürücüleri uygulama hesabı üzerinde çalışır)
     * ve sürücüsü elle seçilmiş her kayıt.
     */
    $guncellemeKosulu = 'operating_system:'.collect(App\Enums\OperatingSystem::cases())
        ->filter->rotatable()->map->value->implode(',')
        .'|access_type:'.$portalTurleri.'|rotation_driver:*';

    /* Platform => o platformda anlamlı erişim türleri (en olası önce). */
    $erisimHaritasi = collect(App\Enums\OperatingSystem::cases())
        ->mapWithKeys(fn ($os) => [$os->value => array_map(fn ($t) => $t->value, $os->accessTypes())])
        ->all();

    /* Rol istisnalarından biri işaretliyse "Gelişmiş" açık gelir: kısıt gizli kalmamalı. */
    $gelismisAcik = old('restricted_roles', $restrictedRoleUuids) !== []
        || old('granted_roles', $grantedRoleUuids) !== []
        || old('konsol_roles', $konsolRoleUuids) !== []
        || old('gorme_roles', $gormeRoleUuids) !== []
        || old('portal_roles', $portalRoleUuids) !== [];
@endphp

{{--
    Ekleme ve düzenleme AYNI formu kullanır. İki ayrı form olsaydı biri
    değiştiğinde diğeri sessizce eski kalırdı — kural kopyalanmaz, paylaştırılır.

    $visibleFields: alan adı => görülebilir mi. Boş dizi "hepsi görünür"
    demektir (yeni kayıtta korunacak eski değer yoktur). Görülemeyen alan
    BASILMAZ ve DEĞİŞTİRİLEMEZ: listede "•••" gösterip aynı değeri düzenleme
    formunda açık açık yazmak, maskelemeyi anlamsız kılardı.

    Gizlenen alan FORMDAN ÇIKMAZ: yalnızca görünmez, değeri gönderilmeye
    devam eder. Çıkarılsaydı tür değiştirilip kaydedildiğinde eski değer
    sessizce silinirdi.
--}}
<div class="kayit-formu" data-kosullu-form>
<x-ui.panel title="Kayıt bilgileri">
    <div class="field-row">
        <x-form.field name="group" label="Grup">
            <x-form.select name="group" :options="$groupOptions"
                           :selected="$credential?->group?->uuid ?? $selectedGroup" />
        </x-form.field>

        <x-form.field name="name" label="Ad" hint="Listede görünecek ad, ör. “SSH”, “vCenter iLO”.">
            <x-form.input name="name" :value="$credential?->name" required maxlength="120" />
        </x-form.field>
    </div>

    {{--
        ALT GRUP: grubun içindeki bölme (ör. Yedekleme → Grup A). Liste seçili gruba
        göre daralır (kosullu-alan.js · data-bagli); bölmesi olmayan grupta
        yalnızca "Yok" kalır. Alan hiçbir grupta bölme yoksa HİÇ çizilmez —
        tek seçenekli bir kutu soru değil gürültüdür.
    --}}
    @if (count($altGrupOptions) > 1)
        <x-form.field name="alt_grup" label="Alt grup" hint="Grubun içindeki bölme; listede başlık olarak görünür. Yetki taşımaz.">
            <x-form.select name="alt_grup" :options="$altGrupOptions"
                           :selected="$credential?->altGrup?->uuid"
                           data-bagli="group"
                           data-bagli-secenekler="{{ json_encode($altGrupHaritasi) }}" />
        </x-form.field>
    @endif

    {{--
        SIRA: önce NE (platform), sonra NASIL (erişim türü), sonra NEREDE
        (adres). Erişim türü listesi platforma göre daralır
        (bkz. OperatingSystem::accessTypes, kosullu-alan.js · data-bagli).
    --}}
    <div class="field-row">
        <x-form.field name="operating_system" label="Platform"
                      hint="Erişim türleri ve parola güncelleme buna göre gelir.">
            <x-form.select name="operating_system" :options="$operatingSystems"
                           :selected="$credential?->operating_system?->value" />
        </x-form.field>

        <x-form.field name="platform" label="Sürüm" hint="Ör. “Windows Server 2022”, “iLO 5”.">
            <x-form.input name="platform" :value="$credential?->platform" maxlength="60" />
        </x-form.field>
    </div>

    <div class="field-row">
        <x-form.field name="access_type" label="Erişim türü">
            <x-form.select name="access_type" :options="$accessTypes"
                           :selected="$credential?->access_type?->value"
                           data-bagli="operating_system"
                           data-bagli-secenekler="{{ json_encode($erisimHaritasi) }}" />
        </x-form.field>

        {{--
            RDP GÜVENLİK KİPİ — yalnızca uzak masaüstünde.

            Makineler aynı şeyi konuşmuyor: bazıları NLA'yı zorunlu kılıyor,
            bazılarının RDP sertifikası SHA-1 imzalı olduğu için TLS hiç
            kurulamıyor ve geriye yalnızca eski RDP güvenliği kalıyor. Karar
            makine başına.
        --}}
        <x-form.field name="rdp_security" label="RDP güvenlik kipi"
                      hint="Bağlantı “SSL/TLS connection failed” diyorsa “Eski RDP güvenliği”ni seçin."
                      data-goster="access_type:rdp">
            <x-form.select name="rdp_security"
                           :options="[
                               '' => 'Otomatik (varsayılan)',
                               'nla' => 'NLA (ağ düzeyinde kimlik doğrulama)',
                               'tls' => 'TLS',
                               'rdp' => 'Eski RDP güvenliği (TLS yok)',
                           ]"
                           :selected="old('rdp_security', $credential?->rdp_security ?? '')" />
        </x-form.field>
    </div>

    <div class="field-row">
        @if ($visibleFields['host'] ?? true)
            <x-form.field name="host" label="Adres" :hint="in_array('host', $lockedFields, true) ? $kilitNotu : 'IP ya da alan adı.'"
                          data-goster="access_type:!{{ $adressizTurler }}">
                <x-form.input name="host" :value="$credential?->host" maxlength="190" mono :readonly="in_array('host', $lockedFields, true)" />
            </x-form.field>
        @else
            <x-form.masked name="host" label="Adres" />
        @endif

        @if ($visibleFields['uplink'] ?? true)
            <x-form.field name="uplink" label="Uplink" hint="Bağlı olduğu üst cihazın adresi (switch)."
                          data-goster="access_type:!{{ $adressizTurler }}">
                <x-form.input name="uplink" :value="$credential?->uplink" maxlength="190" mono />
            </x-form.field>
        @else
            <x-form.masked name="uplink" label="Uplink" />
        @endif

        @if ($visibleFields['port'] ?? true)
            <x-form.field name="port" label="Port" :hint="in_array('port', $lockedFields, true) ? $kilitNotu : 'Varsayılandan farklıysa.'"
                          data-goster="access_type:!{{ $adressizTurler }}">
                <x-form.input name="port" type="number" :value="$credential?->port" min="1" max="65535" mono :readonly="in_array('port', $lockedFields, true)" />
            </x-form.field>
        @else
            <x-form.masked name="port" label="Port" />
        @endif
    </div>

    <div class="field-row">
        @if ($visibleFields['url'] ?? true)
            <x-form.field name="url" label="Bağlantı adresi" :hint="in_array('url', $lockedFields, true) ? $kilitNotu : 'Web arayüzünün tam adresi.'"
                          data-goster="access_type:!{{ $konsolTurleri }}">
                <x-form.input name="url" type="url" :value="$credential?->url" maxlength="500" mono :readonly="in_array('url', $lockedFields, true)" />
            </x-form.field>
        @else
            <x-form.masked name="url" label="Bağlantı adresi" />
        @endif
    </div>
</x-ui.panel>

<x-ui.panel title="Kimlik bilgileri" subtitle="Parola şifreli saklanır, hiçbir ekranda gösterilmez.">
    <div class="field-row">
        @if ($visibleFields['username'] ?? true)
            <x-form.field name="username" label="Kullanıcı adı">
                <x-form.input name="username" :value="$credential?->username" maxlength="190" mono
                              autocomplete="off" />
            </x-form.field>
        @else
            <x-form.masked name="username" label="Kullanıcı adı" />
        @endif

        @if ($visibleFields['password'] ?? true)
            <x-form.field name="password" label="Parola"
                          :hint="$credential?->has_password
                              ? 'Kayıtlı bir parola var. Boş bırakılırsa değişmez.'
                              : 'Kayıtlı parola yok.'">
                {{--
                    Düzenlemede alan boş gelir: mevcut parola sayfaya basılmaz.
                    Boş bırakılan alan parolayı silmez, dokunmaz
                    (bkz. CredentialWriter::update).
                --}}
                <x-form.input name="password" type="text" value="" maxlength="500" mono
                              autocomplete="new-password" spellcheck="false" />
            </x-form.field>
        @else
            {{--
                Parolayı GÖREMEYEN, parolayı DEĞİŞTİREMEZ de. Kutu açık
                bırakılsaydı görme yetkisi anlamsız olurdu: kişi parolayı
                kendi bildiği bir değerle değiştirip öğrenirdi.
            --}}
            <x-form.masked name="password" label="Parola"
                           hint="Parolayı görme yetkiniz yok; buradan değiştirilemez." />
        @endif
    </div>

    @if ($visibleFields['notes'] ?? true)
        <x-form.field name="notes" label="Not" hint="Şifreli saklanır.">
            <x-form.textarea name="notes" :value="$credential?->notes" rows="3" maxlength="5000" />
        </x-form.field>
    @else
        <x-form.masked name="notes" label="Not" />
    @endif

    <div class="field">
        <span class="field__label">Durum</span>
        <div class="row row--wrap">
            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $credential?->is_active ?? true))>
                <span>Etkin</span>
            </label>

            {{--
                KRİTİK İŞARETİ: gerekçe yalnızca işaretli kayıtlarda sorulur.
                Her kayıtta sorulduğunda insanlar kutuya "test" yazıp
                geçiyordu. Karar tek yerde: App\Support\GerekceKurali.
            --}}
            <label class="checkbox">
                <input type="checkbox" name="is_critical" value="1"
                       @checked(old('is_critical', $credential?->is_critical ?? false))>
                <span>Kritik — işlemlerde gerekçe sorulsun</span>
            </label>
        </div>
    </div>
</x-ui.panel>

<x-ui.panel title="Parola güncelleme" subtitle="Panel, parolayı uzaktaki makinede değiştirebilir."
            data-goster="{{ $guncellemeKosulu }}">
    <div class="field-row">
        <x-form.field name="rotation_driver" label="Sürücü"
                      hint="Boş bırakılırsa platforma göre seçilir."
                      data-rotation-surucu>
            <x-form.select name="rotation_driver" :options="$rotationDrivers"
                           :selected="$credential?->rotation_driver" />
        </x-form.field>

        {{--
          KUTU ELLE GÜNCELLEMEYİ ENGELLEMEZ:
          yalnızca kaydın TOPLU güncellemeye girip girmeyeceğini söyler.
          Elle turun kapısı ayrı: `rotate` yetkisi.
        --}}
        <div class="field">
            <span class="field__label">Toplu güncelleme</span>
            <label class="checkbox">
                <input type="checkbox" name="rotation_enabled" value="1"
                       @checked(old('rotation_enabled', $credential?->rotation_enabled ?? false))>
                <span>Otomatik güncellemeye açık</span>
            </label>
            <span class="field__hint">Kapalıyken de tek tek güncellenebilir.</span>
        </div>
    </div>

    {{--
      GÜNCELLEME HESABI KASADAN SEÇİLİR — AMA YALNIZCA MAKİNE HESABI İSTEYEN
      SÜRÜCÜDE (Plesk, mailcow, rspamd: panelin parolası makinede koşan bir
      komutla değişiyor, bağlantı makinenin SSH kimliğiyle kuruluyor).
      Hangi sürücülerin istediğini sürücüler söylüyor (NeedsMachineAccount);
      liste sunucudan geliyor. Başlangıç durumunu sunucu verir, JS yalnızca
      kutular değişince günceller (bkz. rotation-hesabi.js).
    --}}
    @php($surucuSecimi = old('rotation_driver', $credential?->rotation_driver ?? ''))
    @php($kasaSecimi = old('rotation_credential_id', $credential?->rotation_credential_id ?? ''))

    <x-form.field name="rotation_credential_id" label="Güncelleme hesabı — kasadan seç"
                  hint="Parola, makineye bu kayıtla bağlanılarak değiştirilir. Seçilmezse güncelleme düğmesi çıkmaz."
                  data-makine-hesabi="{{ json_encode($makineHesabiSurucileri) }}"
                  :class="in_array($surucuSecimi, $makineHesabiSurucileri, true) ? '' : 'is-hidden'">
        <x-form.select name="rotation_credential_id" :options="$rotationCredentialOptions"
                       :selected="$credential?->rotation_credential_id" />
    </x-form.field>

    {{--
      AYRI GÜNCELLEME HESABI yalnızca kutu işaretliyse görünür: kayıtların
      çoğu kendi hesabıyla döner, ayrı hesap istisnadır. Kutu kaldırılınca hesap adı temizlenir ve tur kaydın kendi
      hesabıyla bağlanır — gizli ama hâlâ geçerli bir hesap bırakılmaz.
    --}}
    <div class="{{ filled($kasaSecimi) ? 'is-hidden' : '' }}" data-elle-hesap>
        <label class="checkbox">
            <input type="checkbox" data-kosul="ayri_hesap"
                   @checked(filled(old('rotation_username', $credential?->rotation_username)))>
            <span>Ayrı bir güncelleme hesabıyla bağlan</span>
        </label>

        <div class="field-row kayit-formu__ic" data-goster="ayri_hesap:*" data-gizlenince-temizle>
            <x-form.field name="rotation_username" label="Güncelleme hesabı"
                          hint="Kasada karşılığı olmayan bir hesap için.">
                <x-form.input name="rotation_username" :value="$credential?->rotation_username" mono
                              maxlength="190" autocomplete="off" />
            </x-form.field>

            <x-form.field name="rotation_password" label="Güncelleme parolası"
                          :hint="$credential?->rotation_password ? 'Kayıtlı. Boş bırakılırsa değişmez.' : null">
                <x-form.input name="rotation_password" type="text" value="" mono
                              maxlength="500" autocomplete="new-password" spellcheck="false" />
            </x-form.field>
        </div>
    </div>
</x-ui.panel>

{{--
    PORTAL OTURUMU — yalnızca web ve panel kayıtlarında (portal alanları
    yalnızca onlarda dolu). Alanlar kutu işaretliyse görünür; kutu kaldırılınca
    temizlenir ve özellik kapanır (giriş adresi boşken özellik zaten kapalı).
--}}
<x-ui.panel title="Portal oturumu" data-goster="access_type:{{ $portalTurleri }}">
    <label class="checkbox">
        <input type="checkbox" data-kosul="portal_acik"
               @checked(filled(old('login_url', $credential?->login_url)))>
        <span>Panelden tek tıkla giriş — parola ekranda görünmez</span>
    </label>

    <div class="kayit-formu__ic" data-goster="portal_acik:*" data-gizlenince-temizle>
        <x-form.field name="login_url" label="Giriş formunun POST adresi"
                      :hint="in_array('login_url', $lockedFields, true) ? $kilitNotu : 'Giriş sayfası değil, formun action adresi. CSRF jetonu ya da iki adımlı doğrulama isteyen sitelerde çalışmaz.'">
            <x-form.input name="login_url" type="url" :value="$credential?->login_url" maxlength="500" mono :readonly="in_array('login_url', $lockedFields, true)" />
        </x-form.field>

        <div class="field-row">
            <x-form.field name="login_username_field" label="Kullanıcı alanının adı"
                          hint="Formdaki input name: “username” gibi.">
                <x-form.input name="login_username_field" :value="$credential?->login_username_field" maxlength="100" mono :readonly="in_array('login_username_field', $lockedFields, true)" />
            </x-form.field>

            <x-form.field name="login_password_field" label="Parola alanının adı"
                          hint="Formdaki input name: “password” gibi.">
                <x-form.input name="login_password_field" :value="$credential?->login_password_field" maxlength="100" mono :readonly="in_array('login_password_field', $lockedFields, true)" />
            </x-form.field>
        </div>
    </div>
</x-ui.panel>

{{--
    GELİŞMİŞ: nadiren değişen ayarlar katlanmış durur. Rol istisnalarından
    biri işaretliyse AÇIK gelir — bir kaydı bir role kapatan ayar, kapalı bir
    bölümün içinde gözden kaçmamalı. Sıra burada sorulmaz: listede
    sürükleyerek verilir (bkz. siralama.js); bölüm yalnız rol istisnasıdır.
--}}
@if ($canRestrict && $restrictableRoles->isNotEmpty())
<details class="kayit-formu__gelismis" @if ($gelismisAcik) open @endif>
    <summary>
        <span class="kayit-formu__gelismis-baslik">Gelişmiş ayarlar</span>
        <span class="text-sm text-muted">Rol istisnaları</span>
    </summary>

    <div class="kayit-formu__gelismis-govde">
        {{--
            KAYIT BAZINDA ROL KISITI VE EK İZİNLER — yalnızca YÖNETİCİ.

            Yetki normalde GRUP düzeyindedir. Bu kutular grubun içinden tek
            bir kaydı bir role kapatmak ya da grubun vermediğini tek bir
            kayıtta vermek içindir. Yönetici rolleri listede yok: yönetici
            bütün kontrolleri zaten geçiyor.
        --}}
            {{-- İşaretsiz kutu gönderilmez: bu işaret "tikin hepsi kaldırıldı"yı taşır. --}}
            <input type="hidden" name="rol_kutulari" value="1">

            {{--
                ROL BAŞINA TEK SATIR. "Göremeyecek" ve "görebilecek" iki ayrı kutu
                listesiyken aynı rol ikisinde birden işaretlenebiliyordu.
                Görünürlük tek seçim; ek izinler yanında.
                Konsol ve portal sütunları kaydın erişim türüne göre görünür.
            --}}
            <div class="field">
                <span class="field__label">Rol istisnaları</span>
                <table class="rol-istisna">
                    <thead>
                        <tr>
                            <th scope="col">Rol</th>
                            <th scope="col">Bu kayıt</th>
                            <th scope="col">Parola</th>
                            <th scope="col" data-goster="access_type:{{ $konsolTurleri }}">Konsol</th>
                            <th scope="col" data-goster="access_type:{{ $portalTurleri }}">Portal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($restrictableRoles as $rol)
                            <tr>
                                <th scope="row">{{ $rol->label }}</th>
                                <td>
                                    <select name="rol_gorunum[{{ $rol->uuid }}]" class="select"
                                            aria-label="{{ $rol->label }} bu kaydı">
                                        <option value="grup" @selected(old('rol_gorunum.'.$rol->uuid, $rolGorunum[$rol->uuid] ?? 'grup') === 'grup')>Gruba göre</option>
                                        <option value="gizli" @selected(old('rol_gorunum.'.$rol->uuid, $rolGorunum[$rol->uuid] ?? 'grup') === 'gizli')>Gizli</option>
                                        <option value="gorur" @selected(old('rol_gorunum.'.$rol->uuid, $rolGorunum[$rol->uuid] ?? 'grup') === 'gorur')>Görür</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="checkbox" name="granted_roles[]" value="{{ $rol->uuid }}"
                                           aria-label="{{ $rol->label }} parolayı görür"
                                           @checked(in_array($rol->uuid, old('granted_roles', $grantedRoleUuids), true))>
                                </td>
                                <td data-goster="access_type:{{ $konsolTurleri }}">
                                    <input type="checkbox" name="konsol_roles[]" value="{{ $rol->uuid }}"
                                           aria-label="{{ $rol->label }} konsol açar"
                                           @checked(in_array($rol->uuid, old('konsol_roles', $konsolRoleUuids), true))>
                                </td>
                                <td data-goster="access_type:{{ $portalTurleri }}">
                                    <input type="checkbox" name="portal_roles[]" value="{{ $rol->uuid }}"
                                           aria-label="{{ $rol->label }} portal açar"
                                           @checked(in_array($rol->uuid, old('portal_roles', $portalRoleUuids), true))>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <span class="field__hint">
                    <strong>Gruba göre</strong>: grubun yetkisi geçerli. <strong>Gizli</strong>: grubu görse de bu kaydı görmez.
                    <strong>Görür</strong>: grubu göremese de bu kaydı görür (adres, port, bağlantı, not).
                    Parola, konsol ve portal ek izinlerdir; parolayı göstermeden bağlanmak konsol ve portalla olur.
                </span>
            </div>
    </div>
</details>
@endif
</div>
