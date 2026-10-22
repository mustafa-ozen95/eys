import { expect, test } from '@playwright/test';

/**
 * Toplu döndürme şeridi.
 *
 * Sorulan soru "kutu görünüyor mu" değil: seçim sayısı doğru mu, onay
 * penceresi KAÇ kaydı etkilediğini söylüyor mu, gerekçesiz gönderilebiliyor mu.
 */
const EPOSTA = process.env.E2E_EMAIL;
const PAROLA = process.env.E2E_PASSWORD;

/**
 * Merkez açıkken parola formu KATLI gelir; açmadan doldurulamaz.
 *
 * Başlığa TIKLANIR, betikle açılmaz: <details> betiksiz de çalışan bir öğe ve
 * senaryolardan biri tam olarak betiksiz kurulumu sınıyor. `open` özniteliği
 * sunucudan geliyorsa (merkez kapalı kurulum) hiç dokunulmaz.
 */
async function parolaFormunuAc(page) {
    const katlanir = page.locator('details.gate__collapsible');

    if (await katlanir.count() && await katlanir.getAttribute('open') === null) {
        await page.locator('summary.gate__collapsible-title').click();
    }
}

async function girisYap(page) {
    await page.goto('/login');

    await parolaFormunuAc(page);

    await page.fill('#email', EPOSTA);
    await page.fill('#password', PAROLA);
    await page.click('form button[type="submit"]');
    await expect(page).toHaveURL(/access/);
}

test('secim sayaci ve dugme durumu', async ({ page }) => {
    await girisYap(page);

    const dugme = page.locator('[data-bulk-submit]');
    const kutular = page.locator('[data-bulk-item]');

    await expect(dugme).toBeDisabled();
    await expect(page.locator('[data-bulk-count]')).toHaveText('0');

    await kutular.first().check();
    await expect(page.locator('[data-bulk-count]')).toHaveText('1');
    await expect(dugme).toBeEnabled();

    await kutular.nth(1).check();
    await expect(page.locator('[data-bulk-count]')).toHaveText('2');

    /* Hepsini seç, sonra geri al. */
    const hepsi = page.locator('[data-bulk-all]');
    await hepsi.check();
    const toplam = await kutular.count();
    await expect(page.locator('[data-bulk-count]')).toHaveText(String(toplam));

    await hepsi.uncheck();
    await expect(page.locator('[data-bulk-count]')).toHaveText('0');
    await expect(dugme).toBeDisabled();
});

test('onay penceresi kac kaydi etkiledigini soyler ve gerekce ister', async ({ page }) => {
    await girisYap(page);

    await page.locator('[data-bulk-item]').first().check();
    await page.locator('[data-bulk-item]').nth(1).check();
    await page.locator('[data-bulk-submit]').click();

    const onay = page.locator('[data-confirm-dialog]');
    await expect(onay).toBeVisible();
    await expect(onay.locator('[data-confirm-message]')).toContainText('2 kaydın parolası');

    /* Gerekçe yazılmadan onay düğmesi açılmaz. */
    await expect(onay.locator('[data-confirm-accept]')).toBeDisabled();

    await onay.locator('[data-confirm-reason]').fill('Toplu yenileme provasi');
    await expect(onay.locator('[data-confirm-accept]')).toBeEnabled();

    await onay.locator('[data-confirm-accept]').click();

    await expect(page.locator('.toast')).toContainText('2 kayıt sıraya alındı');
});

test('satirin tamami hala bagalanti, kutu ise satiri acmiyor', async ({ page }) => {
    await girisYap(page);

    /* Kutuya tıklamak kaydı AÇMAMALI: sayfa aynı kalır. */
    const adres = page.url();
    await page.locator('[data-bulk-item]').first().check();
    expect(page.url()).toBe(adres);

    /*
     * Satırın herhangi bir yerine tıklamak kaydı açar: bağlantının katmanı
     * satırın tamamını kaplıyor. (Hücreyi doğrudan hedeflemek Playwright'ta
     * "başka eleman tıklamayı yakalıyor" hatası verir — istenen davranış
     * tam olarak budur.)
     */
    await page.locator('.grid-row').first().click();
    await expect(page).toHaveURL(/kayit=/);
    await expect(page.locator('.detail')).toBeVisible();
});

test('tum kasa dugmesi yok: yalniz secilenler guncellenir', async ({ page }) => {
    await girisYap(page);
    await expect(page.locator('[data-bulk-submit]')).toBeVisible();
    await expect(page.locator('[data-bulk-all-submit]')).toHaveCount(0);
    await expect(page.getByText('Tüm kasayı güncelle')).toHaveCount(0);
});
