<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Credential;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;

/**
 * Erişim kaydı yetkileri.
 *
 * Karar burada VERİLMEZ, çözümleyiciye sorulur: aynı soruyu iki yerde
 * yanıtlayan iki kod olsaydı biri değişip diğeri eski kalırdı.
 *
 * Yetki kaydın kendisine değil GRUBUNA verilir; yeni bir kayıt eklendiğinde
 * izin listesini güncellemek gerekmesin diye.
 */
class CredentialPolicy
{
    public const RESOURCE = 'credential_group';

    public function __construct(private UserPermissionResolver $resolver) {}

    /**
     * Liste ekranını açabilir mi?
     *
     * HERKES AÇABİLİR. Ne göreceğine kapı değil SORGU karar veriyor
     * (Credential::scopeVisibleTo): yetkisi olmayan boş bir liste görür,
     * kimsenin kaydı sızmaz.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Credential $credential): bool
    {
        return $this->can($user, 'view', $credential);
    }

    /**
     * Kayıt ekleme yetkisi tek başına yetmez; hangi gruba eklendiği
     * StoreCredentialRequest içinde ayrıca doğrulanır — yetkisi olmayan bir
     * grubun kimliği forma elle yazılabilir.
     */
    public function create(User $user): bool
    {
        return $this->resolver->allowsAny($user, self::RESOURCE, 'create');
    }

    public function update(User $user, Credential $credential): bool
    {
        return $this->can($user, 'update', $credential);
    }

    public function delete(User $user, Credential $credential): bool
    {
        return $this->can($user, 'delete', $credential);
    }

    /**
     * Parolayı ekranda görme yetkisi.
     *
     * "Görüntüle" yetkisi bunu KAPSAMAZ: grubu gören listeyi görür, parolayı
     * yalnızca o grupta 'field.password' verilmiş olan açar.
     */
    public function reveal(User $user, Credential $credential): bool
    {
        return $this->can($user, 'field.password', $credential);
    }

    /**
     * Uzaktaki makinenin parolasını değiştirme yetkisi.
     *
     * Dış dünyaya etki eden bir işlem; "düzenle" yetkisi bunu KAPSAMAZ.
     * Ayrıca parolayı görebilmeyi de gerektirir: göremediğiniz bir parolayı
     * değiştirmek, kimsenin bilmediği bir parola üretir.
     */
    public function rotate(User $user, Credential $credential): bool
    {
        return $this->can($user, 'rotate', $credential)
            && $this->can($user, 'field.password', $credential);
    }

    /**
     * Toplu döndürme ekranına girebilir mi?
     *
     * Yalnızca KAPIYI açar: hangi kayıtların döndürüleceğine tek tek
     * rotate() karar verir. Toplu uç, "birini döndürebilen hepsini
     * döndürebilir" anlamına GELMEZ.
     */
    public function rotateAny(User $user): bool
    {
        return $this->resolver->allowsAny($user, self::RESOURCE, 'rotate')
            && $this->resolver->allowsAny($user, self::RESOURCE, 'field.password');
    }

    /**
     * Parolayı DOSYAYA çıkarma yetkisi.
     *
     * "Parolayı görebilmek" tek başına YETMEZ. Ekranda görülen parola orada
     * kalır; dosyaya yazılan parola panelin dışına çıkar ve ondan sonrası
     * kaydın erişim denetiminde değildir. Ayrı bir yetki, ayrı bir karardır.
     */
    public function export(User $user, Credential $credential): bool
    {
        return $this->can($user, 'export', $credential)
            && $this->can($user, 'field.password', $credential);
    }

    /**
     * Toplu çıktı ekranına girebilir mi?
     *
     * Yalnızca KAPIYI açar; hangi satırın dosyaya gireceğine tek tek export()
     * karar verir.
     */
    public function exportAny(User $user): bool
    {
        return $this->resolver->allowsAny($user, self::RESOURCE, 'export')
            && $this->resolver->allowsAny($user, self::RESOURCE, 'field.password');
    }

    /**
     * Portal oturumu açma yetkisi.
     *
     * Parolayı görebilmeyi de gerektirir: göremediğiniz bir parolayı
     * dışarıya göndermek, hiç görmediğiniz bir şeyi kullanmaktır.
     */
    public function session(User $user, Credential $credential): bool
    {
        return $this->can($user, 'session', $credential)
            && $this->can($user, 'field.password', $credential);
    }

