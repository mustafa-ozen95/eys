<?php

declare(strict_types=1);

/**
 * Etkinlik kaydı ayarları.
 *
 * Zincir anahtarı ayrı bir env değeri olabilir; verilmezse uygulama
 * anahtarından TÜRETİLİR. Türetme, anahtarı olmayan bir kurulumda zincirin
 * hiç çalışmamasından iyidir — ama .env'e erişebilen biri zinciri yeniden
 * hesaplayabilir. Tehdit modeli "veritabanına yazabilen ama dosya sistemine
 * erişemeyen" kişidir.
 */
return [

    /*
     * Türetmedeki bağlam dizesi değiştirilirse mevcut zincir doğrulanamaz;
     * bu yüzden sabittir.
     */
    'chain_key' => env('ACTIVITY_CHAIN_KEY')
        ?: hash_hmac('sha256', 'dgn-erisim-activity-chain', (string) env('APP_KEY')),

    /* Son doğrulama sonucunun önbellek anahtarı. */
    'verification_key' => 'activity:verification',

    /* Budama sonrası zincirin yeni başlangıç noktası. */
    'chain_anchor_key' => 'activity:chain-anchor',
];
