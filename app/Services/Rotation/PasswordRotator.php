<?php

declare(strict_types=1);

namespace App\Services\Rotation;

use App\Enums\ActivityAction;
use App\Enums\RotationStage;
use App\Enums\RotationStatus;
use App\Models\Credential;
use App\Models\PasswordRotation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Rotation\Contracts\LimitsPasswordLength;
use App\Services\Rotation\Contracts\NamesRecoveryAccount;
use App\Services\Rotation\Contracts\NeedsMachineAccount;
use App\Services\Rotation\Contracts\PasswordDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bir Linux hesabına yeni ve rastgele bir parola verir.
 *
 * SIRA GÜVENLİĞİN KENDİSİDİR:
 *
 *  1. Makinede tam yetkili bir KURTARMA hesabı açılır.
 *  2. O hesapla gerçekten bağlanılabildiği KANITLANIR. Kanıtlanamazsa hesap
 *     silinir ve tur burada biter — çalışmayan bir kurtarma hesabı güvence
 *     değil, açıkta duran fazladan bir yetkidir.
 *  3. Asıl hesabın parolası KURTARMA HESABIYLA değiştirilir. Değiştirdiğin
 *     parolayla bağlı olduğun oturum, iş yarıda kalırsa seni dışarıda bırakır.
 *  4. Yeni parola DENENİR: yeni bir oturum açılır.
 *  5. Ancak bundan sonra kasadaki kayıt güncellenir.
 *  6. Kurtarma hesabı YENİ parolayla silinir; silme işleminin kendisi yeni
 *     parolanın çalıştığının ikinci kanıtıdır.
 *
 * Herhangi bir adımda takılırsa kurtarma hesabı makinede BIRAKILIR ve
 * parolası panelde şifreli durur: makineye dönebileceğimiz bir kapı hep açık
 * kalır. Ekran bunu uyarı olarak gösterir, repair() kapatır.
 *
 * Yeni parola makineye yazılmadan ÖNCE kayda geçer. Yazma ile doğrulama
 * arasında süreç ölürse parola yalnızca bellekte kalmış olurdu ve makine
 * kendi parolasını bilmediğimiz bir makineye dönerdi.
 */
class PasswordRotator
{
    /**
     * Turu başlatanın gerekçesi.
     *
     * Ekran gerekçe istiyordu ve doğruluyordu ama kimse taşımıyordu: denetim
     * kaydına aşamanın açıklaması yazılıyor, kullanıcının yazdığı sebep
     * yoluna devam etmeden düşüyordu. "Neden yapıldı" sorusunun cevabı
     * kaydın kendisinde durmalı.
     */
    private ?string $reason = null;

