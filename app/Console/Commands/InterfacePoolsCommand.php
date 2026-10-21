<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Credential;
use App\Services\DeviceAddress;
use App\Services\DeviceInterface\InterfaceType;
use App\Support\Host;
use Illuminate\Console\Command;

/**
 * Cihaz arayüzü vekili için nginx bağlantı havuzlarını üretir.
 *
 * NEDEN VAR: vekil, hedefi bir DEĞİŞKENDEN okuyor (`proxy_pass https://$...`).
 * nginx böyle bir hedefe bağlantı havuzu tutamıyor; her istek için yeni bir
 * TCP+TLS bağlantısı açıyor. Hızlı bir sunucuda bu fark edilmiyor ama zayıf
 * işlemcili bir BMC'de el sıkışması tek başına yarım saniyeyi buluyor.
 *
 * Havuzdan alınan bağlantı, her isteğe yeni bağlantı açmaktan kat kat
 * hızlıdır. Arayüz onlarca varlık istiyor; havuzsuz sayfanın açılması çok
 * uzar — iLO'nun çerçevesi "load" olayını o zamana kadar hiç vermediği için
 * ekran BOŞ kalır.
 *
 * nginx havuzu yalnızca ADI OLAN bir sunucu grubuna tutabiliyor, o yüzden
 * kasadaki her vekillenen cihaz için bir grup ve hedeften gruba bir eşleme
 * yazılır. Eşlemenin varsayılanı hedefin KENDİSİDİR: listede olmayan bir
 * cihaz eskisi gibi (havuzsuz, yavaş ama ÇALIŞIR) bağlanır. Yeni bir cihaz
 * eklendiğinde arayüzü açılmaz hâle gelmez, yalnızca bir sonraki üretime
 * kadar yavaş kalır.
 */
class InterfacePoolsCommand extends Command
{
    protected $signature = 'erisim:interface-pools
        {--yaz= : Üretilen yapılandırmanın yazılacağı dosya}
        {--resolv=/etc/resolv.conf : Çözücülerin okunacağı dosya}';

    protected $description = 'Vekillenen cihazlar için nginx bağlantı havuzu yapılandırmasını üretir';

    /**
     * Havuzda tutulacak boşta bağlantı sayısı.
     *
     * Tarayıcı bir kökene en çok altı bağlantı açıyor; havuz onun biraz
     * üstünde durmalı ki her istek hazır bir bağlantı bulsun. Daha büyüğü
     * BMC'lerde ters teper: iLO'nun eşzamanlı bağlantı bütçesi dar.
     */
    private const BOSTA = 8;

    public function handle(): int
    {
        $satirlar = [
            '# ÜRETİLEN DOSYA — elle düzenlenmez.',
            '# Kaynağı kasadır; üreten: php artisan erisim:interface-pools',
            '# Gerekçesi: app/Console/Commands/InterfacePoolsCommand.php',
            '',
        ];

        /*
         * ADLA YAZILMIŞ HEDEF HAVUZA ALINMAZ.
         *
         * nginx (1.22) bir sunucu grubundaki adı AÇILIŞTA çözüyor ve tek bir ad
         * çözülemezse HİÇ AÇILMIYOR: DNS'e ulaşamayan bir kurulumda
         * `host not found in upstream "srv-a.ornek.test:8443"`, nginx FATAL ve
         * panel 80'den de kapanır. Bir cihazın adı bütün paneli düşürmemeli;
         * DNS'in açılış anında yanıt vermemesi buna yeter.
         *
         * Adlı hedef eşlemenin varsayılanıyla (hedefin kendisi) bağlanır ve
         * İSTEK ANINDA çözülür (bkz. çözücü aşağıda). DNS o an yoksa yalnız o
         * cihazın arayüzü açılmaz. Bedeli havuzsuzluk, her istekte yeni TLS:
         * yavaş bir BMC adla kaydedildiyse adresiyle kaydetmek havuzu getirir.
         */
        $hedefler = [];
        $adlilar = [];

        foreach ($this->hedefler() as $ad => $hedef) {
            if (self::adresMi($hedef)) {
                $hedefler[$ad] = $hedef;
            } else {
                $adlilar[] = $hedef;
            }
        }

        foreach ($hedefler as $ad => $hedef) {
            $satirlar[] = 'upstream '.$ad.' {';
            $satirlar[] = '    server '.$hedef.';';
            $satirlar[] = '    keepalive '.self::BOSTA.';';
            $satirlar[] = '}';
            $satirlar[] = '';
        }

        foreach ($adlilar as $hedef) {
            $satirlar[] = '# Havuzsuz, istek anında çözülür: '.$hedef;
        }

        /*
         * ÇÖZÜCÜ: adlı hedef ve henüz havuza girmemiş yeni cihaz için.
         *
         * nginx değişkenden okunan bir adı ancak `resolver` ile çözer;
         * /etc/hosts'a da resolv.conf'a da kendisi bakmaz. Bu satır olmadan
         * havuzda olmayan adlı hedef hiç bağlanamaz. Kapsayıcının kendi
         * çözücüleri yazılır; Docker'da bu 127.0.0.11.
         */
        $cozuculer = $this->cozuculer();

        if ($cozuculer !== []) {
            $satirlar[] = 'resolver '.implode(' ', $cozuculer).' valid=30s ipv6=off;';
            $satirlar[] = 'resolver_timeout 5s;';
        }

        $satirlar[] = '';

        /*
         * Eşleme her hâlükârda yazılır, liste boş olsa bile: vekil
         * $arayuz_havuz değişkenini kullanıyor ve tanımsız bir değişkenle
         * nginx hiç açılmaz.
         */
        $satirlar[] = 'map $arayuz_hedef $arayuz_havuz {';
        $satirlar[] = '    default $arayuz_hedef;';

        foreach ($hedefler as $ad => $hedef) {
            $satirlar[] = '    "'.$hedef.'" '.$ad.';';
        }

        $satirlar[] = '}';

        $metin = implode("\n", $satirlar)."\n";
        $dosya = (string) $this->option('yaz');

        if ($dosya === '') {
            $this->getOutput()->write($metin);

            return self::SUCCESS;
        }

        /*
         * Değişmediyse DOKUNULMAZ: dosyanın değişip değişmediğine bakıp nginx'i
         * yalnızca gerektiğinde yenileyen bir çağıran var (bkz. eys-entrypoint).
         */
        if (is_file($dosya) && file_get_contents($dosya) === $metin) {
            $this->info('Havuz yapılandırması zaten güncel ('.count($hedefler).' cihaz).');

            return self::SUCCESS;
        }

        file_put_contents($dosya, $metin);
        $this->info('Havuz yapılandırması yazıldı: '.count($hedefler).' cihaz → '.$dosya);

        return self::SUCCESS;
    }

