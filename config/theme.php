<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tema çerezinin alan adı
    |--------------------------------------------------------------------------
    |
    | Gece/gündüz seçimi çerezde tutulur. Merkez ve paneller AYRI KÖKLERDİR
    | (merkez.ornek.test, panel-a.ornek.test, panel-b.ornek.test) ve tarayıcı
    | depolaması kök başınadır: birinde yapılan seçim diğerinde görünmez.
    |
    | Üst alan adı verilirse (".ornek.test") hepsi aynı çerezi okur ve
    | seçim hangi ekranda yapılırsa yapılsın hepsinde geçerli olur.
    |
    | BOŞ BIRAKILIRSA çerez yalnızca bu konağa yazılır ve tema paylaşılmaz.
    | Paneller ayrı alan adlarında duruyorsa paylaşacak bir üst alan yoktur;
    | tahmin etmeye çalışmak tarayıcının çerezi sessizce reddetmesiyle
    | biterdi.
    |
    */

    'cookie_domain' => env('THEME_COOKIE_DOMAIN'),

];
