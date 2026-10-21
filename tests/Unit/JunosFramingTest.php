<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Rotation\Junos\Framing\ChunkedFraming;
use App\Services\Rotation\Junos\Framing\EndOfMessageFraming;
use App\Services\Rotation\Junos\Framing\FramingNegotiator;
use App\Services\Rotation\Junos\NetconfException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeByteTransport;

/**
 * NETCONF çerçeveleme: Junos 12.3 (base:1.0, "]]>]]>") ve 24.4 (base:1.1,
 * uzunluk önekli parçalar). Ağ yok; bayt akışı bellekte.
 */
class JunosFramingTest extends TestCase
{
    private const CAP_10 = 'urn:ietf:params:netconf:base:1.0';

    private const CAP_11 = 'urn:ietf:params:netconf:base:1.1';

    #[Test]
    public function eom_mesaji_ayiriciya_kadar_okur_ve_sonrasini_dokunmadan_birakir(): void
    {
        $framing = new EndOfMessageFraming;
        $transport = new FakeByteTransport("<a/>\n]]>]]>\n<b/>\n]]>]]>\n");

        $this->assertSame('<a/>', $framing->read($transport));
        $this->assertSame('<b/>', $framing->read($transport), 'İkinci mesaj birincinin okumasında yutulmamalı.');
    }

    #[Test]
    public function eom_kodlama_ayirici_ekler_ve_cozme_onu_soyar(): void
    {
        $framing = new EndOfMessageFraming;

        $this->assertSame("<rpc/>\n]]>]]>\n", $framing->encode("  <rpc/>\n"));
        $this->assertSame('<rpc/>', $framing->decode($framing->encode('<rpc/>')));
    }

    #[Test]
    public function chunked_kodlama_uzunlugu_bayt_olarak_yazar(): void
    {
        $framing = new ChunkedFraming;

        /* "ş" iki bayttır: uzunluk karakter değil BAYT sayısıdır. */
        $this->assertSame("\n#9\n<a>ş</a>\n##\n", $framing->encode('<a>ş</a>'));
        $this->assertSame("\n#4\n<a/>\n##\n", $framing->encode('<a/>'));
    }

    #[Test]
    public function chunked_cok_parcali_mesaji_birlestirir(): void
    {
        $framing = new ChunkedFraming;
        $transport = new FakeByteTransport("\n#4\n<a>x\n#4\n</a>\n##\n");

        $this->assertSame('<a>x</a>', $framing->read($transport));
    }

    #[Test]
    public function chunked_parca_verisindeki_ayirici_mesaji_kesmez(): void
    {
        $framing = new ChunkedFraming;
        $veri = "<c>## Last changed\n##\nsatir</c>";
        $transport = new FakeByteTransport($framing->encode($veri).$framing->encode('<d/>'));

        $this->assertSame($veri, $framing->read($transport), 'Sınırı uzunluk belirler, "\n##\n" dizisi değil.');
        $this->assertSame('<d/>', $framing->read($transport));
    }

    #[Test]
    public function chunked_bozuk_basligi_reddeder(): void
    {
        $this->expectException(NetconfException::class);

        (new ChunkedFraming)->read(new FakeByteTransport("\nabc\n"));
    }

    #[Test]
    public function chunked_asiri_buyuk_parcayi_reddeder(): void
    {
        $this->expectException(NetconfException::class);

        (new ChunkedFraming)->read(new FakeByteTransport("\n#99999999\n"));
    }

    #[Test]
    public function chunked_bellekten_cozme_uzunluga_guvenir(): void
    {
        $framing = new ChunkedFraming;
        $veri = "x\n##\ny";

        $this->assertSame($veri, $framing->decode($framing->encode($veri)));
    }

    #[Test]
    public function muzakere_ortak_en_yuksek_surumu_secer(): void
    {
        $n = new FramingNegotiator;

        $this->assertInstanceOf(ChunkedFraming::class, $n->negotiate([self::CAP_10, self::CAP_11], [self::CAP_10, self::CAP_11]));
        $this->assertInstanceOf(EndOfMessageFraming::class, $n->negotiate([self::CAP_10], [self::CAP_10, self::CAP_11]), 'Junos 12.3 yalnızca 1.0 bildirir.');
        $this->assertInstanceOf(EndOfMessageFraming::class, $n->negotiate([self::CAP_10, self::CAP_11], [self::CAP_10]), 'İstemci 1.1 bildirmediyse 1.1 konuşulmaz.');
        $this->assertInstanceOf(EndOfMessageFraming::class, $n->negotiate([], [self::CAP_10, self::CAP_11]), 'Hiçbir base yeteneği yoksa en eskisi.');
    }
}
