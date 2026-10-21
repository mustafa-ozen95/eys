<?php

declare(strict_types=1);

namespace App\Support\Net;

use InvalidArgumentException;

/**
 * CIDR bloğunu temsil eden değişmez (immutable) değer nesnesi.
 *
 * Bu sınıf bilinçli olarak Laravel'den bağımsızdır: tüm alt ağ matematiği
 * burada toplanır ve tarayıcı tarafında tekrarlanmaz.
 *
 * IPv4 ve IPv6 EŞİT VATANDAŞTIR. 128 bit adres PHP tamsayısına sığmadığı için
 * aralık sorguları sayı yerine PAKETLENMİŞ BAYT (inet_pton) üzerinden yapılır:
 * baytlar sözlük sırasında karşılaştırıldığında ağ sırasıyla aynı sonucu verir.
 * Bu yüzden addressStart()/addressEnd() ikili dizge döndürür ve veritabanında
 * varbinary sütunlarda durur.
 *
 * Karşılaştırma yalnızca AYNI AİLE içinde anlamlıdır; bir IPv4 bloğu bir IPv6
 * bloğuyla zaten çakışamaz ve sorgular aileyi de süzer.
 */
final readonly class Cidr
{
    private const IPV4_BITS = 32;

    private const IPV6_BITS = 128;

    /**
     * Bölmede üretilebilecek en fazla alt ağ: 2^16 = 65536.
     *
     * IPv6'da sınırsız bölme istemek bir hatadır (bir /32'yi /64'lere bölmek
     * 2^32 alt ağ eder) ve bellek tükenene kadar çalışırdı.
     */
    private const MAX_SPLIT_BITS = 16;

    /**
     * @param  string  $network  Ağ adresi (ana makine bitleri sıfırlanmış)
     */
    private function __construct(
        public string $network,
        public int $prefixLength,
        public bool $isIpv6,
    ) {}

    public static function parse(string $value): self
    {
        $value = trim($value);

        if (! str_contains($value, '/')) {
            throw new InvalidArgumentException("Geçersiz CIDR (prefix eksik): {$value}");
        }

        [$address, $prefix] = explode('/', $value, 2);

        if ($prefix === '' || ! ctype_digit($prefix)) {
            throw new InvalidArgumentException("Geçersiz prefix uzunluğu: {$value}");
        }

        return self::make($address, (int) $prefix);
    }

    public static function make(string $address, int $prefixLength): self
    {
        $address = trim($address);
        $binary = @inet_pton($address);

        if ($binary === false) {
            throw new InvalidArgumentException("Geçersiz IP adresi: {$address}");
        }

        $isIpv6 = strlen($binary) === 16;
        $maxBits = $isIpv6 ? self::IPV6_BITS : self::IPV4_BITS;

        if ($prefixLength < 0 || $prefixLength > $maxBits) {
            throw new InvalidArgumentException("Prefix uzunluğu 0-{$maxBits} aralığında olmalı: /{$prefixLength}");
        }

        return new self(
            inet_ntop(self::maskBinary($binary, $prefixLength)),
            $prefixLength,
            $isIpv6,
        );
    }

    public static function tryParse(string $value): ?self
    {
        try {
            return self::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function maxPrefixLength(): int
    {
        return $this->isIpv6 ? self::IPV6_BITS : self::IPV4_BITS;
    }

    public function toString(): string
    {
        return "{$this->network}/{$this->prefixLength}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function netmask(): ?string
    {
        if ($this->isIpv6) {
            return null;
        }

        return long2ip($this->prefixLength === 0 ? 0 : -1 << (self::IPV4_BITS - $this->prefixLength));
    }

    /**
     * Bloktaki son adres. IPv4'te yayın (broadcast) adresidir.
     */
    public function lastAddress(): string
    {
        $binary = inet_pton($this->network);
        $hostBits = $this->maxPrefixLength() - $this->prefixLength;
        $bytes = str_split($binary);

        for ($bit = 0; $bit < $hostBits; $bit++) {
            $index = count($bytes) - 1 - intdiv($bit, 8);
            $bytes[$index] = chr(ord($bytes[$index]) | (1 << ($bit % 8)));
        }

        return inet_ntop(implode('', $bytes));
    }

    /**
     * Ana makinelere atanabilecek ilk adres.
     *
     * /31 (RFC 3021 noktadan noktaya) ve /32 (tekil ana makine) bloklarında
     * ağ/yayın adresi ayrılmaz; blok adresinin kendisi kullanılabilir.
     */
    public function firstUsable(): string
    {
        if ($this->isPointToPoint() || $this->isSingleHost()) {
            return $this->network;
        }

        /*
         * IPv6'da da ağ adresi atlanır: ::0 alt ağ yönlendirici anycast
         * adresidir (RFC 4291) ve bir arayüze verilmez.
         */
        return inet_ntop(self::increment(inet_pton($this->network)));
    }

    public function lastUsable(): string
    {
        if ($this->isPointToPoint() || $this->isSingleHost()) {
            return $this->lastAddress();
        }

        // IPv6'da yayın adresi yoktur; son adres kullanılabilir.
        return $this->isIpv6
            ? $this->lastAddress()
            : inet_ntop(self::decrement(inet_pton($this->lastAddress())));
    }

    /**
     * Kullanılabilir adres sayısı.
     *
     * IPv6'da bir /64 içinde 2^64 adres vardır: PHP tamsayısına sığmaz ve
     * zaten sayılmaz. Böyle bloklarda null döner ve çağıran taraf "sayılamaz"
     * demek zorundadır — sıfır demek yanlış olurdu, blok boş değil.
     */
    public function usableCount(): ?int
    {
        $hostBits = $this->maxPrefixLength() - $this->prefixLength;

        if ($hostBits > 62) {
            return null;
        }

        $total = 1 << $hostBits;

        if ($this->isSingleHost() || $this->isPointToPoint()) {
            return $total;
        }

        /*
         * IPv6'da yayın adresi yoktur; yalnızca ağ adresi (subnet-router
         * anycast) ayrılır. IPv4'te ağ + yayın olmak üzere iki adres düşer.
         */
        return $this->isIpv6 ? $total - 1 : $total - 2;
    }

    public function isSingleHost(): bool
    {
        return $this->prefixLength === $this->maxPrefixLength();
    }

    public function isPointToPoint(): bool
    {
        return ! $this->isIpv6 && $this->prefixLength === self::IPV4_BITS - 1;
    }

    /**
     * Aralık sorguları için PAKETLENMİŞ başlangıç adresi (inet_pton).
     *
     * Sayı değil bayt: 128 bit adres tamsayıya sığmaz. Baytlar sözlük
     * sırasında karşılaştırıldığında ağ sırasıyla aynı sonucu verir ve aynı
     * kod hem IPv4 hem IPv6 için çalışır.
     */
    public function addressStart(): string
    {
        return (string) inet_pton($this->network);
    }

    public function addressEnd(): string
    {
        return (string) inet_pton($this->lastAddress());
    }

    /**
     * Bir adresin bu bloğun içinde olup olmadığı — aile farkı da gözetilir.
     */
    public function containsAddress(string $address): bool
    {
        $binary = @inet_pton(trim($address));

        if ($binary === false || (strlen($binary) === 16) !== $this->isIpv6) {
            return false;
        }

        return $binary >= $this->addressStart() && $binary <= $this->addressEnd();
    }

    /**
     * Paketlenmiş adresi bir artırır (taşımalı toplama).
     */
    public static function increment(string $binary): string
    {
        for ($i = strlen($binary) - 1; $i >= 0; $i--) {
            $deger = ord($binary[$i]) + 1;
            $binary[$i] = chr($deger & 0xFF);

            if ($deger <= 0xFF) {
                break;
            }
        }

        return $binary;
    }

    /**
     * Paketlenmiş adresi bir azaltır (ödünçlü çıkarma).
     */
    public static function decrement(string $binary): string
    {
        for ($i = strlen($binary) - 1; $i >= 0; $i--) {
            $deger = ord($binary[$i]) - 1;
            $binary[$i] = chr($deger & 0xFF);

            if ($deger >= 0) {
                break;
            }
        }

        return $binary;
    }

    /**
     * Paketlenmiş adrese 2^exponent ekler.
     *
     * Alt ağ adımı IPv6'da PHP tamsayısına sığmaz (bir /52 adımı 2^76); bu
     * yüzden toplama sayıyla değil, doğru BİT KONUMUNA bir eklenerek yapılır.
     */
    public static function addPowerOfTwo(string $binary, int $exponent): string
    {
        $index = strlen($binary) - 1 - intdiv($exponent, 8);

        if ($index < 0) {
            // Adım adres uzayından büyük: taşma, olduğu gibi dön.
            return $binary;
        }

        $tasima = 1 << ($exponent % 8);

        for ($i = $index; $i >= 0 && $tasima > 0; $i--) {
            $toplam = ord($binary[$i]) + ($tasima & 0xFF);
            $binary[$i] = chr($toplam & 0xFF);
            $tasima = ($tasima >> 8) + ($toplam >> 8);
        }

        return $binary;
    }

    /**
     * Paketlenmiş adrese verilen kadar ekler. Adım sayısı PHP tamsayısına
     * sığdığı sürece IPv6'da da doğrudur; adres üretimi hep sınırlı sayıda
     * adım atar.
     */
    public static function add(string $binary, int $steps): string
    {
        $tasima = $steps;

        for ($i = strlen($binary) - 1; $i >= 0 && $tasima > 0; $i--) {
            $toplam = ord($binary[$i]) + ($tasima & 0xFF);
            $binary[$i] = chr($toplam & 0xFF);
            $tasima = ($tasima >> 8) + ($toplam >> 8);
        }

        return $binary;
    }

    public function contains(self|string $other): bool
    {
        $other = is_string($other) ? self::parse($other) : $other;

        if ($other->isIpv6 !== $this->isIpv6 || $other->prefixLength < $this->prefixLength) {
            return false;
        }

        return self::maskBinary(inet_pton($other->network), $this->prefixLength) === inet_pton($this->network);
    }

    public function overlaps(self|string $other): bool
    {
        $other = is_string($other) ? self::parse($other) : $other;

        return $this->contains($other) || $other->contains($this);
    }

    /**
     * Bloğu verilen uzunlukta eşit alt ağlara böler.
     *
     * @return array<int, self>
     */
    public function splitInto(int $prefixLength): array
    {
        if ($prefixLength <= $this->prefixLength || $prefixLength > $this->maxPrefixLength()) {
            throw new InvalidArgumentException(
                "Alt ağ prefix'i /{$this->prefixLength} ile /{$this->maxPrefixLength()} arasında ve üst bloktan büyük olmalı: /{$prefixLength}"
            );
        }

        /*
         * Bölme sayısı PHP tamsayısına sığmalı: bir /32'yi /128'lere bölmek
         * 2^96 alt ağ demektir ve istenmesi bir hatadır. Sınır cömert ama
         * gerçekçi tutulur.
         */
        $parcaBiti = $prefixLength - $this->prefixLength;

        if ($parcaBiti > self::MAX_SPLIT_BITS) {
            throw new InvalidArgumentException(
                "Bu bölme {$parcaBiti} bit, yani 2^{$parcaBiti} alt ağ üretir; en fazla "
                .self::MAX_SPLIT_BITS.' bit bölünebilir.'
            );
        }

        /*
         * Adım 2^(bit sayısı) kadardır ve IPv6'da bu sayı PHP tamsayısına
         * SIĞMAZ (bir /52 adımı 2^76). Bu yüzden toplama sayıyla değil, doğru
         * bit konumuna bir eklenerek yapılır.
         */
        $stepBits = $this->maxPrefixLength() - $prefixLength;
        $address = inet_pton($this->network);
        $end = inet_pton($this->lastAddress());

        $subnets = [];

        while ($address <= $end) {
            $subnets[] = self::make(inet_ntop($address), $prefixLength);

            $sonraki = self::addPowerOfTwo($address, $stepBits);

            // Taşma: son alt ağa ulaşıldı.
            if ($sonraki <= $address) {
                break;
            }

            $address = $sonraki;
        }

        return $subnets;
    }

    private static function maskBinary(string $binary, int $prefixLength): string
    {
        $bytes = str_split($binary);
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        for ($i = 0; $i < count($bytes); $i++) {
            if ($i < $fullBytes) {
                continue;
            }

            if ($i === $fullBytes && $remainingBits > 0) {
                $bytes[$i] = chr(ord($bytes[$i]) & (0xFF << (8 - $remainingBits)) & 0xFF);

                continue;
            }

            $bytes[$i] = "\0";
        }

        return implode('', $bytes);
    }
}
