<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos\Transport;

use App\Services\Rotation\Junos\NetconfException;

/**
 * NETCONF oturumunun altındaki taşıma katmanı: mesaj değil BAYT akışı.
 *
 * Mesaj sınırını çerçeveleme belirler (bkz. MessageFraming): base:1.0 bir
 * ayırıcıya kadar okur, base:1.1 uzunluğa güvenir. Bu yüzden iki ilkel var —
 * "şu diziye kadar" ve "tam şu kadar bayt" — ve ikisi AYNI tampondan beslenir;
 * ayrı tamponlar olsaydı biri diğerinin baytlarını yutardı.
 *
 * Ayrı bir arayüz olmasının sebebi test: testler asla gerçek bağlantı açmaz,
 * kaydedilmiş/sahte cihaz yanıtı veren bir taşıma kullanır.
 */
interface NetconfTransport
{
    /** @throws NetconfException Bağlantı ya da kimlik doğrulama başarısızsa */
    public function open(string $host, int $port, string $username, #[\SensitiveParameter] string $password, int $timeout): void;

    /** @throws NetconfException */
    public function write(string $data): void;

    /**
     * Verilen dize görünene kadar okur; dizeyi DE İÇEREN parçayı döndürür.
     *
     * @throws NetconfException
     */
    public function readUntil(string $terminator): string;

    /**
     * Tam istenen kadar bayt okur (base:1.1: parça verisi ayırıcıyı kendi
     * içinde taşıyabilir, sınırı yalnızca uzunluk belirler).
     *
     * @throws NetconfException
     */
    public function readExactly(int $bytes): string;

    /** Bağlantıyı kapatır; zaten kapalıysa sessizce döner. */
    public function close(): void;
}
