<?php

declare(strict_types=1);

use App\Models\CredentialGroup;

/**
 * Yetki sözlüğü.
 *
 * Yetki bir GRUP üzerinde verilir; alanlar da birer yetkidir. "Parolayı
 * görebilir" ile "adresi görebilir" ayrı sorulardır ve ayrı satırlarda durur.
 *
 * Buraya bir aksiyon eklemek yetki ekranına da, rol şablonuna da onu ekler;
 * başka hiçbir yere dokunulmaz.
 */
return [

    'actions' => [
        'view' => 'Görüntüle',
        'create' => 'Ekle',
        'update' => 'Düzenle',
        'delete' => 'Sil',
        'field.host' => 'Adres',
        'field.port' => 'Port',
        'field.url' => 'Bağlantı adresi',
        /* Kullanıcı adını da bu yetki açar (bkz. 'fields'). */
        'field.password' => 'Kullanıcı adı ve parola',
        'field.notes' => 'Not',
        'rotate' => 'Parola güncelle',
        'export' => 'Parolaları dosyaya çıkar',
        'session' => 'Portal oturumu aç',
        'connect' => 'Konsol aç (SSH / uzak masaüstü)',
    ],

    /*
     * Kayıt alanı => onu açan aksiyon.
     *
     * Ekranlar bu eşlemeyi kullanır; hangi alanın hangi izne bağlı olduğu tek
     * yerde yazar. Yeni bir gizlenebilir alan eklendiğinde burası ve actions
     * güncellenir, ekranlar kendiliğinden uyar.
     */
    'fields' => [
        'host' => 'field.host',
        /* Uplink adresle aynı izne bağlı: ikisi de cihazın ağdaki yeri. */
        'uplink' => 'field.host',
        'port' => 'field.port',
        'url' => 'field.url',
        /*
         * KULLANICI ADI AYRI BİR YETKİ DEĞİL: parolayla aynı yetkiye bağlı.
         *
         * Kimlik iki parçadan oluşuyor ve yarısı elde olmayan biri o hesabı
         * kullanamıyor — parolayı göremeyene hesabın adını göstermenin bir
         * karşılığı yok. Panel zaten parolayı BİLMEDEN erişim sağlıyor
         * (arayüz vekili, konsol); orada da kullanıcı adı gerekmiyor, sunucu
         * kendisi kullanıyor.
         *
         * Ayrı yetki olarak duruyordu ve iki sorun çıkarıyordu: rol başına
         * yanlışlıkla "adı görsün, parolayı görmesin" kurulabiliyordu ve
         * yetki ekranı gereksiz bir kutu daha soruyordu.
         */
        'username' => 'field.password',
        'password' => 'field.password',
        'notes' => 'field.notes',
    ],

    /*
     * "view" olmadan hiçbir alan görünmez: kaydı göremeyen birine adresini
     * göstermenin anlamı yok. Çözümleyici bunu ayrıca kontrol eder.
     */
    'requires_view' => true,

    /*
     * Yerleşik rollerin hazır yetkileri.
     *
     * 'all'    : bütün gruplarda geçerli aksiyonlar
     * 'groups' : yalnızca adı geçen gruplarda EK aksiyonlar
     *
     * Grup adla yazılır; şablon uygulanırken kimliğe çevrilir ve adı
     * bulunamayan satır sessizce atılır (bkz. RoleTemplate).
     */
    'templates' => [
        'teknik' => [
            'credential_group' => [
                'all' => ['view', 'field.host', 'field.port', 'field.url', 'field.notes'],
            ],
        ],

        'network' => [
            'credential_group' => [
                'all' => ['view', 'field.host', 'field.port', 'field.url', 'field.notes'],
                /*
                 * Örnek: ağ ekibinin gruplarında parola ve düzenleme. Adlar
                 * örnektir; bulunamayan grup sessizce atlanır.
                 */
                'groups' => [
                    'Ağ Grubu A' => ['field.password', 'update'],
                    'Ağ Grubu B' => ['field.password', 'update'],
                ],
            ],
        ],
    ],

    'resources' => [
        'credential_group' => [
            'label' => 'Erişim grupları',
            'model' => CredentialGroup::class,
            'actions' => [
                'view',
                'field.host',
                'field.port',
                'field.url',

                /* Kullanıcı adı da bu yetkiye bağlı (bkz. 'fields'). */
                'field.password',
                'field.notes',
                'create',
                'update',
                'delete',

                /*
                 * Parola döndürmek CİHAZI DEĞİŞTİRİR. "Düzenle" yetkisi bunu
                 * kapsamaz: kasadaki kaydı değiştirmekle uzaktaki makinenin
                 * parolasını değiştirmek aynı şey değildir.
                 */
                'rotate',

                /*
                 * Parolayı DOSYAYA çıkarmak, onu panelin dışına taşımaktır.
                 * "Parolayı görüntüle" bunu kapsamaz: ekranda görülen parola
                 * orada kalır ve kapanır, dosyaya yazılan parola gittiği
                 * yerde bizim denetimimizde değildir.
                 */
                'export',

                /*
                 * Portal oturumu açmak parolayı DIŞARIYA gönderir. CLAUDE.md
                 * kuralı bunu açıkça ayırıyor: "uzak oturum açma" ayrı bir
                 * yetki ister, "görüntüle" onu kapsamaz.
                 */
                'session',

                /*
                 * Konsol, parolayı kullanıcıya GÖSTERMEDEN uzaktaki makineye
                 * oturum açar. Parolayı görme yetkisinden ayrıdır ve onu
                 * kapsamaz: göstermeden kullanmak da bir erişimdir.
                 */
                'connect',
            ],
        ],
    ],
];
