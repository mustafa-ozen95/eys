<header class="topbar">
    <a href="{{ route('credentials.index') }}" class="topbar__mark"
       title="Erişim Yönetim Sistemi">{{ config('app.name') }}</a>

    <nav class="topbar__nav" aria-label="Bölümler">
        @foreach ($sections as $section)
            <a href="{{ $section['url'] }}"
               class="topbar__link"
               @if ($section['active']) aria-current="page" @endif>{{ $section['label'] }}</a>
        @endforeach
    </nav>


    <span class="topbar__spacer"></span>

    <button type="button" class="btn btn--ghost btn--icon btn--sm" data-theme-toggle aria-label="Temayı değiştir">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"
                  stroke-linecap="round" />
        </svg>
    </button>

    {{--
        Hesap menüsü.

        Çıkış tek başına üst menüde duruyordu: sık kullanılmayan ama
        yanlışlıkla basılması can sıkıcı bir düğme için fazla göze batan bir
        yer. Artık kimlik göstergesinin altında, hesap işleriyle birlikte.
    --}}
    <div class="user-menu" data-menu>
        <button type="button" class="user-menu__button" data-menu-open
                aria-haspopup="true" aria-expanded="false" aria-label="Hesap menüsü">
            <span class="topbar__avatar" aria-hidden="true">{{ $initials }}</span>
            <span class="topbar__name">{{ auth()->user()?->name }}</span>
            <svg class="user-menu__arrow" viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                <path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6"
                      stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <div class="user-menu__list" data-menu-list hidden>
            <a href="{{ route('account.profile') }}" class="user-menu__item">Hesabım</a>

            {{--
                Merkezin hızlı erişim sayfası, YENİ SEKMEDE: panelde yapılan
                iş yarıda kalmasın. Adres merkez
                girişinin kullandığı ayardan gelir; merkez kapalı kurulumda
                ölü bir bağlantı göstermemek için yalnızca açıkken çizilir.
            --}}
            @if (config('identity.acik'))
                <a href="{{ config('identity.url') }}" class="user-menu__item" target="_blank" rel="noopener">Hızlı Erişim</a>
            @endif

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="user-menu__item user-menu__item--logout">Çıkış</button>
            </form>
        </div>
    </div>
</header>
