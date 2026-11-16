<?php

declare(strict_types=1);

/**
 * Linux sistem güncellemeleri (apt / dnf).
 *
 * İki ayrı iş var ve ikisi ayrı ölçülür:
 *
 *   KONTROL — makineye bağlanır, paket listesini okur, HİÇBİR ŞEY KURMAZ.
 *             Zamanlanabilir (check_hours).
 *   KURULUM — makinede paket kurar. HİÇBİR ZAMAN kendiliğinden başlamaz;
 *             her kurulumu bir kişi, gerekçe yazarak başlatır.
 */
return [

    /*
     * Otomatik kontrol aralığı (saat). 0 = kapalı.
     *
     * Ayarlar → Sistem güncellemeleri'nden değiştirilir. Zamanlayıcı saatte
     * bir bakar ve son kontrolü bu süreden eski makineleri sıraya alır.
     */
    'check_hours' => (int) env('OS_UPDATE_CHECK_HOURS', 4),

    /*
     * Kontrol süresi (sn): depo listesinin yenilenmesi dahil.
     */
    'check_timeout' => (int) env('OS_UPDATE_CHECK_TIMEOUT', 180),

    /*
     * Kurulum süresi (sn).
     *
     * config/queue.php'deki retry_after'dan (960) KÜÇÜK kalmalı: aşarsa
     * kuyruk işi ölü sanıp ikinci kez verir. Süre dolarsa kurulum makinede
     * sürmeye devam eder (oturumdan bağımsız başlatılır, bkz. OsUpdateScripts),
     * panel yalnızca beklemeyi bırakır.
     */
    'apply_timeout' => (int) env('OS_UPDATE_APPLY_TIMEOUT', 840),

    /*
     * Süresi dolan kurulumdan sonra yeniden denetim (sn). Kurulum makinede
     * sürüyor olabilir; hemen denetlemek paket yöneticisinin kilidine takılır.
     */
    'recheck_after' => (int) env('OS_UPDATE_RECHECK_AFTER', 900),

    /*
     * İşlerin kuyruğu. Kurulum dakikalar sürer; parola döndürmenin 300 sn
     * sınırlı işçisini meşgul etmesin diye ayrı işçide koşar
     * (docker/rootfs/etc/supervisor/supervisord.conf · isci-guncelleme).
     */
    'queue' => env('OS_UPDATE_QUEUE', 'updates'),

    /* Otomatik kontrolde iki makine arasına konan aralık (sn): hepsine aynı anda bağlanılmaz. */
    'stagger_seconds' => (int) env('OS_UPDATE_STAGGER', 20),
];