    /**
     * Vekillenen cihazların "ad => konak:port" listesi.
     *
     * Aynı makinede iki kayıt olabiliyor (iLO ile Plesk ayrı kayıt ama ayrı
     * makine; iki ESXi kaydı aynı konağa bakabilir). Anahtar hedefin kendisinden
     * türetilir, böylece aynı hedef iki kez yazılmaz — nginx aynı adı iki kez
     * görürse hiç açılmaz.
     *
     * @return array<string, string>
     */
    private function hedefler(): array
    {
        $liste = [];

        foreach (Credential::query()->cursor() as $credential) {
            $tur = InterfaceType::icin($credential);

            if ($tur === null) {
                continue;
            }

            $parca = parse_url(DeviceAddress::for($credential, $tur->varsayilanPort()));
            $konak = (string) ($parca['host'] ?? '');
            $port = (int) ($parca['port'] ?? $tur->varsayilanPort());

            /*
             * DEĞER NGINX METNİNE GİRER, o yüzden yalnızca gerçek bir adres
             * geçer. Kasadaki bir kayıt (eski, ya da doğrulamadan önce
             * yazılmış) `;`/`}` gibi karakter taşıyorsa o kayıt ATLANIR —
             * bütün yapılandırmayı düşürmek, kalan cihazların da havuzunu
             * (ve nginx'in açılmasını) bozardı. Atlanan cihaz havuzsuz ama
             * çalışır kalır (bkz. sınıf açıklaması: eşlemenin varsayılanı).
             */
            if (! Host::gecerliMi($konak) || $port < 1 || $port > 65535) {
                $this->getOutput()->getErrorStyle()->warning('Havuza alınmadı (geçersiz adres): kayıt #'.$credential->getKey());

                continue;
            }

            $hedef = $konak.':'.$port;
            $liste['cihaz_'.preg_replace('/[^a-z0-9]+/i', '_', $hedef)] = $hedef;
        }

        ksort($liste);

        return $liste;
    }

    /** Hedef bir IP adresine mi bakıyor ("10.0.0.1:443", "[fe80::1]:443")? */
    private static function adresMi(string $hedef): bool
    {
        $konak = trim(substr($hedef, 0, (int) strrpos($hedef, ':')), '[]');

        return filter_var($konak, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Kapsayıcının çözücüleri, nginx'in yazımıyla (IPv6 köşeli ayraçta).
     *
     * DEĞER NGINX METNİNE GİRER: yalnızca geçerli bir IP geçer. Dosya
     * okunamazsa ya da adres yoksa liste boş döner; çözücü satırı yazılmaz,
     * adlı hedef bağlanamaz ama nginx açılır.
     *
     * @return list<string>
     */
    private function cozuculer(): array
    {
        $dosya = (string) $this->option('resolv');
        $metin = is_readable($dosya) ? (string) file_get_contents($dosya) : '';

        preg_match_all('/^\s*nameserver\s+(\S+)/m', $metin, $eslesme);

        $liste = [];

        foreach ($eslesme[1] as $adres) {
            if (filter_var($adres, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $liste[] = $adres;
            } elseif (filter_var($adres, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $liste[] = '['.$adres.']';
            }
        }

        return array_values(array_unique($liste));
    }
}
