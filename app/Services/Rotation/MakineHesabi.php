<?php

declare(strict_types=1);

namespace App\Services\Rotation;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Services\Rotation\Contracts\NeedsMachineAccount;

/**
 * MAKİNENİN SSH KİMLİĞİ — kasadan SEÇİLİR, tahmin edilmez.
 *
 * Kimi sürücüde DEĞİŞTİREN ile DEĞİŞEN hesap farklıdır: Plesk'te panelin
 * `admin` parolasını değiştiren şey makinenin SSH kimliğidir. Bu kimlik kayda
 * ayrıca yazılıyordu ("güncelleme hesabı" alanı) — oysa aynı makinenin SSH
 * kaydı kasada çoğu zaman zaten duruyor.
 *
 * Kimlik İKİ YERDEN gelir:
 *
 *   1. Kayda BAĞLANMIŞ kimlik (`rotation_credential_id`) — ekrandaki liste
 *      bunu seçtirir. Kopya değil bağ olduğu için hep güncel.
 *   2. Kaydın kendi "güncelleme hesabı" alanı — elle yazılmış kimlik.
 *
 * AYNI ADRESLİ SSH KAYDI ARTIK KENDİLİĞİNDEN KULLANILMIYOR, yalnızca ÖNERİ
 * olarak gösteriliyor (bkz. oneri, eksikSebep). Önce üçüncü bir basamak
 * olarak sessizce devreye giriyordu; artık hesap seçilmemişse "parolayı
 * güncelle" seçeneği gizlenir. Uzaktaki bir makinede
 * parola değiştiren bir işin HANGİ KİMLİKLE bağlanacağı, ekrana bakan kişinin
 * göremediği bir kuraldan çıkmamalı — seçilir, yazılır, görünür.
 *
 * ADRES EŞLEŞMESİ TAM OLMALI: "aynı makine" kararını yaklaşık vermek, başka
 * bir sunucunun kimliğini önermek olurdu.
 */
class MakineHesabi
{
    /**
     * Bu kayıt için kullanılacak SSH kimliği.
     *
     * @return array{0: string, 1: string}|null [kullanıcı, parola]
     */
    public function bul(Credential $kayit): ?array
    {
        /*
         * SEÇİLMİŞ KAYIT EN ÖNDE. Parola her turda o kayıttan okunur; SSH
         * parolası döndüğünde burada eskiyen bir kopya kalmaz.
         */
        $bagli = $this->bagliKayit($kayit);

        if ($bagli !== null && filled($bagli->username)) {
            return [(string) $bagli->username, (string) $bagli->password];
        }

        if (filled($kayit->rotation_username)) {
            return [(string) $kayit->rotation_username, (string) $kayit->rotation_password];
        }

        return null;
    }

    /**
     * Kayda bağlanmış kimlik kaydı — KİŞİSEL KAYIT HARİÇ.
     *
     * Kişisel kayıt hiçbir koşulda başkasının turunun kimliği olamaz; yazma
     * anında seçtirilmiyor (bkz. CredentialRequest::bagliHesapKurali) ama
     * bağ sonradan kişisele dönüşmüş (kayıt kişisel gruba alınmış) ya da
     * doğrudan yazılmış olabilir. Son karar noktası burası: kimlik burada
     * okunur.
     */
    private function bagliKayit(Credential $kayit): ?Credential
    {
        $bagli = $kayit->rotationCredential;

        return $bagli !== null && ! $bagli->kisiselMi() ? $bagli : null;
    }

    /**
     * Seçilen SSH kaydının PORTU.
     *
     * Kimlikle birlikte portu da o kayıttan almak şart: makinenin SSH'ı 22'de
     * olmak zorunda değil. Örnek: Plesk kaydının portu panelin portu (8443),
     * sürücünün varsayılanı 22, makinenin SSH'ı ise 2222'de olursa port
     * kayıttan alınmadığında tur "Connection refused" diyerek düşer.
     *
     * Elle yazılan hesapta port bilgisi yok; orada sürücünün varsayılanı
     * kalır.
     */
    public function port(Credential $kayit): ?int
    {
        $bagli = $this->bagliKayit($kayit);

        return $bagli !== null && filled($bagli->username) ? $bagli->port : null;
    }

    /** Kimliğin nereden geldiği — ekranda ve kayıtta yazması için. */
    public function kaynak(Credential $kayit): ?string
    {
        $bagli = $this->bagliKayit($kayit);

        if ($bagli !== null && filled($bagli->username)) {
            return 'seçilen kayıt: #'.$bagli->id.' '.$bagli->name;
        }

        return filled($kayit->rotation_username) ? 'kaydın güncelleme hesabı' : null;
    }

