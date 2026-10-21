<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
| Panelin dışarıya açtığı okuma arayüzü. Kimlik doğrulaması jetonla yapılır
| ("Authorization: Bearer ..."); oturum çerezi kabul edilmez.
|
| Jeton bir KİMLİK BİLGİSİDİR ve sahibi adına konuşur. İki sınırı vardır ve
| ikisi birden geçerlidir:
|
|   1. Jetonun kendi yetkileri (hangi kaynakta ne yapabilir).
|   2. SAHİBİNİN yetkileri. Jeton sahibini aşamaz — sahibinin yetkisi
|      daraltılınca jeton da daralır.
|
| Bu yüzden jeton üretmek yöneticiye özeldir: başkası adına jeton çıkarmak,
| o kişinin yetkisini kalıcı bir anahtara dönüştürmektir.
*/

return [

    /*
    | Jetonun görünen öneki. Sızan bir dizgenin ne olduğu ilk bakışta
    | anlaşılsın; sır değildir, kayıtta ve ekranda basılabilir.
    */
    'prefix' => env('API_TOKEN_PREFIX', 'eys'),

    /*
    | Jetonun sır kısmının bayt uzunluğu. 32 bayt = 256 bit; base62'ye
    | çevrildiğinde ~43 karakter.
    */
    'secret_bytes' => (int) env('API_TOKEN_BYTES', 32),

    /*
    | Yeni jetonun varsayılan ömrü (gün). Boş bırakılırsa süresiz olur;
    | süresiz jeton unutulur ve unutulan jeton sızar.
    */
    'default_lifetime_days' => (int) env('API_TOKEN_LIFETIME_DAYS', 365),

    /*
    | Dakikada izin verilen istek sayısı (jeton başına). Sınır jetondadır,
    | IP'de değil: aynı IP'den gelen iki jeton birbirini yavaşlatmamalı.
    */
    'rate_limit' => (int) env('API_RATE_LIMIT', 120),

    /*
    | Kimliği doğrulanamayan istekler için dakikadaki sınır (adres başına).
    | Geçerli jetonlu istekler de bu kapıdan geçtiği için jeton sınırından
    | belirgin biçimde yüksektir; amaç kimliksiz bir uca sınırsız istek
    | atılabilmesini engellemektir.
    */
    'guest_rate_limit' => (int) env('API_GUEST_RATE_LIMIT', 600),

    /*
    |----------------------------------------------------------------------
    | Uçların belgesi
    |----------------------------------------------------------------------
    | Anahtar rota adıdır ("api.v1." öneki olmadan). Belge buradan üretilir;
    | ekranda elle yazılmış ikinci bir liste YOKTUR — ayrışırdı.
    |
    | "ability" ucun istediği yetkidir ve "kaynak:aksiyon" biçimindedir.
    | Jeton bunu taşımak zorunda; SAHİBİ de aynı yetkiye sahip olmalı.
    |
    | Bir uç eklenip buraya yazılmazsa test kırılır (ApiDocumentationTest).
    */
    'endpoints' => [

        'credentials.index' => [
            'summary' => 'Kayıtlar',
            'ability' => 'credential_group:view',
            'description' => 'İsteği yapanın görebildiği kasa kayıtları. Sayfalıdır; '
                .'host ve kullanici ile süzülür. PAROLA DÖNMEZ.',
            'query' => ['host', 'kullanici', 'grup'],
        ],

        'credentials.show' => [
            'summary' => 'Tek kayıt',
            'ability' => 'credential_group:view',
            'description' => 'Adresteki kimlik kaydın uuid’sidir; sıralı birincil anahtar dışarı çıkmaz.',
        ],

        'credentials.secret' => [
            'summary' => 'Kaydın parolası',
            'ability' => 'credential_group:field.password',
            'description' => 'AYRI YETKİ ister: grubu görebilen biri parolayı göremeyebilir. '
                .'Her okuma denetim kaydına, jetonun adıyla birlikte yazılır.',
        ],
    ],
];
