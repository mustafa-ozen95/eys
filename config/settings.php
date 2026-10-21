<?php

declare(strict_types=1);

/**
 * Ayar kataloğu.
 *
 * Anahtar = üzerine bindirilecek config yolu. Ekranın alan türü, doğrulama
 * kuralı ve tip çevrimi hep BURADAN okunur; üç yerde ayrı ayrı tanımlanıp
 * birbirinden ayrışmaz.
 *
 * BURAYA YALNIZCA GERÇEKTEN ETKİSİ OLAN AYARLAR KONUR. Ekranda duran ama
 * hiçbir şeyi değiştirmeyen bir kutu, olmayan bir denetimi varmış gibi
 * gösterir — ve bu, hiç kutu olmamasından kötüdür.
 */
/*
 * Saat dilimi listesi PHP'nin kendi IANA veritabanından gelir; elle yazılmış
 * bir liste güncel kalmazdı. config:cache bunu diziye çevirip dosyaya yazar.
 */
$saatDilimleri = array_combine(
    DateTimeZone::listIdentifiers(),
    DateTimeZone::listIdentifiers(),
);

return [

    'groups' => [

        'panel' => [
            'label' => 'Saat dilimi',
            'description' => 'Kayıtların, zamanlanmış işlerin ve ekranlardaki bütün tarih/saatlerin '
                .'hangi dilime göre gösterileceği. Denetim kaydı takibi için doğru olması şart: '
                .'yanlış dilim, olayların gerçekte ne zaman olduğunu saatlerce kaydırır.',
            'settings' => [

                'app.timezone' => [
                    'label' => 'Saat dilimi',
                    'hint' => 'Türkiye için Europe/Istanbul. Anlar UTC saklanır; bu ayar yalnızca gösterimi değiştirir.',
                    'type' => 'select',
                    'options' => $saatDilimleri,
                    'rules' => ['required', 'timezone'],
                ],

            ],
        ],

        'rotation' => [
            'label' => 'Parola güncelleme',
            'description' => 'Uzaktaki makinelerde parola değiştiren otomasyonun davranışı.',
            'settings' => [

                'rotation.enabled' => [
                    'label' => 'Otomatik güncelleme açık',
                    'hint' => 'Kapatıldığında hiçbir kaydın parolası güncellenemez; tek tek kayıt ayarlarına bakılmaz. Acil durum düğmesi.',
                    'type' => 'bool',
                    'rules' => ['boolean'],
                ],

                'rotation.password_length' => [
                    'label' => 'Üretilen parola uzunluğu',
                    'hint' => 'Kısaltmadan önce hedef sistemin sınırına bakın: bazı eski cihazlar sessizce kırpıyor ve kasadaki parola cihazdakinden farklı kalıyor.',
                    'type' => 'int',
                    'unit' => 'karakter',
                    'rules' => ['integer', 'min:12', 'max:128'],
                ],

                'rotation.password_alphabet' => [
                    'label' => 'Parola alfabesi',
                    'hint' => 'Karışabilecek karakterler (0/O, 1/l/I) bilinçli olarak dışarıda; bu parolalar bazen konsola elle yazılıyor.',
                    'type' => 'string',
                    'mono' => true,
                    'rules' => ['string', 'min:16', 'max:200'],
                ],

                'rotation.ssh_timeout' => [
                    'label' => 'SSH zaman aşımı',
                    'hint' => 'Bağlantı ve komut bekleme süresi.',
                    'type' => 'int',
                    'unit' => 'saniye',
                    'rules' => ['integer', 'min:5', 'max:300'],
                ],

                'rotation.mailcow_path' => [
                    'label' => 'mailcow dizini',
                    'hint' => 'mailcow-dockerized kurulum yolu. Yanlışsa docker compose projeyi bulamaz.',
                    'type' => 'string',
                    'mono' => true,
                    'rules' => ['string', 'regex:/^\/[A-Za-z0-9._\/-]+$/'],
                ],
            ],
        ],

        'security' => [
            'label' => 'Güvenlik',
            'description' => 'Oturum ve giriş davranışı.',
            'settings' => [

                'session.lifetime' => [
                    'label' => 'Oturum süresi',
                    'hint' => 'Hareketsiz kalan oturum bu süre sonunda düşer.',
                    'type' => 'int',
                    'unit' => 'dakika',
                    'rules' => ['integer', 'min:5', 'max:1440'],
                ],

                'auth.max_attempts' => [
                    'label' => 'Başarısız giriş sınırı',
                    'hint' => 'Bu sayıdan sonra aynı e-posta ve IP için giriş bir süre kilitlenir.',
                    'type' => 'int',
                    'unit' => 'deneme',
                    'rules' => ['integer', 'min:3', 'max:20'],
                ],
            ],
        ],

        /*
         * Merkez ile giriş.
         *
         * Açıldığında giriş ekranı merkezi ÖNE alır ve parola formunu
         * katlar — kapatmaz. Ayar tek başına yetmez: istemci bilgileri yoksa
         * akış zaten çalışmaz (bkz. CenterClient::acikMi). İkisi ayrı
         * durur ki bir ayar, olmayan bir yeteneği varmış gibi gösteremesin.
         */
        'kimlik' => [
            'label' => 'Kimlik Yönetim Merkezi',
            'description' => 'Kullanıcılar parolalarını buraya değil, Kimlik Yönetim Merkezi’ne yazar. '
                .'Panelin kendi parola girişi kapanmaz; merkez erişilemez olduğunda kasaya girmenin tek yolu odur.',
            'settings' => [

                'identity.acik' => [
                    'label' => 'Kimlik Yönetim Merkezi ile giriş',
                    'hint' => 'Açıkken giriş ekranı merkezi öne alır; parolayla giriş katlanmış hâlde durur '
                        .'ve merkez erişilemediğinde kullanılabilir.',
                    'type' => 'bool',
                    'rules' => ['boolean'],
                ],

                'identity.yonetici_devri' => [
                    'label' => 'Merkezdeki yönetici burada da yönetici olsun',
                    'hint' => 'KAPALI TUTULMASI ÖNERİLİR. Merkezin yöneticisi kimlikleri yönetir; buranın '
                        .'yöneticisi bütün sistemlerin parolalarını görür. Açıkken merkezde rol dağıtabilen '
                        .'biri, buradaki hiçbir yöneticinin haberi olmadan kendini burada da yönetici '
                        .'yapabilir. Kapalıyken yeni hesap varsayılan rolle doğar.',
                    'type' => 'bool',
                    'rules' => ['boolean'],
                ],
            ],
        ],
    ],
];