    /**
     * Sürücünün "bu kayıt döndürülemez" gerekçesi — üç sürücüde de aynı.
     *
     * Metin üç sürücüde ayrı ayrı yazılıydı ve birbirinden ayrışmaya açıktı;
     * dahası ikisi ayrıca `rotation_password` alanının dolu olmasını istiyordu
     * — kasadan kayıt seçildiğinde o alan BOŞ kalır ve tur, kimlik aslında
     * hazırken "parola girilmeli" diye kapanırdı.
     */
    public function eksikSebep(Credential $kayit): ?string
    {
        /*
         * SEÇİLEN HESAP SÜRÜCÜYE UYMALI.
         *
         * Liste artık makinenin yönetim hesabı olabilecek BÜTÜN türleri
         * gösteriyor (SSH ve uzak masaüstü); daraltmak kısır döngü
         * üretiyordu. Bedeli şu: uyumsuz bir eşleşme kurulabiliyor ve panel
         * onu makinenin karşısında öğreniyordu.
         *
         * Örnek: sürücü `plesk` (Linux, SSH), seçilen hesap ise RDP kaydı.
         * Tur, RDP portuna (3390) SSH kurmaya çalışıp "Error reading SSH
         * identification string" ile düşer — kullanıcının okuyup
         * anlayabileceği bir cümle değil. Uyumsuzluk
         * artık makineye gitmeden, ne yapılacağını söyleyerek durur.
         */
        $bagli = $this->bagliKayit($kayit);
        $turler = $this->erisimTurleri($kayit);

        if ($bagli !== null && ! in_array($bagli->access_type->value, $turler, true)) {
            return 'Seçilen güncelleme hesabı bu sürücüye uymuyor: #'.$bagli->getKey().' · '.$bagli->name
                .' bir '.$bagli->access_type->label().' kaydı, bu sürücü ise '
                .$this->turAdlari($turler).' kaydı istiyor. Sürücüyü değiştirin ya da uygun bir kayıt seçin.';
        }

        $kimlik = $this->bul($kayit);

        if ($kimlik === null) {
            $oneri = $this->oneri($kayit);

            return 'Güncelleme hesabı seçilmemiş: bu sürücü parolayı makineye SSH ile '
                .'bağlanıp değiştiriyor, kaydın penceresinden kasadan bir SSH kaydı seçin'
                .($oneri === null
                    ? ' ya da hesabı elle yazın.'
                    : ' (aynı adreste #'.$oneri->getKey().' · '.$oneri->name.' duruyor).');
        }

        return blank($kimlik[1])
            ? 'Güncelleme hesabının parolası boş; SSH ile bağlanılamaz.'
            : null;
    }

    /**
     * Aynı adresteki yönetim kaydı — SEÇİLMEDİĞİNDE ÖNERİLİR, kullanılmaz.
     *
     * HANGİ TÜR ARANACAĞINI SÜRÜCÜ SÖYLER: Linux'ta makinenin SSH kaydı,
     * Windows'ta RDP/uzak yönetim kaydı (bkz. NeedsMachineAccount). Tür burada
     * sabit yazılsaydı, Windows'ta koşan bir Plesk için "SSH kaydı seçin"
     * denirdi — o makinede SSH yokken.
     *
     * PASİF KAYIT ÖNERİLMEZ: pasife alınmış bir kimlik "artık bunu
     * kullanmayın" demektir; öneri onu görmezden gelseydi o karar hiçbir şey
     * ifade etmezdi.
     *
     * Birden çok kayıt varsa en KRİTİK olan, sonra en yeni güncellenen
     * önerilir: kasada bir makinenin hem `root` hem sınırlı bir hesabı
     * duruyorsa, tur için işaretlenmiş olan kritik kayıttır.
     */
    public function oneri(Credential $kayit): ?Credential
    {
        /*
         * ÖNERİ DE YALNIZCA BAKANIN GÖREBİLDİĞİ KAYITLARDAN gelir: gerekçe
         * metni kullanıcıya gösteriliyor ve kaydın numarasını ile adını
         * taşıyor; görmediği bir kaydın adını bu yoldan öğrenmemeli.
         * Kullanıcı yoksa (kuyruk) öneri de yok: metni kimse okumuyor.
         */
        $bakan = auth()->user();

        if (blank($kayit->host) || $bakan === null) {
            return null;
        }

        return Credential::query()
            ->visibleTo($bakan)
            ->otomasyon()
            ->where('id', '!=', $kayit->id)
            ->whereIn('access_type', $this->erisimTurleri($kayit))
            ->where('is_active', true)
            ->whereRaw('LOWER(TRIM(host)) = ?', [mb_strtolower(trim((string) $kayit->host))])
            ->whereNotNull('username')
            ->orderByDesc('is_critical')
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * Tür adlarını insanca yazar: "SSH ya da uzak masaüstü".
     *
     * @param  list<string>  $turler
     */
    private function turAdlari(array $turler): string
    {
        $adlar = array_map(
            fn (string $tur): string => AccessType::from($tur)->label(),
            $turler,
        );

        return count($adlar) === 1 ? $adlar[0] : implode(' ya da ', $adlar);
    }

    /**
     * Bu kayıt için kasadan seçilebilecek türler.
     *
     * Sürücü söylemiyorsa SSH varsayılır: bu sorunun sorulduğu her yerde
     * zaten makinenin yönetim kimliği aranıyor ve Linux tarafı çoğunluk.
     *
     * @return list<string>
     */
    public function erisimTurleri(Credential $kayit): array
    {
        $surucu = app(DriverRegistry::class)->for($kayit);

        $turler = $surucu instanceof NeedsMachineAccount
            ? $surucu->makineErisimTurleri()
            : [AccessType::Ssh];

        return array_map(fn (AccessType $tur): string => $tur->value, $turler);
    }
}
