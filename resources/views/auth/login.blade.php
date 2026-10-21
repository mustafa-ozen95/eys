<x-layout.gate title="Giriş">
    {{-- Ad tek yerden gelir: config('app.name'). --}}
    <div class="gate__mark">{{ config('app.name') }}</div>
    {{-- Panelin açık adı da tek yerden: config('app.aciklama'). --}}
    <p class="gate__lead">{{ config('app.aciklama') }} — devam etmek için oturum açın.</p>

    <x-ui.flash />

    {{--
        MERKEZ AÇIKKEN PAROLA FORMU KATLANIR, KALDIRILMAZ.

        Kaldırılsaydı merkez erişilemez olduğu gün panele kimse giremezdi —
        yöneticisi dahil. İkinci yolu kapatmak, kurtarma yolunu kapatmak olurdu.
    --}}
    @if ($merkezAcik)
        <a href="{{ route('kimlik.giris') }}" class="btn btn--primary btn--block">
            {{ $merkezAd }} ile giriş
        </a>

        <div class="gate__ayrac"><span>veya</span></div>
    @endif

    <details class="gate__katlanir" @if (! $merkezAcik || $errors->any()) open @endif>
        <summary class="gate__katlanir-baslik">Parolayla giriş</summary>

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label class="field__label" for="email">E-posta</label>
                <input class="input input--mono" id="email" name="email" type="email"
                       value="{{ old('email') }}" required autocomplete="username" @if (! $merkezAcik || $errors->any()) autofocus @endif
                       aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
            </div>

            <div class="field">
                <label class="field__label" for="password">Parola</label>
                <input class="input" id="password" name="password" type="password"
                       required autocomplete="current-password"
                       aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
            </div>

            {{--
                "BU CİHAZDA AÇIK TUT" YOK.

                Böyle bir kutucuk kalıcı bir hatırlama çerezi bırakır: oturum
                hareketsizlikten düşse bile kullanıcı sessizce geri içeri
                alınır ve oturum süresi hiçbir şey ifade etmez. Açık unutulmuş
                bir oturum, panelin kapısını açık bırakmakla aynı şey.
            --}}
            <div class="form-actions">
                <button type="submit" @class(['btn', 'btn--block', 'btn--primary' => ! $merkezAcik])>Oturum aç</button>
            </div>
        </form>
        {{--
            Not, KATLI FORMUN İÇİNDE duruyor.

            Merkez açıkken kullanıcı zaten parola formunu görmüyor; "hesabı
            yönetici açar" cümlesi orada bağlamsız kalıyor ve ekranı
            kalabalıklaştırıyordu. Formu açan kişi ise tam olarak bunu
            merak eden kişi.
        --}}
        <p class="gate__foot">
            Hesap açma isteği için sistem yöneticisine başvurun.
        </p>
    </details>

</x-layout.gate>
