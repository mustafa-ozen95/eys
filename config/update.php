<?php

/*
|--------------------------------------------------------------------------
| Güncelleme kontrolü
|--------------------------------------------------------------------------
|
| Çalışan sürüm, deponun main dalındaki son başarılı imaj derlemesiyle
| karşılaştırılır (bkz. App\Services\Update\UpdateCheck).
| Yalnızca bakar; güncelleme sunucudan, onaylı yapılır.
|
*/

return [
    'repo' => env('UPDATE_REPO', 'mustafa-ozen95/eys'),

    /* İmajı derleyen iş akışı ve dal: "kurulabilir sürüm" bunun son başarılı turu. */
    'workflow' => 'image.yml',
    'branch' => 'main',

    /*
     * Depo açıkken jeton gerekmez, jetonsuz sorulur. Özel depo için salt
     * okunur, yalnız panel depolarını gören bir jeton (fine-grained; Contents
     * ve Actions: read). Erişilemezse kontrol "hata" der, panelin başka
     * hiçbir yeri etkilenmez.
     */
    'token' => env('UPDATE_TOKEN'),

    /*
     * İmaja derlemede yazılır: Dockerfile'da ARG APP_VERSION → ENV APP_VERSION.
     * Derlemeye commit vermeyen platformlarda (NineDeploy gibi) imaj onu
     * bağlamdaki .git'ten okuyup VERSION dosyasına yazar (docker/version-read.sh).
     */
    'version' => env('APP_VERSION') ?: (is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : null),
];
