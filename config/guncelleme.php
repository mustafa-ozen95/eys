<?php

/*
|--------------------------------------------------------------------------
| Güncelleme kontrolü
|--------------------------------------------------------------------------
|
| Çalışan sürüm, deponun main dalındaki son başarılı imaj derlemesiyle
| karşılaştırılır (bkz. App\Services\Guncelleme\GuncellemeDenetimi).
| Yalnızca bakar; güncelleme sunucudan, onaylı yapılır.
|
*/

return [
    'depo' => env('GUNCELLEME_DEPO', 'mustafa-ozen95/eys'),

    /* İmajı derleyen iş akışı ve dal: "kurulabilir sürüm" bunun son başarılı turu. */
    'is' => 'imaj.yml',
    'dal' => 'main',

    /*
     * Depo açıkken jeton gerekmez, jetonsuz sorulur. Özel depo için salt
     * okunur, yalnız panel depolarını gören bir jeton (fine-grained; Contents
     * ve Actions: read). Erişilemezse kontrol "hata" der, panelin başka
     * hiçbir yeri etkilenmez.
     */
    'jeton' => env('GUNCELLEME_JETONU'),

    /*
     * İmaja derlemede yazılır: Dockerfile'da ARG SURUM → ENV UYGULAMA_SURUMU.
     * Derlemeye commit vermeyen platformlarda (NineDeploy gibi) imaj onu
     * bağlamdaki .git'ten okuyup SURUM dosyasına yazar (docker/surum-oku.sh).
     */
    'surum' => env('UYGULAMA_SURUMU') ?: (is_file(base_path('SURUM')) ? trim((string) file_get_contents(base_path('SURUM'))) : null),
];
