<?php

declare(strict_types=1);

namespace App\Services\Disa;

use RuntimeException;
use ZipArchive;

/**
 * Tek sayfalık bir Excel (.xlsx) dosyası üretir.
 *
 * Sınıf kendi başına durur: paylaşılan bir paket yok. Aynı sınıfı taşıyan
 * başka bir depo varsa birinde düzeltilen hata ötekinde de düzeltilmeli.
 *
 * NEDEN KÜTÜPHANE DEĞİL: panelin bağımlılık listesi birkaç satır ve öyle
 * kalması bilinçli (kurulumun basit kalması bu listeye bağlı). Tam teşekküllü
 * bir tablo kütüphanesi yüzlerce dosya getiriyor; burada gereken ise tek
 * sayfa, tek satır başlık ve düz metin hücreler. xlsx zaten birkaç XML'in
 * zip'i; o birkaç XML aşağıda.
 *
 * NEDEN CSV DEĞİL: Excel, CSV'yi açarken ayracı ve kodlamayı kendi tahmin
 * ediyor. Türkçe Windows'ta virgülle ayrılmış UTF-8 bir dosya tek sütuna
 * yığılıyor ve Ç/Ş/Ğ bozuluyor. xlsx'te tahmin yok.
 *
 * Hücreler "satır içi metin" (inlineStr) olarak yazılır: paylaşılan metin
 * tablosu kurmaya gerek kalmıyor ve her hücre kendi değerini taşıyor. Sayı
 * biçimi de YOK — parola "0012" ise sıfırları korunsun, tarih sanılmasın.
 */
final class CalismaKitabi
{
    /**
     * @param  array<int, string>  $basliklar
     * @param  array<int, array<int, string|null>>  $satirlar
     * @return string Dosyanın ham içeriği.
     */
    public static function uret(string $sayfaAdi, array $basliklar, array $satirlar): string
    {
        $yol = tempnam(sys_get_temp_dir(), 'eys-xlsx-');

        if ($yol === false) {
            throw new RuntimeException('Geçici dosya açılamadı.');
        }

        $zip = new ZipArchive;

        if ($zip->open($yol, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Çalışma kitabı yazılamadı.');
        }

        $zip->addFromString('[Content_Types].xml', self::icerikTurleri());
        $zip->addFromString('_rels/.rels', self::kokIliskileri());
        $zip->addFromString('xl/workbook.xml', self::kitap($sayfaAdi));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::kitapIliskileri());
        $zip->addFromString('xl/styles.xml', self::bicemler());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sayfa($basliklar, $satirlar));
        $zip->close();

        $icerik = (string) file_get_contents($yol);

        unlink($yol);

        return $icerik;
    }

    private static function icerikTurleri(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function kokIliskileri(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function kitap(string $sayfaAdi): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::kacis(self::sayfaAdi($sayfaAdi)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function kitapIliskileri(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** Tek biçem: kalın başlık satırı (s="1"). */
    private static function bicemler(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf xfId="0"/><xf fontId="1" applyFont="1" xfId="0"/></cellXfs>'
            .'</styleSheet>';
    }

    /**
     * @param  array<int, string>  $basliklar
     * @param  array<int, array<int, string|null>>  $satirlar
     */
    private static function sayfa(array $basliklar, array $satirlar): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .self::sutunGenislikleri(count($basliklar))
            .'<sheetData>'
            .self::satir(1, $basliklar, kalin: true);

        $no = 2;

        foreach ($satirlar as $satir) {
            $xml .= self::satir($no++, array_values($satir));
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** Sütunlar okunabilir genişlikte açılsın; hepsi aynı. */
    private static function sutunGenislikleri(int $adet): string
    {
        return $adet === 0 ? '' : '<cols><col min="1" max="'.$adet.'" width="26" customWidth="1"/></cols>';
    }

    /** @param  array<int, string|null>  $hucreler */
    private static function satir(int $no, array $hucreler, bool $kalin = false): string
    {
        $xml = '<row r="'.$no.'">';

        foreach ($hucreler as $sira => $deger) {
            $adres = self::sutunAdi($sira).$no;
            $bicem = $kalin ? ' s="1"' : '';

            $xml .= '<c r="'.$adres.'"'.$bicem.' t="inlineStr"><is><t xml:space="preserve">'
                .self::kacis((string) $deger).'</t></is></c>';
        }

        return $xml.'</row>';
    }

    /** 0 => A, 25 => Z, 26 => AA ... */
    private static function sutunAdi(int $sira): string
    {
        $ad = '';

        for ($n = $sira + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $ad = chr(65 + ($n - 1) % 26).$ad;
        }

        return $ad;
    }

    /**
     * Sayfa adı: Excel'in yasakladığı karakterler ve 31 karakter sınırı.
     *
     * Sınırı aşan ya da : \ / ? * [ ] içeren bir ad dosyayı AÇILMAZ yapıyor —
     * hata da vermiyor, Excel "onarılması gereken içerik" diyor.
     */
    private static function sayfaAdi(string $ad): string
    {
        $temiz = (string) preg_replace('#[:\\\\/?*\[\]]#', ' ', $ad);

        return mb_substr(trim($temiz) === '' ? 'Sayfa1' : trim($temiz), 0, 31);
    }

    /**
     * XML kaçışı + DENETİM KARAKTERLERİNİN ATILMASI.
     *
     * XML 1.0 bazı denetim karakterlerini hiç kabul etmiyor; kaçırılmış olsa
     * bile dosyayı bozuyorlar. Parolada böyle bir karakter bulunması olası
     * değil ama kasadaki değer bizim yazdığımız değil.
     */
    private static function kacis(string $deger): string
    {
        $temiz = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $deger);

        return htmlspecialchars($temiz, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
