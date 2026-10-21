import { expect, test } from '@playwright/test';

/**
 * Süren parola turunun ilerleme çubuğu.
 *
 * TUR BURADA BAŞLATILMAZ. Ekranı sınamak için gerçek bir makinenin parolasını
 * değiştirmek ödenecek bir bedel değil; tarayıcıdan başlatılan bir tur
 * yanlış kaydı seçerse üretimdeki bir sunucuya dokunur. Tur kaydı testin
 * DIŞINDA hazırlanır (E2E_KAYIT ile hangi kaydın açılacağı verilir), burada
 * yalnızca ekranın davranışı ölçülür:
 *
 *  1. Çubuk çiziliyor ve "Parolayı güncelle" düğmesi gizleniyor mu?
 *  2. Tur bitince sayfa TARAYICI YENİLEMESİ yapmadan tazeleniyor mu?
 *     Pencereye bir iz bırakılır; tam yenilemede o iz kaybolur.
 */
const EPOSTA = process.env.E2E_EMAIL;
const PAROLA = process.env.E2E_PASSWORD;

/** Turu hazırlanmış kaydın adresi (?kayit=uuid dâhil). */
const ADRES = process.env.E2E_ADRES;

async function parolaFormunuAc(page) {
    const katlanir = page.locator('details.gate__katlanir');

    if (await katlanir.count() && await katlanir.getAttribute('open') === null) {
        await page.locator('summary.gate__katlanir-baslik').click();
    }
}

async function girisYap(page) {
    await page.goto('/giris');
    await parolaFormunuAc(page);
    await page.fill('#email', EPOSTA);
    await page.fill('#password', PAROLA);
    await page.click('form button[type="submit"]');
    await expect(page).toHaveURL(/erisim/);
}

test('süren tur çubukla gösterilir, düğme gizlenir', async ({ page }, testInfo) => {
    test.setTimeout(120000);

    await girisYap(page);
    await page.goto(ADRES);

    const cubuk = page.locator('[role="progressbar"]');
    await expect(cubuk).toBeVisible({ timeout: 15000 });

    /*
     * ÇUBUK KAYDIRMADAN GÖRÜNMELİ.
     *
     * Ölçüm (Pixel 7, 412×915): çubuk "Parola güncelleme" bölümünün
     * içindeyken görüntü alanının tamamen dışında kalıyordu —
     * DOM'da vardı, ekranda yoktu. Görünmeyen bir ilerleme çubuğunun varlık
     * sebebi yok.
     */
    await expect(cubuk).toBeInViewport();

    /* Tur sürerken ikinci kez başlatmaya davet edilmemeli. */
    await expect(page.getByRole('button', { name: 'Parolayı güncelle' })).toHaveCount(0);

    await page.screenshot({ path: testInfo.outputPath('eys-ilerleme.png'), fullPage: false });

    /* Yenilemede silinecek iz. */
    await page.evaluate(() => {
        window.__eysIsaret = 'duruyor';
    });

    /* Tur dışarıdan bitirilince çubuk kaybolur, düğme geri gelir. */
    await expect(cubuk).toHaveCount(0, { timeout: 90000 });
    await expect(page.getByRole('button', { name: 'Parolayı güncelle' })).toBeVisible();

    await page.screenshot({ path: testInfo.outputPath('eys-ilerleme-bitis.png'), fullPage: false });

    /* İz duruyorsa sayfa YERİNDE tazelendi. */
    expect(await page.evaluate(() => window.__eysIsaret ?? null)).toBe('duruyor');
});

/**
 * TURU BAŞLATMAK SAYFAYI YENİLEMEZ.
 *
 * Tur bittiğinde sayfa yerinde tazeleniyordu ama BAŞLATAN düğme hâlâ tam
 * sayfa gönderiyordu: ekran beyaza düşüyor ve kullanıcı işin başladığını
 * ancak yeni sayfa çizilince görüyordu — tam da ilerlemeyi izlemek istediği
 * anda.
 *
 * Bu senaryo GERÇEK bir tur başlatır, bu yüzden kaydın hangi kayıt olduğu
 * ÖNCE doğrulanır: yanlış kayıtta çalışan bir test üretimdeki bir sunucunun
 * parolasını değiştirirdi.
 */
test('turu başlatmak sayfayı yenilemez', async ({ page }) => {
    test.setTimeout(120000);

    await girisYap(page);
    await page.goto(ADRES);

    /* Güvenlik kilidi: yalnızca sınama için açılmış kayıtta çalışır. */
    await expect(page.locator('.detail__title')).toHaveText(/test hesabı/);

    await page.evaluate(() => {
        window.__eysIsaret = 'duruyor';
    });

    await page.getByRole('button', { name: 'Parolayı güncelle' }).click();

    /*
     * Pencere kayıt adını DEĞİL adresi istiyor (bkz. CredentialListing:
     * adresi olan kayıtta doğrulama metni adrestir). Hangi kayıtta
     * olduğumuzun güvencesi yukarıdaki başlık kontrolü; buradaki yalnızca
     * pencerenin bir şey istediğini doğrular.
     */
    const beklenen = (await page.locator('[data-confirm-expected]').textContent())?.trim() ?? '';
    expect(beklenen).not.toBe('');

    await page.locator('[data-confirm-input]').fill(beklenen);
    await page.locator('[data-confirm-reason]').fill('yerinde gönderim doğrulaması');
    await page.locator('[data-confirm-accept]').click();

    /* Çubuk geldi: tur başladı. */
    await expect(page.locator('[role="progressbar"]')).toBeVisible({ timeout: 20000 });

    /* İz duruyorsa gönderim sayfayı yenilemedi. */
    expect(await page.evaluate(() => window.__eysIsaret ?? null)).toBe('duruyor');

    /* Tur kendi hâlinde bitsin; çubuk kaybolur, düğme geri gelir. */
    await expect(page.getByRole('button', { name: 'Parolayı güncelle' }))
        .toBeVisible({ timeout: 90000 });

    /* Bitişte de yenileme yok. */
    expect(await page.evaluate(() => window.__eysIsaret ?? null)).toBe('duruyor');
});
