<?php

/*
|--------------------------------------------------------------------------
| Güncelleme kontrolü
|--------------------------------------------------------------------------
|
| Çalışan sürüm, deponun main dalındaki son başarılı imaj derlemesiyle
| karşılaştırılır (bkz. App\Services\Update\UpdateCheck).
| Yeni sürüm varsa yönetici "Şimdi güncelle" ile platformun deploy hook'unu
| tetikler (bkz. App\Services\Update\UpdateApply).
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
     * Platformun deploy hook adresi (NineDeploy: Service → Settings → Deploy
     * hook). Tanımsızsa "Şimdi güncelle" düğmesi çıkmaz. İçindeki jeton
     * deploy yetkisidir: gizli değişken olarak verilir, ekranda görünmez.
     */
    'hook' => env('UPDATE_HOOK_URL'),

    /*
     * İmaja derlemede yazılır: Dockerfile'da ARG APP_VERSION → ENV APP_VERSION.
     * Derlemeye commit vermeyen platformlarda (NineDeploy gibi) imaj onu
     * bağlamdaki .git'ten okuyup VERSION dosyasına yazar (docker/version-read.sh).
     */
    'version' => env('APP_VERSION') ?: (is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : null),
];
