<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers\Concerns;

use App\Models\Credential;
use App\Services\Rotation\Contracts\NeedsMachineAccount;
use App\Services\Rotation\MachineAccount;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * SSH taşıması — Linux ve Windows sürücülerinin ortak yanı.
 *
 * Bağlanmak, oturumu taşımak ve kimliği sınamak iki platformda da aynı; ayrım
 * yalnızca çalıştırılan komutlarda. Bu kod iki yere kopyalansaydı biri
 * düzeltilip diğeri eski kalırdı.
 */
trait ConnectsOverSsh
{
    private int $timeout = 15;

    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        $ssh = $this->connect($credential, $username, $password);

        return new RotationSession($ssh, $username, $password, static fn () => $ssh->disconnect());
    }

    public function verify(Credential $credential, string $username, string $password): bool
    {
        try {
            $this->connect($credential, $username, $password)->disconnect();

            return true;
        } catch (RotationException) {
            return false;
        }
    }

    private function connect(Credential $credential, string $username, string $password): SSH2
    {
        $ssh = new SSH2($credential->host, $this->connectPort($credential), $this->timeout);

        try {
            $ok = $ssh->login($username, $password);
        } catch (Throwable $e) {
            throw new RotationException('Bağlantı kurulamadı: '.$e->getMessage());
        }

        if (! $ok) {
            throw new RotationException("Kimlik doğrulaması reddedildi ({$username}).");
        }

        return $ssh;
    }

    /**
     * Komutu çalıştırır ve gerekirse standart girdiden veri gönderir.
     *
     * Parolalar HER ZAMAN buradan geçer, komut satırından değil: komut satırı
     * hedef makinede süreç listesinde görünür.
     *
     * Çıktı hata mesajına KOYULMAZ — bazı komutlar girdiyi yankılıyor ve mesaj
     * kullanıcıya gösteriliyor.
     */
    /**
     * Yükseltme isteminin METNİ.
     *
     * Boş istem (`-p ""`) kullanılamaz: parolanın ne zaman istendiğini
     * anlamanın tek yolu istemi GÖRMEK ve boş bir istem görülemez. Metin
     * makinenin kendi çıktısına benzemeyecek kadar özeldir; yoksa lecture
     * ya da açılış iletisi içindeki bir kelime istem sanılırdı.
     */
    private const SUDO_ISTEM = 'EYS-SUDO-PAROLA:';

    /**
     * Yetki yükseltme ön sözü.
     *
     * İki sürücü (Linux ve Plesk) aynı satırı kendi kopyasıyla taşıyordu;
     * biri düzeltildiğinde diğeri sessizce eski kalırdı.
     */
    protected function sudoPrelude(): string
    {
        return 'sudo -S -p '.escapeshellarg(self::SUDO_ISTEM).' -v';
    }

    private function execute(
        RotationSession $session,
        string $command,
        string $failure,
        string $stdin = '',
        string $prelude = '',
        string $preludeInput = '',
    ): string {
        /** @var SSH2 $ssh */
        $ssh = $session->handle;

        /*
         * Bağlantı KOPMUŞSA burada durulur.
         *
         * phpseclib kopmuş bir oturumda exec() çağrılınca kendi içinde
         * "Undefined array key 1" PHP uyarısı üretiyor (SSH2.php:2925,
         * exec kanalı artık yok). Kayıtta bu satırı gören insan hatayı
         * phpseclib'de arıyor; oysa neden basit: karşı taraf oturumu
         * düşürmüş.
         */
        if (! $ssh->isConnected()) {
            throw new RotationException($failure.': makineyle bağlantı koptu.');
        }

        /*
         * ÇIKIŞ KODU KOMUTUN KENDİSİNDEN OKUNUR, getExitStatus()'tan DEĞİL.
         *
         * phpseclib PTY istendiğinde exec()'i komut bitmeden döndürüyor
         * (SSH2.php: request_pty dalı hemen `return true`). O anda çıkış
         * kodu paketi henüz gelmemişse getExitStatus() null görüp `false`
         * döndürüyor — ve "false" ile "0" ayırt edilmediği için BAŞARISIZ
         * BİR KOMUT SESSİZCE BAŞARILI SAYILIYORDU.
         *
         * Komutun sonuna rastgele bir işaret basılır ve çıktı o işaret
         * görünene kadar okunur. Böylece hem komutun bittiğinden hem de
         * gerçek çıkış kodundan emin oluruz. İşaret hiç gelmezse komut
         * tamamlanmamıştır ve bu da hatadır.
         */
        $marker = 'NDSRC'.bin2hex(random_bytes(6));

        /*
         * ÖNCE ÇALIŞAN KOMUT ve GİRDİSİ AYRI AŞAMADA yazılır.
         *
         * AlmaLinux 9 / Ubuntu 26.04'te `sudo -S` parolayı
         * okuduktan sonra uçbirim girdi tamponunu TEMİZLİYOR (tcflush). İki
         * satır peş peşe yazıldığında ikincisi — komutun kendi girdisi —
         * siliniyor ve komut girdiyi sonsuza kadar bekliyor. Tur "komut
         * tamamlanmadı" diyerek kapanıyor, oysa bazı makinelerde parola
         * değişmiş oluyordu: kasa ile makine ayrışıyordu.
         *
         * Çözüm zamanlamaya değil, İŞARETE dayanır: yükseltme bitip HAZIR
         * satırı görülene kadar beklenir, komutun girdisi ondan sonra yazılır.
         */
        $gate = 'NDSGT'.bin2hex(random_bytes(4));

        $ssh->enablePTY();

        $ssh->exec($prelude === ''
            ? '{ '.$command.' ; }; printf "\n'.$marker.':%s\n" "$?"'
            : '{ '.$prelude.' ; }; printf "\n'.$gate.'\n"; { '.$command.' ; }; printf "\n'.$marker.':%s\n" "$?"');

        $onSoz = '';

        if ($prelude !== '') {
            if ($preludeInput !== '') {
                /*
                 * PAROLA, İSTEM GÖRÜLENE KADAR YAZILMAZ.
                 *
                 * AlmaLinux 9.4 / sudo 1.9.5'te sudo
                 * parolayı okumadan hemen önce uçbirim girdi tamponunu
                 * temizliyor (tcflush). Erken yazılan parola siliniyor, sudo
                 * yeniden soruyor ve elimizde yazacak bir şey kalmadığı için
                 * "Sorry, try again" ile reddediyor; ardından asıl komut
                 * girdisini sonsuza kadar bekliyor ve tur "komut
                 * tamamlanmadı" diyerek 60 saniyede düşüyor.
                 *
                 * Yarış YENİ AÇILAN kurtarma hesaplarında kaçınılmazdı: o
                 * hesap sudo'yu ilk kez kullandığı için sudo önce uzun
                 * "lecture" metnini basıyor, temizleme de ondan sonra
                 * geliyor. Aynı makinede daha önce sudo kullanmış bir hesapla
                 * sorun görünmez — bu yüzden bir kayıt uzun süre çalışıp
                 * tam da kurtarma hesabıyla düşebilir.
                 *
                 * Ya istem görülür ya da ön söz parola sormadan biter
                 * (NOPASSWD): ikisinden hangisi önce gelirse ona göre
                 * davranılır, yoksa parolasız makinede boşuna beklenirdi.
                 */
                $onSoz = (string) $ssh->read(
                    '/'.preg_quote(self::SUDO_ISTEM, '/').'|'.$gate.'/',
                    SSH2::READ_REGEX,
                );

                if (! str_contains($onSoz, $gate)) {
                    $ssh->write($preludeInput);
                    $onSoz .= (string) $ssh->read('/'.$gate.'/', SSH2::READ_REGEX);
                }
            } else {
                $onSoz = (string) $ssh->read('/'.$gate.'/', SSH2::READ_REGEX);
            }
        }

        if ($stdin !== '') {
            $ssh->write($stdin);
        }

        $ssh->write("\x04");

        $output = $onSoz.(string) $ssh->read('/'.$marker.':\d+/', SSH2::READ_REGEX);
        $ssh->disablePTY();

        if (preg_match('/'.$marker.':(\d+)/', $output, $m) !== 1) {
            throw new RotationException($failure.' Komut tamamlanmadı ya da zaman aşımına uğradı.');
        }

        if ((int) $m[1] !== 0) {
            /*
             * Çıktı hata mesajına KOYULMAZ: PTY açıkken yazdığımız her şey
             * geri yankılanıyor ve o yankıda parola var.
             */
            throw new RotationException($failure.' Hedef makine komutu reddetti (kod '.$m[1].').');
        }

        return $output;
    }

    /**
     * Bağlanılacak port.
     *
     * Çoğu kayıtta kaydın portu SSH portudur. Panel kayıtlarında değildir:
     * orada port panelin portudur (8443) ve SSH oraya bağlanmaz — bu yüzden
     * sürücü kendi portunu söyleyebilir (bkz. PleskPasswordDriver).
     *
     * MAKİNENİN SSH KİMLİĞİYLE bağlanan sürücülerde port da SEÇİLEN KAYITTAN
     * gelir: kimliği bir kayıttan alıp portu varsayımdan almak, o kaydın
     * yarısını kullanmak olurdu. SSH'ı 22 dışında bir portta (ör. 2222) olan
     * makinede tur, sürücünün varsayılanı 22'ye gidip "Connection refused"
     * ile düşerdi.
     */
    protected function connectPort(Credential $credential): int
    {
        $secilen = $this instanceof NeedsMachineAccount
            ? app(MachineAccount::class)->port($credential)
            : null;

        return $secilen ?? $credential->port ?? $this->defaultPort();
    }

    abstract protected function defaultPort(): int;
}
