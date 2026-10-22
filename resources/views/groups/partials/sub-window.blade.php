{{--
    Alt grup ekleme / düzenleme penceresi.

    Tek pencere, iki iş: $pencere sunucuda kurulur (bkz. GroupController ·
    index). Ekleme `?pencere=ekle&grup=`, düzenleme `?pencere=duzenle&alt=`
    ile açılır; doğrulama hatasında istek aynı adrese döner ve pencere
    hatayla birlikte yeniden açılır.
--}}
@if ($pencere)
    <x-ui.modal id="sub-group-window" :title="$pencere['baslik']" open>
        <form method="POST" action="{{ $pencere['action'] }}" data-inline autocomplete="off">
            @csrf
            @if ($pencere['method'] !== 'POST')
                @method($pencere['method'])
            @endif

            <div class="modal__body">
                <x-ui.errors class="modal__alert" />

                <x-form.field name="alt_ad" label="Ad"
                              hint="{{ $pencere['grupAdi'] }} grubunun içinde listede başlık olarak görünür. Yetki taşımaz.">
                    <x-form.input name="alt_ad" :value="$pencere['ad']" required maxlength="120" autofocus />
                </x-form.field>
            </div>

            <x-ui.modal-actions :submit="$pencere['method'] === 'POST' ? 'Ekle' : 'Kaydet'" />
        </form>
    </x-ui.modal>
@endif
