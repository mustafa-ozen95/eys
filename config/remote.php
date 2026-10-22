<?php

declare(strict_types=1);
use App\Services\DeviceInterface\InterfaceSession;

/*
|--------------------------------------------------------------------------
| Konsol (uzak oturum) ayarları
|--------------------------------------------------------------------------
| DİKKAT: Buradaki port aralığı ve proxy yolları nginx yapılandırmasıyla
| birebir eşleşmek zorundadır; eşleşmezse oturumlar sessizce kırılır.
|
|   ttyd.port_range      → location ~ ^/ssh-proxy/(?<ttyd_port>\d+)
|   ttyd.proxy_path      → aynı location
|   guacamole.proxy_path → location ^~ /rdp-proxy/  →  127.0.0.1:8090/guacamole/
|
| İlgili dosya: docker/rootfs/etc/nginx/conf.d/eys.conf
|
| PORT ARALIĞI 7500-7699 (nginx deseni: 7[56][0-9][0-9]). ttyd süreçleri
| bulundukları ağın port uzayını paylaşır; aynı port uzayındaki başka bir
| uygulama bu aralığı kullanırsa bir oturum başkasının terminaline düşebilir.
*/

return [

    'session' => [
        'ttl' => 600,
        'cache_prefix' => 'remote_session:',
    ],

    /*
    | Cihaz arayüzlerinin vekillenmesi (ESXi Host Client, vCenter VAMI).
    |
    | Bu arayüzlerin oturumu DIŞARIDAN açılamıyor: ESXi jetonu istemcide
    | üretiyor, VAMI jetonu localStorage'da tutuyor. İkisi de arayüz panelin
    | KENDİ kökeninden verilince çalışıyor.
    |
    | Önek nginx yapılandırmasıyla BİREBİR aynı olmak zorundadır — ayrışırsa
    | adres 404 döner ve sebebi görünmez. Arayüzlerin kökten istediği sabit
    | yollar InterfaceType::kokYollari() içinde yazılı; nginx'teki konumlar da
    | onlarla eşleşir.
    */
    'interface' => [
        'prefix' => InterfaceSession::PREFIX,

        /*
        | Vekilin ALAN ADI.
        |
        | Boşsa arayüz panelin kendi alan adından verilir — çalışır ama aynı
        | kökendir: cihazın arayüzünden çıkan betik panele, oturumu açan kişi
        | adına istek atabilir (tarayıcı çerezi kendiliğinden ekler; betiğin
        | çerezi okumasına gerek yok). Cihaz sertifikasını doğrulamadığımız
        | için bunun tetiklenmesi cihazın ele geçirilmesini bile gerektirmez.
        |
        | Ayrı bir alan adı verilince o kapı kapanır: panel oturum çerezi
        | konak-özeldir (session.domain boş) ve oraya HİÇ gitmez. Kimlik
        | bunun yerine ANLIK bir devir jetonuyla taşınır ve devamı yalnızca
        | o kökende yaşayan bir çerezle sürer.
        |
        |     INTERFACE_DOMAIN=cihaz.eys.ornek.test
        |
        | Alan adı panelle aynı sunucuyu göstermeli (aynı ters vekil, aynı
        | konteyner); yalnızca KÖKEN ayrılıyor.
        */
        'domain' => env('INTERFACE_DOMAIN'),

        /*
         * OTURUM BAŞINA PORT.
         *
         * Arayüz kendi portunda açılır ve oturumu ADRES taşır. Ortak portta
         * oturumu çerez taşıyordu; çerez tarayıcıya ait olduğu için aynı anda
         * iki arayüz açan kullanıcının istekleri son açılana gidiyordu ve iki
         * Plesk'i ayıracak hiçbir ipucu kalmıyordu.
         *
         * Portun yol önekinden üstünlüğü şu: cihazların MUTLAK adresleri
         * (Plesk'in "/admin/domain/list" gibi) portun dışına ÇIKAMIYOR.
         *
         * Havuz boşsa arayüz ortak portta açılır — yer kalmadı diye hiç
         * açmamak, çakışma riskinden kötüdür. Aralık ters vekilde de açık
         * olmalı (docker/interface-proxy.Caddyfile ve docker-compose.yml; sunucuda
         * başka bir vekil karşılıyorsa onun kuralı).
         */
        'port_start' => (int) env('INTERFACE_PORT_START', 9450),
        'port_end' => (int) env('INTERFACE_PORT_END', 9479),

        /*
        | VEKİLLENEN TÜRLER.
        |
        | Bir arayüz buraya ancak UÇTAN UCA DOĞRULANDIKTAN sonra girer. Girdiği
        | anda o kayıtların eski yolu (formla giriş) kapanır — yarım bir vekil,
        | çalışan bir girişi bozmak demek olurdu.
        |
        | Plesk, iLO ve vSphere Client'ın sunucu tarafı girişi çalışıyor ama
        | arayüzleri vekil arkasında henüz açılmıyor (mutlak adresler, kendi
        | kökenine dönen yönlendirmeler). Doğrulanınca listeye eklenecekler.
        */
        'types' => array_filter(explode(',', (string) env('INTERFACE_TYPES', 'esxi,vami'))),

        /*
        | Devir jetonunun ömrü. Tek kullanımlıktır ve ilk açılışta yanar;
        | süre yalnızca tarayıcının yönlendirmeyi izlemesine yeter kadar.
        */
        'handoff_ttl' => 60,
    ],

    'ttyd' => [
        'binary' => env('TTYD_BINARY', '/usr/local/bin/ttyd'),
        'sshpass_binary' => env('SSHPASS_BINARY', '/usr/bin/sshpass'),
        'ssh_binary' => env('SSH_BINARY', '/usr/bin/ssh'),
        'port_range' => [7500, 7699],
        'proxy_path' => '/ssh-proxy',
        'session_path' => 'ssh-sessions',
        'orphan_log_ttl' => 3600,
    ],

    'guacamole' => [
        'url' => env('GUACAMOLE_URL', 'http://127.0.0.1:8090/guacamole'),
        'timeout' => (int) env('GUACAMOLE_TIMEOUT', 10),
        'secret' => env('GUACAMOLE_SECRET'),
        'username' => env('GUACAMOLE_USER', 'guacadmin'),
        'proxy_path' => '/rdp-proxy',
        'rdp' => [
            /*
             * GÜVENLİK KİPİ. Varsayılan "any": pazarlık FreeRDP'ye bırakılır.
             *
             * Windows Pro'ya doğrudan xfreerdp ile "any" de "nla" da
             * bağlanır. "Security negotiation failed" hatasının sebebi
             * güvenlik kipi değil, guacd'nin yükleyemediği FreeRDP kanal
             * eklentileri olabilir (bkz. docker/Dockerfile). Hata mesajı kipi
             * işaret ettiği için yanıltıcıdır.
             *
             * Ayarlanabilir bırakıldı: NLA'yı zorunlu kılan ya da yalnızca
             * eski RDP güvenliğini konuşan cihazlar için (nla, tls, rdp).
             */
            'security' => env('GUACAMOLE_RDP_SECURITY', 'any'),

            /*
             * SERTİFİKA DOĞRULANMAZ — ama SHA-1 İMZA YİNE DE GEÇMEZ.
             *
             * Bu ayar "sertifikaya güvenme, yine de bağlan" der ve cihazların
             * kendi ürettiği sertifikalar için doğru olan budur. Kabul
             * etmediği tek şey var ve kaynağı burası değil:
             *
             * Sertifikası SHA-1 imzalı Windows makineleri konsola açılmaz,
             * guacd şunu yazar:
             *
             *     RDP server closed/refused connection:
             *     SSL/TLS connection failed (untrusted/self-signed certificate?)
             *
             * Mesaj güveni işaret ediyor, sebep o değil. TLSv1.2 ve
             * ECDHE-RSA-AES256-SHA384 konuşulsa bile takılan şey sertifikanın
             * RSA-SHA1 ile imzalanmış olması. OpenSSL 3 SHA-1 imzayı yalnızca
             * güvenlik seviyesi 0'da kabul eder (seviye 1 de reddeder),
             * FreeRDP ise seviyeyi kendi ayarından sabitler ve Guacamole o
             * ayarı dışarı açmaz.
             *
             * YANİ PANELDEN ÇÖZÜLEMEZ. guacd'ye özel bir OpenSSL ayarı işe
             * yaramaz: FreeRDP seviyeyi kendisi yeniden kurar, config'i ezer.
             * Çözüm cihazda — sertifikayı SHA-256 ile yenilemek (certreq;
             * bkz. SUPPORT.md → "RDP açılmıyorsa").
             */
            'ignore_cert' => 'true',
            'resize_method' => 'display-update',
            'enable_drive' => 'true',
            'drive_path' => env('GUACAMOLE_DRIVE_PATH', '/tmp'),
            'recording_path' => env('GUACAMOLE_RECORDING_PATH', '/var/lib/guacamole/recordings'),
            'server_layout' => 'tr-tr-qwerty',
        ],

        'vnc' => [
            /*
             * TIGHT ÖNE ALINIR — pazarlığa bırakmak yetmiyor.
             *
             * Guacamole'un varsayılan listesinde tight YOK; sunucu destekliyor
             * olsa bile ZRLE'de anlaşılıyor. ZRLE bir kare içindeki düz renk
             * alanlarını sıkıştırır, fotoğrafı sıkıştıramaz — kamera görüntüsü
             * gibi ekranlarda iş yapan şey JPEG, onu da tight taşıyor.
             *
             * Sürekli değişen bir ekranda tight hem daha az veri taşır hem
             * daha akıcıdır: değişen küçük parçaları ayrı ayrı gönderir, ZRLE
             * ise büyük bloklar hâlinde; ZRLE ile ekran gözle görülür biçimde
             * takılır.
             *
             * Liste bir SIRA, dayatma değil: tight'ı konuşamayan bir sunucuyla
             * aşağıdakilerde anlaşılır.
             */
            'encodings' => env('GUACAMOLE_VNC_ENCODINGS', 'tight zrle ultra copyrect hextile zlib corre rre raw'),

            /*
             * RENK DERİNLİĞİ 24 bit. 16'ya düşürmek bandı kısar ama
             * fotoğrafik görüntüde bantlaşma yapar; sürekli görüntü gösteren
             * bir ekran için yanlış takas. Ayarlanabilir bırakıldı: dar hatlı bir uçtan
             * bağlanan biri için 16 doğru olabilir.
             */
            'color_depth' => env('GUACAMOLE_VNC_COLOR_DEPTH', '24'),

            /*
             * İMLEÇ YERELDE ÇİZİLİR: uzak imleci beklemek, her kıpırdanışta
             * ekranın o parçasını yeniden istemek demek.
             */
            'cursor' => env('GUACAMOLE_VNC_CURSOR', 'local'),

            /*
             * KAYIPSIZ ZORLANMAZ. "true" her kareyi PNG yapar; sürekli değişen
             * bir ekranda bu, tarayıcıya giden veriyi katlar. JPEG'e izin
             * verilince fotoğrafik bölgeler ucuzlar.
             */
            'force_lossless' => env('GUACAMOLE_VNC_FORCE_LOSSLESS', 'false'),
        ],
    ],

    /* Portu yazılmamış kayıtlar için protokolün alışılmış portu. */
    'default_ports' => [
        'ssh' => 22,
        'rdp' => 3389,
        'vnc' => 5900,
    ],
];
