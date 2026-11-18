<?php

declare(strict_types=1);

namespace App\Services\Export;

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
 * sayfa, başlık, birkaç renk ve düz metin hücreler. xlsx zaten birkaç XML'in
 * zip'i; o birkaç XML aşağıda.
 *
 * NEDEN CSV DEĞİL: Excel, CSV'yi açarken ayracı ve kodlamayı kendi tahmin
 * ediyor. Türkçe Windows'ta virgülle ayrılmış UTF-8 bir dosya tek sütuna
 * yığılıyor ve Ç/Ş/Ğ bozuluyor. xlsx'te tahmin yok.
 *
 * Hücreler "satır içi metin" (inlineStr) olarak yazılır: paylaşılan metin
 * tablosu kurmaya gerek kalmıyor ve her hücre kendi değerini taşıyor. Sayı
 * biçimi de YOK — parola "0012" ise sıfırları korunsun, tarih sanılmasın.
 * Formül de YOK: "=..." ile başlayan bir değer metin olarak kalır.
 *
 * GÖRÜNÜM (18.11.2026, "daha anlaşılır, renkli"): üstte başlık ve açıklama,
 * koyu zeminli sütun başlığı, donmuş başlık + süzgeç, içeriğe göre sütun
 * genişliği, aynı gruptaki satırlar aynı bantta (gruplar sırayla beyaz /
 * açık mavi), kategori hücreleri panelin rozet renginde, gizli sütun eş
 * aralıklı yazı ve sarı zeminle. Yazdırınca yatay, sayfa genişliğine sığar.
 */
final class Workbook
{
    /**
     * Rozet renkleri (BadgeVariant değerleri): [zemin, yazı]. Panelin
     * rozetleriyle aynı aile; Excel'de okunaklı kalsın diye açık zemin, koyu yazı.
     */
    public const RENKLER = [
        'green' => ['E3F4E8', '1E6B3A'],
        'red' => ['FDE7E7', 'A61B1B'],
        'yellow' => ['FFF4CC', '7A5B00'],
        'blue' => ['E3EDFB', '1D4E89'],
        'purple' => ['EEE7FA', '5B2C8F'],
        'cyan' => ['DFF4F7', '0E6470'],
        'orange' => ['FDEBDD', '9A4A0C'],
        'pink' => ['FCE7F1', '9D1C5B'],
        'gray' => ['EEF0F3', '4B5563'],
    ];

    /* cellXfs sırası (bicemler() ile birebir). */
    private const B_BASLIK = 1;

    private const B_ACIKLAMA = 2;

    private const B_UYARI = 3;

    private const B_SUTUN = 4;

    private const B_GOVDE = 5;   // +1: ikinci bant

    private const B_GIZLI = 7;   // +1: ikinci bant

    private const B_RENK = 9;    // RENKLER sırasıyla

    private const MIN_GENISLIK = 10;

    private const MAX_GENISLIK = 48;

    /**
     * Seçenekler (hepsi isteğe bağlı): baslik, aciklama (satırlar), uyari
     * (kırmızı satır); bant: değeri aynı kalan ardışık satırlar aynı zeminde
     * (grup sütunu); gizli: eş aralıklı yazı + sarı zemin; renkler: sütun =>
     * [değer => RENKLER anahtarı].
     *
     * @param  array<int, string>  $basliklar
     * @param  array<int, array<int, string|null>>  $satirlar
     * @param  array{baslik?: string, aciklama?: list<string>, uyari?: string, bant?: int, gizli?: list<int>, renkler?: array<int, array<string, string>>}  $secenek
     * @return string Dosyanın ham içeriği.
     */
    public static function uret(string $sayfaAdi, array $basliklar, array $satirlar, array $secenek = []): string
    {
        $yol = tempnam(sys_get_temp_dir(), 'eys-xlsx-');

        if ($yol === false) {
            throw new RuntimeException('Geçici dosya açılamadı.');
        }

        $zip = new ZipArchive;

        if ($zip->open($yol, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Çalışma kitabı yazılamadı.');
        }

        $ust = self::ustSatirlar($secenek);
        $baslikSatiri = count($ust) + 1;

        $zip->addFromString('[Content_Types].xml', self::icerikTurleri());
        $zip->addFromString('_rels/.rels', self::kokIliskileri());
        $zip->addFromString('xl/workbook.xml', self::kitap($sayfaAdi, $baslikSatiri, count($basliklar), count($satirlar)));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::kitapIliskileri());
        $zip->addFromString('xl/styles.xml', self::bicemler());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sayfa($basliklar, $satirlar, $ust, $secenek));
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