    public function __construct(
        private DriverRegistry $registry,
        private PasswordGenerator $generator,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @param  string  $unattended  Oturum açmış bir kullanıcı yoksa kayda
     *                              yazılacak ad. Varsayılanı zamanlanmış görev
     *                              içindir; konsoldan elle başlatılan tur
     *                              kendini öyle göstermemeli — denetim kaydı
     *                              "kim yaptı" sorusunu doğru yanıtlamalı.
     */
    /** Kayıt başına döndürme kilidi. */
    private const LOCK = 'access:rotation:';

    public function rotate(
        Credential $credential,
        ?User $actor = null,
        ?string $reason = null,
        string $unattended = 'zamanlanmış görev',
    ): PasswordRotation {
        $this->reason = $reason;

        $rotation = $credential->rotations()->create([
            'user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name ?? $unattended,
            'status' => RotationStatus::Running,
            'stage' => RotationStage::Started,
            'previous_password' => $credential->password,
        ]);

        $blocked = $this->registry->blockedReason($credential);

        if ($blocked !== null) {
            return $this->finish($rotation, RotationStatus::Failed, $blocked);
        }

        /*
         * AYNI KAYDA İKİ TUR AYNI ANDA GİREMEZ.
         *
         * Tetikleyen dört yol var: tek kayıt düğmesi, toplu güncelleme,
         * zamanlanmış görev ve konsol. İkisi aynı anda çalışsaydı ikisi de
         * "önceki parola" olarak kasadaki değeri okur, ikisi de cihazda
         * parolayı değiştirir ve kasaya SON YAZAN kalırdı — cihazdaki parola
         * ile kasadaki farklı olabilirdi. Bir parola kasası için en kötü
         * sonuç budur; üstelik fark ancak bir sonraki girişte anlaşılırdı.
         *
         * Kilit KAYIT BAŞINADIR: farklı kayıtlar birbirini beklemez, toplu
         * güncelleme yavaşlamaz.
         */
        $lock = Cache::lock(self::LOCK.$credential->getKey(), $this->lockSeconds());

        if (! $lock->get()) {
            return $this->finish(
                $rotation,
                RotationStatus::Failed,
                'Bu kayıt için bir güncelleme zaten sürüyor; ikinci tur başlatılmadı.',
            );
        }

        try {
            $this->run($credential, $rotation);
        } catch (Throwable $e) {
            return $this->fail($rotation, $e);
        } finally {
            /* Tur nasıl biterse bitsin kilit BIRAKILIR; yoksa kayıt süre dolana dek kilitli kalırdı. */
            $lock->release();
        }

        return $rotation->refresh();
    }

    /**
     * Kilidin ömrü.
     *
     * İşin zaman aşımından uzun tutulur: işi öldüren bir zaman aşımı
     * `finally` bloğunu çalıştırmayabilir ve kilit ancak süresi dolunca
     * düşer. Kısa tutmak, ölü bir turun kilidini erken bırakıp ikinci turun
     * araya girmesine yol açardı.
     */
    private function lockSeconds(): int
    {
        return (int) config('rotation.lock_seconds', 600);
    }

    private function run(Credential $credential, PasswordRotation $rotation): void
    {
        $driver = $this->registry->for($credential);

        /*
         * KURTARMA HESAPLI SIRA İSTİSNA DEĞİL, STANDARTTIR.
         *
         * Sürücü geçici hesap açabiliyorsa tur hep aynı biçimde işler —
         * döndürülen hesap ister sıradan bir kullanıcı, ister yöneticinin
         * kendisi olsun: önce geçici hesap açılır, o hesapla GERÇEKTEN
         * bağlanılabildiği kanıtlanır, parola ondan sonra değiştirilir,
         * sonuç sınanır ve geçici hesap en sonda silinir.
         *
         * Ayrı bir yönetim kimliği varken geçici hesap gereksiz görünür ama
         * değildir: yöneticinin yetkisi turun ortasında kaybolabilir (parolası
         * başka bir yerden değişir, hesap kilitlenir), ve o an elimizde
         * kanıtlanmış ikinci bir kapı olmazsa kayıt cihazla ayrışmış hâlde
         * kalır. Geçici hesabın maliyeti bir kayıt, karşılığı geri dönüş
         * garantisidir.
         */
        $driver->supportsRecovery()
            ? $this->runWithRecovery($credential, $rotation, $driver)
            : $this->runDirect($credential, $rotation, $driver);
    }

    /**
     * Yeni parola — cihazın ÜST SINIRINA uyarak.
     *
     * Panel ayarı uzunluğu belirler; kimi platform daha kısasını şart koşar
     * (bkz. LimitsPasswordLength). Sınırı görmezden gelmek, turu cihazın
     * "parola politikası" hatasıyla düşürürdü.
     */
    private function newPassword(PasswordDriver $driver): string
    {
        $length = (int) config('rotation.password_length', 24);

        if ($driver instanceof LimitsPasswordLength) {
            $length = min($length, $driver->maxPasswordLength());
        }

        return $this->generator->generate($length);
    }

    /**
     * Kurtarma hesaplı sıra — parolasını değiştirdiğimiz hesapla bağlandığımız
     * platformlar için (SSH, Windows).
     */
    private function runWithRecovery(
        Credential $credential,
        PasswordRotation $rotation,
        PasswordDriver $driver,
    ): void {
        /*
         * Kurtarma hesabının adını bazı cihazlar kendisi belirler (Junos:
         * ayardaki sabit ad). Rastgele ek, ad kısıtı olan cihazlarda ya da
         * ad üzerinden yetki kuralı yazılmış yerlerde sorun çıkarıyordu.
         */
        $recoveryName = $driver instanceof NamesRecoveryAccount
            ? $driver->recoveryName()
            : $this->recoveryName();
        $recoveryPassword = $this->newPassword($driver);

        /*
         * Kurtarma KİMLİĞİ makineye gitmeden önce kayda geçer: hesap açılırken
         * süreç ölürse cihazda parolasını bilmediğimiz bir hesap kalırdı.
         */
        $rotation->forceFill([
            'recovery_username' => $recoveryName,
            'recovery_password' => $recoveryPassword,
        ])->save();

        // 1 — kurtarma hesabı.
        [$kimlik, $kimlikParolasi] = $this->identity($credential, $driver);

        $session = $driver->open($credential, $kimlik, $kimlikParolasi);

        try {
            /*
             * "CİHAZDA HESAP DURUYOR" BAYRAĞI ANCAK BURADA KALKAR.
             *
             * Kimliği kaydetmek ile "cihazda bir hesap var" demek ayrı
             * şeylerdir; ikisi birlikte yapılınca cihaza HİÇ BAĞLANILAMAYAN
             * turlar bile "yarıda kalmış tur, kurtarma hesabı duruyor" diye
             * kırmızı uyarı bırakıyordu. Örnek: cihaz ağdan kopuksa tur ilk
             * adımda düşer; bayrak erken kalksaydı panelde cihazda olmayan bir
             * hesap için uyarı asılı kalırdı — üstelik uyarıyı kapatmak cihaza
             * bağlanmayı gerektirdiği için kapatılamazdı.
             *
             * Bayrak yine hesabın açılmasından ÖNCE kalkar: açılış yarıda
             * kesilirse hesap cihazda kalmış olabilir ve o ihtimalde
             * susmaktansa fazladan uyarmak doğrudur. Kazanılan şey, oturum
             * hiç açılamadığında yanlış alarm vermemek.
             */
            $rotation->forceFill(['recovery_active' => true])->save();

            $driver->createRecovery($session, $recoveryName, $recoveryPassword);
            $rotation->forceFill(['stage' => RotationStage::RecoveryCreated])->save();
        } finally {
            $session->close();
        }

        // 2 — kurtarma hesabı gerçekten çalışıyor mu.
        if (! $driver->verify($credential, $recoveryName, $recoveryPassword)) {
            $this->removeRecovery($credential, $rotation, $kimlik, $kimlikParolasi);

            throw new RotationException('Kurtarma hesabı açıldı ama onunla bağlanılamadı; tur iptal edildi.');
        }

        $rotation->forceFill(['stage' => RotationStage::RecoveryVerified])->save();

        // 3 — parola değişimi, KURTARMA hesabıyla.
        $newPassword = $this->newPassword($driver);
        $rotation->forceFill(['attempted_password' => $newPassword])->save();

        $recovery = $driver->open($credential, $recoveryName, $recoveryPassword);

        try {
            $driver->changePassword($recovery, $this->hesapAdi($credential), $newPassword);
            $rotation->forceFill(['stage' => RotationStage::PasswordChanged])->save();
        } finally {
            $recovery->close();
        }

        // 4 — yeni parola deneniyor.
        if (! $driver->verify($credential, $this->hesapAdi($credential), $newPassword)) {
            throw new RotationException('Yeni parola makineye yazıldı ama onunla bağlanılamadı.');
        }

        $rotation->forceFill(['stage' => RotationStage::PasswordVerified])->save();

        // 5 — kasadaki kayıt ancak şimdi değişir.
        $this->storePassword($credential, $newPassword);

        // 6 — kurtarma hesabı YENİ parolayla silinir.
        $this->removeRecovery($credential, $rotation, $this->hesapAdi($credential), $newPassword);

        $rotation->forceFill(['stage' => RotationStage::RecoveryRemoved])->save();

        $this->finish($rotation, RotationStatus::Succeeded, 'Parola güncellendi, doğrulandı ve kurtarma hesabı silindi.');
    }

    /**
     * Kurtarma hesabı GEREKTİRMEYEN sıra.
     *
     * Değiştiren kimlik (API anahtarı, ayrı bir SSH hesabı) ile değiştirilen
     * parola ayrı şeyler; kapı zaten açık kalıyor. Bir şey ters giderse eski
     * parola aynı kanaldan geri konur.
     */
    private function runDirect(
        Credential $credential,
        PasswordRotation $rotation,
        PasswordDriver $driver,
    ): void {
        $newPassword = $this->newPassword($driver);
        $previous = $credential->password;

        $rotation->forceFill(['attempted_password' => $newPassword])->save();

        [$kimlik, $kimlikParolasi] = $this->identity($credential, $driver);

        $session = $driver->open($credential, $kimlik, $kimlikParolasi);

        try {
            $driver->changePassword($session, $this->hesapAdi($credential), $newPassword);
            $rotation->forceFill(['stage' => RotationStage::PasswordChanged])->save();

            /*
             * Doğrulayamayan sürücüde tur "doğrulanamadı" olarak kapanır.
             * Kasa yine de güncellenir: değişiklik servise yazıldı ve geri
             * dönüş kanalı (anahtar/ayrı hesap) elimizde. Ama "başarılı"
             * demeyiz — kanıtlayamadığımız şeye kanıtlanmış muamelesi
             * yapmak kasaya duyulan güveni bozar.
             */
            if (! $driver->canVerify()) {
                $this->storePassword($credential, $newPassword);

                $this->finish(
                    $rotation,
                    RotationStatus::Unverified,
                    'Parola değiştirildi ve kasaya yazıldı, ama bu servis yeni parolayı sınamaya izin vermiyor. Elle doğrulayın.',
                );

                return;
            }

            if ($driver->verify($credential, $this->hesapAdi($credential), $newPassword)) {
                $rotation->forceFill(['stage' => RotationStage::PasswordVerified])->save();
                $this->storePassword($credential, $newPassword);

                $this->finish($rotation, RotationStatus::Succeeded, 'Parola güncellendi ve doğrulandı.');

                return;
            }

            /* Doğrulanamadı: aynı kanaldan eskiye dön. */
            if ($previous !== null) {
                $driver->changePassword($session, $this->hesapAdi($credential), $previous);

                if ($driver->verify($credential, $this->hesapAdi($credential), $previous)) {
                    $this->finish(
                        $rotation,
                        RotationStatus::RolledBack,
                        'Yeni parola doğrulanamadı; eski parola geri kondu ve çalışıyor.',
                    );

                    return;
                }
            }

            throw new RotationException('Yeni parola doğrulanamadı ve eskisi geri konamadı.');
        } finally {
            $session->close();
        }
    }

    /**
     * Yarıda kalmış bir turu kapatır.
     *
     * Hangi kimliğin geçerli olduğu BİLİNMEZ — tur nerede takıldığına göre
     * eski parola, yeni parola ya da yalnızca kurtarma hesabı çalışıyor
     * olabilir. Üçü de sırayla denenir; ilk açılan oturum yolu belirler.
     */
    public function repair(PasswordRotation $rotation, ?User $actor = null): PasswordRotation
    {
        $credential = $rotation->credential;

        if (! $rotation->recovery_active || $credential === null) {
            return $rotation;
        }

        /*
         * Onarım da AYNI KİLİDİ alır — döndürmeyle paylaşılan kilit.
         *
         * Onarım da cihaza bağlanır, kimlik dener ve kurtarma hesabını siler.
         * Sürmekte olan bir turun ortasında bunu yapmak, o turun az önce
         * açtığı kurtarma hesabını silmek ya da kasadaki parolayı yanlış
         * değerle güncellemek demekti.
         */
        $lock = Cache::lock(self::LOCK.$credential->getKey(), $this->lockSeconds());

        if (! $lock->get()) {
            /* Sessiz geçilmez: kullanıcı neden bir şey olmadığını görmeli. */
            $this->activity->record(
                ActivityAction::RotationFailed,
                "{$credential->group?->name} · {$credential->name} · Onarım ertelendi",
                $credential,
                'Bu kayıt için bir güncelleme sürüyor; onarım şimdi yapılmadı.',
                $actor ?? $rotation->user,
            );

            return $rotation;
        }

        try {
            return $this->runRepair($rotation, $credential, $actor);
        } finally {
            $lock->release();
        }
    }

    private function runRepair(
        PasswordRotation $rotation,
        Credential $credential,
        ?User $actor,
    ): PasswordRotation {
        $driver = $this->registry->for($credential);

        if ($driver === null) {
            return $this->finish($rotation, RotationStatus::Unverified, 'Sürücü bulunamadı; kurtarma hesabı elle silinmeli.');
        }

        /*
         * Kasadaki parola ÖNCE denenir. Tur 3. adımdan sonra takıldıysa
         * makinedeki parola yeni olabilir; o yüzden denenen parola da
         * listede. Sonuncu çare kurtarma hesabının kendisi.
         */
        $adaylar = [
            /* Ayrı bir yönetim kimliği varsa tur ondan hiç çıkmamıştır. */
            $this->identity($credential, $driver),
            [$credential->username, $credential->password],
            [$credential->username, $rotation->attempted_password],
            [$rotation->recovery_username, $rotation->recovery_password],
        ];

        foreach ($adaylar as [$username, $password]) {
            if (blank($username) || blank($password)) {
                continue;
            }

            if (! $driver->verify($credential, $username, $password)) {
                continue;
            }

            /*
             * Makinedeki parola denenen parolaysa kasa geride kalmış demektir;
             * ikisi ayrışmış hâlde bırakılmaz.
             */
            if ($username === $credential->username && $password === $rotation->attempted_password) {
                $this->storePassword($credential, $password);
            }

            try {
                $this->removeRecovery($credential, $rotation, $username, $password);
            } catch (Throwable $e) {
                return $this->finish($rotation, RotationStatus::Unverified, 'Kurtarma hesabı silinemedi: '.$e->getMessage());
            }

            $rotation->forceFill(['stage' => RotationStage::RecoveryRemoved])->save();

            return $this->finish($rotation, RotationStatus::RolledBack, 'Tur kapatıldı; kurtarma hesabı silindi.', $actor);
        }

        return $this->finish(
            $rotation,
            RotationStatus::Unverified,
            'Hiçbir kimlikle bağlanılamadı. Makineye elle girip kurtarma hesabını silmek gerekiyor.',
            $actor,
        );
    }

    /**
     * Kurtarma hesabını siler — YETKİSİ OLAN kimlikle.
     *
     * Onarım, çalışan bir GİRİŞ arayarak başlıyor; ama silmek yetki ister ve
     * ikisi aynı şey değil. Örnek (vCenter 8.0.3): kaydın kendi hesabı (root)
     * giriş yapabiliyor, hesap uçlarında ise 403 alıyor. O kimlikle silmeye
     * çalışan onarım "kurtarma hesabı silinemedi" deyip kaydı sonsuza dek
     * yarıda bırakırdı.
     *
     * Bu yüzden önce TURU YÜRÜTEN kimlik denenir (kaydın güncelleme hesabı),
     * sonra onarımın bulduğu çalışan kimlik. İlki yoksa ikisi zaten aynıdır.
     */
    private function removeRecovery(
        Credential $credential,
        PasswordRotation $rotation,
        string $username,
        string $password,
    ): void {
        $driver = $this->registry->for($credential);
        $son = null;

        foreach ([$this->identity($credential, $driver), [$username, $password]] as [$ad, $parola]) {
            if (blank($ad) || blank($parola)) {
                continue;
            }

            try {
                $session = $driver->open($credential, $ad, $parola);

                try {
                    $driver->deleteRecovery($session, (string) $rotation->recovery_username);
                } finally {
                    $session->close();
                }

                $rotation->forceFill(['recovery_active' => false])->save();

                return;
            } catch (Throwable $e) {
                $son = $e;
            }
        }

        throw $son ?? new RotationException('Kurtarma hesabı silinemedi.');
    }

    private function fail(PasswordRotation $rotation, Throwable $e): PasswordRotation
    {
        /*
         * Kurtarma hesabı hâlâ duruyorsa durum "başarısız" değil
         * "doğrulanamadı"dır: makinede açıkta duran bir yetki var ve
         * kapatılana kadar bu bir açıktır.
         */
        $status = $rotation->fresh()->recovery_active
            ? RotationStatus::Unverified
            : RotationStatus::Failed;

        return $this->finish($rotation, $status, $e->getMessage());
    }

    private function finish(
        PasswordRotation $rotation,
        RotationStatus $status,
        string $message,
        ?User $actor = null,
    ): PasswordRotation {
        $rotation->forceFill([
            'status' => $status,
            'message' => Str::limit($message, 190),
            'finished_at' => now(),
        ])->save();

        $rotation->loadMissing('credential.group');

        $this->activity->record(
            $status === RotationStatus::Succeeded ? ActivityAction::Rotated : ActivityAction::RotationFailed,
            "{$rotation->credential?->group?->name} · {$rotation->credential?->name} · {$status->label()}",
            $rotation->credential,
            $this->reason ?? $rotation->stage->hint(),
            $actor ?? $rotation->user,
        );

        return $rotation->refresh();
    }

    /**
     * Kurtarma hesabının adı her turda FARKLIDIR.
     *
     * Sabit bir ad, yarıda kalmış eski bir turun hesabıyla çakışır ve
     * "useradd: user already exists" hatası turu daha ilk adımda düşürürdü.
     */
    /**
     * PAROLASI DEĞİŞEN HESABIN ADI — hesabı olmayan kayıtlarda boş metin.
     *
     * Rspamd'ın hesap kavramı yok: tek bir parola var ve kayıtta kullanıcı
     * adı boş duruyor. Alan doğrudan sürücüye verilseydi tur daha ilk
     * adımda düşerdi:
     *
     *     changePassword(): Argument #2 ($username) must be of type string,
     *     null given
     *
     * Sürücülerin imzası `string`; "hesap yok" bilgisini boş metin taşır.
     * null'ı sürücüye kadar götürmek, her sürücüde ayrı ayrı korunmayı
     * gerektirirdi.
     */
    private function hesapAdi(Credential $credential): string
    {
        return (string) $credential->username;
    }

    /**
     * Bağlanırken kullanılacak kimlik.
     *
     * Ayrı bir döndürme hesabı verilmişse o, verilmemişse kaydın KENDİ
     * hesabı. Ekrandaki ipucu bunu yıllardır söylüyordu ama kurtarma
     * hesapsız sıra yalnızca döndürme hesabına bakıyordu: alan boş olan
     * kayıtlarda boş kimlikle bağlanmayı deniyor ve tur daha ilk adımda
     * düşüyordu.
     *
     * @return array{0: string, 1: string}
     */
    private function identity(Credential $credential, ?PasswordDriver $driver = null): array
    {
        /*
         * MAKİNENİN SSH KİMLİĞİNİ İSTEYEN SÜRÜCÜLER (Plesk, mailcow
         * yöneticisi, rspamd) kimliği kasadan da bulabilir: kaydın kendi
         * alanı boşsa aynı adresli SSH kaydına düşülür. Aynı parolayı kasada
         * iki yerde tutmak, biri döndüğünde ötekini sessizce eskitiyordu
         * (bkz. MachineAccount).
         */
        if ($driver instanceof NeedsMachineAccount) {
            $ssh = app(MachineAccount::class)->bul($credential);

            if ($ssh !== null) {
                return $ssh;
            }
        }

        return filled($credential->rotation_username)
            ? [(string) $credential->rotation_username, (string) $credential->rotation_password]
            : [(string) $credential->username, (string) $credential->password];
    }

    /**
     * Bağlandığımız kimlik, parolasını değiştireceğimiz hesabın ta kendisi mi?
     *
     * Cihazlar kullanıcı adında büyük/küçük harf ayrımı yapmaz; `Administrator`
     * ile `administrator` aynı hesaptır ve farklı sayılsaydı tur kendini
     * dışarıda bırakırken kurtarma hesabı açmazdı.
     */
    private function rotatesOwnIdentity(Credential $credential, ?PasswordDriver $driver = null): bool
    {
        [$kimlik] = $this->identity($credential, $driver);

        return mb_strtolower(trim($kimlik)) === mb_strtolower(trim((string) $credential->username));
    }

    /**
     * Kasadaki kaydı yeni parolayla günceller.
     *
     * DÖNDÜRME KİMLİĞİ DE AYNI HESAPSA O DA GÜNCELLENİR. Yoksa cihazdaki
     * parola yeni, kasadaki döndürme kimliği eski kalırdı: bir sonraki tur —
     * ve panelden açılan her oturum — artık geçersiz bir parolayla bağlanmaya
     * çalışır, hata da ancak o zaman görünürdü.
     */
    private function storePassword(Credential $credential, string $newPassword): void
    {
        $degisenler = ['password' => $newPassword, 'rotated_at' => now()];

        if (filled($credential->rotation_username) && $this->rotatesOwnIdentity($credential)) {
            $degisenler['rotation_password'] = $newPassword;
        }

        $credential->forceFill($degisenler)->save();

        $this->spreadIdentity($credential, $newPassword);
    }

    /**
     * Döndürülen hesabı BAŞKA kayıtların döndürme kimliği olarak kullananları
     * da günceller.
     *
     * Bir KVM'de iki kayıt tutulur: yönetici hesabı ve kullanıcı hesabı.
     * Kullanıcı kaydının parolasını değiştiren şey yönetici hesabıdır, yani
     * yöneticinin parolası kullanıcı kaydının `rotation_password` alanında da
     * DURUR. Yönetici döndürülünce o alan eski parolayla kalırdı: kullanıcı
     * kaydının bir sonraki turu "cihaza ulaşılamadı" ile düşer, üstelik hata
     * ancak o tur çalıştığında görünürdü.
     *
     * Eşleşme AYNI CİHAZLA sınırlıdır. Aynı kullanıcı adı (`administrator`,
     * `root`) onlarca cihazda bulunur; adrese bakmayan bir güncelleme, bir
     * cihazın parolasını hepsinin kaydına yazardı.
     */
    private function spreadIdentity(Credential $credential, string $newPassword): void
    {
        $ad = trim((string) $credential->username);
        $adres = trim((string) $credential->host);

        if ($ad === '' || $adres === '') {
            return;
        }

        Credential::query()
            ->whereKeyNot($credential->getKey())
            ->whereRaw('LOWER(TRIM(host)) = ?', [mb_strtolower($adres)])
            ->whereRaw('LOWER(TRIM(rotation_username)) = ?', [mb_strtolower($ad)])
            ->get()
            ->each(function (Credential $diger) use ($newPassword): void {
                $diger->forceFill(['rotation_password' => $newPassword])->save();
            });
    }

    private function recoveryName(): string
    {
        return 'eys-kurtarma-'.Str::lower(Str::random(6));
    }
}