    /**
     * CİHAZ ARAYÜZÜNÜ vekilden açma yetkisi.
     *
     * "Portal oturumu"ndan AYRI ve parolayı görmeyi GEREKTİRMEZ. İkisi farklı
     * şey yapıyor:
     *
     *   session  Fırlatma sayfası parolayı gizli bir alana koyup KULLANICININ
     *            TARAYICISINDAN hedefe POST ediyor. Parola tarayıcıya
     *            uğradığı için görme yetkisi de aranıyor — göremediğiniz bir
     *            parolayı dışarıya göndermek, hiç görmediğiniz bir şeyi
     *            kullanmaktır.
     *
     *   arayuz   Oturum SUNUCUDA açılıyor; dönen çerez panelde kalıyor ve
     *            vekil her isteğe onu ekliyor. Parola tarayıcıya HİÇ gitmiyor
     *            (bkz. InterfaceLogin). Görme yetkisi aramak, var olmayan bir
     *            sızıntıya karşı önlem almak olurdu.
     *
     * Konsolda (connect) da aynı karar verilmiş: "parolayı GÖRMEK gerekmez —
     * konsolun amacı zaten parolayı göstermeden bağlamak". Vekil bunun web
     * arayüzü hâli.
     *
     * Kaydı GÖRMEK yine şart: göremediğiniz bir cihazın arayüzünü açamazsınız.
     */
    public function arayuz(User $user, Credential $credential): bool
    {
        return $this->can($user, 'session', $credential)
            && $this->can($user, 'view', $credential);
    }

    /**
     * Konsol (SSH / uzak masaüstü) açma yetkisi.
     *
     * Dış dünyaya etki eden bir işlem; ne "görüntüle" ne de "parolayı gör"
     * bunu kapsar. Parolayı GÖRMEK gerekmez — konsolun amacı zaten parolayı
     * göstermeden bağlamak — ama kaydı görmek gerekir: göremediğiniz bir
     * makineye oturum açamazsınız.
     *
     * Kaydın konsola uygun bir tür olup olmadığına burada bakılmaz; o soruyu
     * TargetResolver yanıtlar ve sebebini söyler. Yetki ile uygunluk ayrı
     * sorulardır.
     */
    public function connect(User $user, Credential $credential): bool
    {
        return $this->can($user, 'connect', $credential)
            && $this->can($user, 'view', $credential);
    }

    private function can(User $user, string $action, Credential $credential): bool
    {
        /*
         * KİŞİSEL ALAN KALDIRILDI. Sahipli kayıt kalmışsa
         * (owner_id) grup yetkisi onu kimseye açmaz; yalnız yönetici görür
         * (Gate::before).
         */
        if ($credential->kisiselMi()) {
            return false;
        }

        /*
         * KAYIT BAZINDA ROL KISITI HER ŞEYDEN ÖNCE gelir.
         *
         * Yetki bu panelde grup düzeyindedir; bu, grubun içinden tek bir
         * kaydı ayırmanın yolu. Kısıt konmuş bir kayıt o role TÜMÜYLE
         * kapalıdır: görüntüleme, düzenleme, silme, parola açma, oturum
         * açma. Aksiyona göre ayrım yok — "bu kayıt o role ait değil" demek,
         * altı ayrı kutuyu doğru işaretlemekten hem anlaşılır hem güvenli.
         *
         * Liste tarafı aynı kısıtı sorguda uyguluyor (Credential::visibleTo);
         * iki nokta var çünkü kayda iki yoldan varılıyor: listeden ve
         * doğrudan adresten. Yalnızca listeyi süzmek, adresi bilene kapıyı
         * açık bırakırdı.
         */
        if ($credential->isRestrictedFor($user)) {
            return false;
        }

        if ($this->resolver->allows($user, self::RESOURCE, $action, $credential->credential_group_id)) {
            return true;
        }

        /*
         * GRUP VERMEDİYSE KAYDIN KENDİSİNE BAKILIR.
         *
         * Kısıtın aynası: grubun vermediğini tek bir kayıtta vermek. Örnek:
         * bir rol bir grupta parola göremiyor ama aynı gruptaki tek bir
         * hesabı kullanması gerekiyor.
         *
         * SIRA ÖNEMLİ: kısıt yukarıda, ek izin burada. Aynı kayıtta ikisi de
         * varsa kayıt KAPALIDIR — yasak, iznin önünde gelir.
         */
        if (! in_array($action, $credential->grantedActionsFor($user), true)) {
            return false;
        }

        /*
         * KAYIT İZNİ BİR ROL KATMANI İZNİDİR, kişinin kendi kararını ezmez.
         *
         * Karar sırasında kişinin satırı rolden ÖNCE gelir (bkz.
         * UserPermissionResolver); kayıt izni resolver'ın dışında bir "veya"
         * olarak eklendiği için bu sırayı atlıyordu: kişiye açıkça yasak
         * konmuş bir parola, rolün o kayıttaki izniyle yine açılıyordu. İki
         * koşul:
         *   - kişinin bu aksiyon için kayda özel ya da genel bir satırı
         *     (yasak) varsa o kazanır;
         *   - alan izni kaydı GÖRMEYİ şart koşar (config requires_view),
         *     kayıt izni de öyle: görme yasaklıysa parola açılmaz.
         */
        if ($this->resolver->hasPersonRow($user, self::RESOURCE, $action, $credential->credential_group_id)) {
            return false;
        }

        return str_starts_with($action, 'field.') === false
            || $this->resolver->allows($user, self::RESOURCE, 'view', $credential->credential_group_id)
            || in_array('view', $credential->grantedActionsFor($user), true);
    }
}