    /**
     * Süzgeç aralığı ve yazdırırken her sayfada tekrarlanan başlık satırı
     * "tanımlı ad" olarak burada durur; Excel ikisini de buradan okur.
     */
    private static function kitap(string $sayfaAdi, int $baslikSatiri, int $sutun, int $satir): string
    {
        $ad = self::sayfaAdi($sayfaAdi);
        $alinti = "'".str_replace("'", "''", $ad)."'";
        $adlar = '';

        if ($sutun > 0) {
            $son = self::sutunAdi($sutun - 1);
            $adlar = '<definedNames>'
                .'<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
                .self::kacis("{$alinti}!\$A\${$baslikSatiri}:\${$son}\$".($baslikSatiri + max($satir, 1))).'</definedName>'
                .'<definedName name="_xlnm.Print_Titles" localSheetId="0">'
                .self::kacis("{$alinti}!\${$baslikSatiri}:\${$baslikSatiri}").'</definedName>'
                .'</definedNames>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::kacis($ad).'" sheetId="1" r:id="rId1"/></sheets>'
            .$adlar
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

    /** Yazı tipleri, zeminler ve hücre biçemleri; sıra B_* sabitleriyle aynı. */
    private static function bicemler(): string
    {
        $font = fn (string $ic): string => '<font>'.$ic.'<name val="Calibri"/></font>';
        $zemin = fn (string $renk): string => '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$renk.'"/><bgColor indexed="64"/></patternFill></fill>';
        $ince = '<left style="thin"><color rgb="FFD0D7E2"/></left><right style="thin"><color rgb="FFD0D7E2"/></right>'
            .'<top style="thin"><color rgb="FFD0D7E2"/></top><bottom style="thin"><color rgb="FFD0D7E2"/></bottom>';
        $orta = '<alignment vertical="center"/>';

        $fontlar = [
            $font('<sz val="11"/>'),
            $font('<b/><sz val="16"/><color rgb="FF1F3A5F"/>'),
            $font('<sz val="10"/><color rgb="FF6B7280"/>'),
            $font('<b/><sz val="10"/><color rgb="FFB42318"/>'),
            $font('<b/><sz val="11"/><color rgb="FFFFFFFF"/>'),
            '<font><sz val="11"/><name val="Consolas"/></font>',
        ];
        $zeminler = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>',
            $zemin('1F3A5F'), $zemin('FFFFFF'), $zemin('EEF3FA'), $zemin('FFF8E1'), $zemin('FCEFC7')];
        $xf = fn (int $f, int $z, int $k, string $hiza = ''): string => '<xf numFmtId="0" fontId="'.$f.'" fillId="'.$z.'" borderId="'.$k.'" xfId="0"'
            .' applyFont="1" applyFill="1" applyBorder="1"'.($hiza === '' ? '/>' : ' applyAlignment="1">'.$hiza.'</xf>');

        $bicemler = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            $xf(1, 0, 0),
            $xf(2, 0, 0),
            $xf(3, 0, 0),
            $xf(4, 2, 1, '<alignment vertical="center" wrapText="1"/>'),
            $xf(0, 3, 1, $orta), $xf(0, 4, 1, $orta),
            $xf(5, 5, 1, $orta), $xf(5, 6, 1, $orta),
        ];

        foreach (self::RENKLER as [$arka, $yazi]) {
            $fontlar[] = $font('<b/><sz val="11"/><color rgb="FF'.$yazi.'"/>');
            $zeminler[] = $zemin($arka);
            $bicemler[] = $xf(count($fontlar) - 1, count($zeminler) - 1, 1, $orta);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="'.count($fontlar).'">'.implode('', $fontlar).'</fonts>'
            .'<fills count="'.count($zeminler).'">'.implode('', $zeminler).'</fills>'
            .'<borders count="2"><border/><border>'.$ince.'</border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($bicemler).'">'.implode('', $bicemler).'</cellXfs>'
            .'</styleSheet>';
    }

    /**
     * Başlık, açıklama ve uyarı satırları (verilenler) + bir boşluk.
     *
     * @param  array{baslik?: string, aciklama?: list<string>, uyari?: string}  $secenek
     * @return list<array{0: string, 1: int}>
     */
    private static function ustSatirlar(array $secenek): array
    {
        $ust = [];

        if (filled($secenek['baslik'] ?? null)) {
            $ust[] = [$secenek['baslik'], self::B_BASLIK];
        }

        foreach ($secenek['aciklama'] ?? [] as $satir) {
            $ust[] = [$satir, self::B_ACIKLAMA];
        }

        if (filled($secenek['uyari'] ?? null)) {
            $ust[] = [$secenek['uyari'], self::B_UYARI];
        }

        return $ust === [] ? [] : [...$ust, ['', 0]];
    }

    /**
     * @param  array<int, string>  $basliklar
     * @param  array<int, array<int, string|null>>  $satirlar
     * @param  list<array{0: string, 1: int}>  $ust
     * @param  array{bant?: int, gizli?: list<int>, renkler?: array<int, array<string, string>>}  $secenek
     */
    private static function sayfa(array $basliklar, array $satirlar, array $ust, array $secenek): string
    {
        $baslikSatiri = count($ust) + 1;
        $xml = '';
        $no = 1;

        foreach ($ust as [$metin, $bicem]) {
            $yukseklik = $bicem === self::B_BASLIK ? ' ht="26" customHeight="1"' : '';
            $xml .= $metin === '' ? '' : '<row r="'.$no.'"'.$yukseklik.'>'.self::hucre('A'.$no, $metin, $bicem).'</row>';
            $no++;
        }

        $xml .= self::satir($no++, $basliklar, fn (): int => self::B_SUTUN, ' ht="24" customHeight="1"');

        $bant = 0;
        $onceki = null;

        foreach ($satirlar as $sira => $satir) {
            $satir = array_values($satir);

            if (isset($secenek['bant'])) {
                $deger = $satir[$secenek['bant']] ?? null;
                $bant = $sira > 0 && $deger !== $onceki ? 1 - $bant : $bant;
                $onceki = $deger;
            } else {
                $bant = $sira % 2;
            }

            $xml .= self::satir($no++, $satir, fn (int $sutun, string $deger): int => self::govdeBicemi($secenek, $sutun, $deger, $bant));
        }

        $son = self::sutunAdi(max(count($basliklar), 1) - 1);
        $bolme = 'A'.($baslikSatiri + 1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            .'<pane ySplit="'.$baslikSatiri.'" topLeftCell="'.$bolme.'" activePane="bottomLeft" state="frozen"/>'
            .'<selection pane="bottomLeft" activeCell="'.$bolme.'" sqref="'.$bolme.'"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="18" customHeight="1"/>'
            .self::sutunGenislikleri($basliklar, $satirlar)
            .'<sheetData>'.$xml.'</sheetData>'
            .($basliklar === [] ? '' : '<autoFilter ref="A'.$baslikSatiri.':'.$son.($baslikSatiri + max(count($satirlar), 1)).'"/>')
            .'<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
            .'</worksheet>';
    }

    /** @param  array{gizli?: list<int>, renkler?: array<int, array<string, string>>}  $secenek */
    private static function govdeBicemi(array $secenek, int $sutun, string $deger, int $bant): int
    {
        $renk = $secenek['renkler'][$sutun][$deger] ?? null;

        if ($renk !== null && array_key_exists($renk, self::RENKLER)) {
            return self::B_RENK + (int) array_search($renk, array_keys(self::RENKLER), true);
        }

        return (in_array($sutun, $secenek['gizli'] ?? [], true) ? self::B_GIZLI : self::B_GOVDE) + $bant;
    }

    /**
     * Sütun genişliği içeriğe göre: en uzun değer (başlık dahil), alt ve üst
     * sınır arasında. Hepsi aynı genişlikteyken kısa sütunlar boşa yer
     * kaplıyor, uzun adresler kesiliyordu.
     *
     * @param  array<int, string>  $basliklar
     * @param  array<int, array<int, string|null>>  $satirlar
     */
    private static function sutunGenislikleri(array $basliklar, array $satirlar): string
    {
        if ($basliklar === []) {
            return '';
        }

        $xml = '<cols>';

        foreach (array_values($basliklar) as $sira => $baslik) {
            $en = mb_strlen($baslik) + 4;

            foreach ($satirlar as $satir) {
                $en = max($en, mb_strlen((string) (array_values($satir)[$sira] ?? '')) + 2);
            }

            $genislik = min(self::MAX_GENISLIK, max(self::MIN_GENISLIK, $en));
            $xml .= '<col min="'.($sira + 1).'" max="'.($sira + 1).'" width="'.$genislik.'" customWidth="1"/>';
        }

        return $xml.'</cols>';
    }

    /**
     * @param  array<int, string|null>  $hucreler
     * @param  callable(int, string): int  $bicem
     */
    private static function satir(int $no, array $hucreler, callable $bicem, string $ek = ''): string
    {
        $xml = '<row r="'.$no.'"'.$ek.'>';

        foreach (array_values($hucreler) as $sira => $deger) {
            $xml .= self::hucre(self::sutunAdi($sira).$no, (string) $deger, $bicem($sira, (string) $deger));
        }

        return $xml.'</row>';
    }

    private static function hucre(string $adres, string $deger, int $bicem): string
    {
        return '<c r="'.$adres.'"'.($bicem > 0 ? ' s="'.$bicem.'"' : '').' t="inlineStr"><is><t xml:space="preserve">'
            .self::kacis($deger).'</t></is></c>';
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
