<?php

declare(strict_types=1);

namespace App\Services\Rotation\Contracts;

use App\Enums\AccessType;

/**
 * DEĞİŞTİREN kimliğin MAKİNENİN HESABI olduğunu bildiren sürücü.
 *
 * Çoğu sürücüde parolayı değiştiren, parolası değişen hesabın kendisidir:
 * cihaza o hesapla bağlanılır ve kendi parolası değiştirilir. Plesk, mailcow
 * yöneticisi ve rspamd'de böyle değil — panelin yönetici parolası makinede
 * koşan bir komutla değişiyor, yani bağlantı makinenin kendi yönetim
 * hesabıyla kuruluyor.
 *
 * HANGİ TÜR KAYIT SEÇİLEBİLECEĞİNİ SÜRÜCÜ SÖYLER: Linux'ta makinenin SSH
 * kaydı, Windows'ta RDP/uzak yönetim kaydı. Ekran listeyi buradan doldurur;
 * ikinci bir liste tutulsaydı yeni bir sürücü eklendiğinde sessizce eskirdi.
 *
 * Kimlik şu sırayla aranır (bkz. App\Services\Rotation\MachineAccount):
 *
 *   1. Kayda BAĞLANMIŞ kimlik (`rotation_credential_id`) — ekrandaki liste
 *      bunu seçtirir. Kopya değil bağ olduğu için hep güncel.
 *   2. Kaydın kendi "güncelleme hesabı" alanı — elle yazılmış kimlik.
 *
 * Aynı adresteki kayıt kendiliğinden KULLANILMAZ, yalnızca önerilir: uzaktaki
 * bir makinede parola değiştiren bir işin hangi kimlikle bağlanacağı, ekrana
 * bakan kişinin göremediği bir kuraldan çıkmamalı. Hesap seçilmemişse
 * "parolayı güncelle" seçeneği gizlenir.
 */
interface NeedsMachineAccount
{
    /**
     * Kasadan seçilebilecek kayıt türleri.
     *
     * @return list<AccessType>
     */
    public function makineErisimTurleri(): array;
}
