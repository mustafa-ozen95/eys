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
     * EYS'nin üzerinde çalıştığı sunucunun adresleri (virgülle). Bunlara
     * panelden kurulum ve yeniden başlatma yapılmaz (bkz.
     * OsUpdateTargets::panelSunucusu). APP_URL'nin çözüldüğü IP'ler zaten
     * sayılır; burası APP_URL'nin göstermediği adresler için.
     */
    'panel_hosts' => env('OS_UPDATE_PANEL_HOSTS', ''),

    /*
     * /boot'ta bundan az boş alan (MB) varsa ekran uyarır: yeni çekirdek
     * (vmlinuz + initramfs) sığmayabilir, kurulum hiç başlamadan düşer.
     */
    'boot_min_free_mb' => (int) env('OS_UPDATE_BOOT_MIN_FREE_MB', 150),

    /*
     * İşlerin kuyruğu. Kurulum dakikalar sürer; parola döndürmenin 300 sn
     * sınırlı işçisini meşgul etmesin diye ayrı işçide koşar
     * (docker/rootfs/etc/supervisor/supervisord.conf · isci-guncelleme).
     */
    'queue' => env('OS_UPDATE_QUEUE', 'updates'),

    /* Otomatik kontrolde iki makine arasına konan aralık (sn): hepsine aynı anda bağlanılmaz. */
    'stagger_seconds' => (int) env('OS_UPDATE_STAGGER', 20),

    /*
     * KONSOL AÇIKKEN KURULUM BEKLER.
     *
     * Makinede panelden açılmış bir konsol (SSH/RDP, herhangi bir kaydı)
     * varken biri makinenin başında çalışıyordur; o sırada kurulum
     * başlatılmaz. console_retry saniyede bir yeniden bakılır, console_wait
     * saniye sonra hâlâ açıksa kurulum atlanır (19.11.2026, NS1: konsolu
     * açık makine kurulum başladıktan 2 sn sonra vCenter'dan resetlendi).
     */
    'console_wait' => (int) env('OS_UPDATE_CONSOLE_WAIT', 7200),
    'console_retry' => (int) env('OS_UPDATE_CONSOLE_RETRY', 60),
];
