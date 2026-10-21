# Yerel veri

Bu dizin **depoya girmez** (`.gitignore`). Yazılım depoda durur, veri burada.

## `kasa-yapisi.php`

Kasanın iskeleti: grup adları ve her grubun altındaki kayıt adları.
`CredentialGroupSeeder` varsa okur, yoksa hiçbir şey yapmaz.

**Parola, adres ve kullanıcı adı buraya da yazılmaz** — onlar panelden girilir
ve veritabanında şifreli durur.

```php
<?php

use App\Enums\AccessType;
use App\Enums\GroupKind;
use App\Enums\OperatingSystem;

return [
    'SUNUCU-01' => ['kind' => GroupKind::Server, 'items' => [
        //  kayıt adı  =>  [erişim türü, işletim sistemi, platform (isteğe bağlı)]
        'SSH' => [AccessType::Ssh, OperatingSystem::Linux, 'Ubuntu 24'],
        'Panel' => [AccessType::Panel, OperatingSystem::Application, 'Plesk'],
    ]],
];
```

Platform alanı parola döndürme sürücüsünü seçtirir; tahminle doldurulmaz.
Bilinmiyorsa üçüncü eleman yazılmaz.

Çalıştırmak için:

```bash
php artisan db:seed --class=CredentialGroupSeeder
```
