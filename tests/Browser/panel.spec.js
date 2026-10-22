import { expect, test } from '@playwright/test';

/**
 * Görsel ve akış testleri.
 *
 * Sorulan soru "sayfa açıldı mı" değil: parola ekranda görünüyor mu, gizli
 * kalması gereken bir şey sızıyor mu, dar ekranda yerleşim bozuluyor mu.
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

test('giris ekrani cizilir ve tema token\'lari uygulanir', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('.gate__mark')).toHaveText('EYS');

    // Stil yüklenmediyse zemin saydam kalır; token'ın gerçekten uygulandığını görelim.
    const zemin = await page.evaluate(() =>
        getComputedStyle(document.body).backgroundColor);
    expect(zemin).not.toBe('rgba(0, 0, 0, 0)');

    await expect(page).toHaveScreenshot('giris.png', { maxDiffPixelRatio: 0.02 });
});

test('oturumsuz erisim listesi girise yonlenir', async ({ page }) => {
    await page.goto('/access');
    await expect(page).toHaveURL(/login/);
});

test('liste uc sutun olarak cizilir', async ({ page }, testInfo) => {
    await girisYap(page);

    await expect(page.locator('.column').first()).toBeVisible();

    /*
     * Sütun başlığı telefonda BİLİNÇLİ olarak gizlenir (credential-row.css,
     * 720px altı): dar ekranda satırlar yığılıyor ve başlık hiçbir sütuna
     * denk gelmiyor. Beklenti cihaza göre değişir.
     */
    const dar = (page.viewportSize()?.width ?? 0) <= 720;

    if (dar) {
        await expect(page.locator('.credential-row__head')).toBeHidden();
    } else {
        await expect(page.locator('.credential-row__head')).toBeVisible();
    }

    // Gruplardan biri seçilip kaydı açılır.
    await page.locator('.row-item').first().click();
    await page.locator('.grid-row').first().click();

    await expect(page.locator('.detail')).toBeVisible();
});

test('parola once gizli, gostere basinca aciliyor', async ({ page }) => {
    await girisYap(page);

    /*
     * Parolası OLAN bir kayıt ADIYLA seçilir. Listenin ilk satırını almak
     * kırılgandır: grup listesinin başına "Hepsi" gibi bir satır eklenince
     * ilk satır parolasız bir kayda denk gelir ve test sessizce ATLANIR —
     * yani kapsam, kimse fark etmeden düşer.
     */
    await page.getByRole('link', { name: /^Hepsi/ }).click();
    await page.locator('.credential-row__name', { hasText: 'srv-a SSH' }).click();

    const kutu = page.locator('[data-secret]');

    await expect(kutu).toBeVisible();

    await expect(kutu.locator('[data-secret-value]')).toHaveText('••••••••');
    await expect(kutu.locator('[data-secret-copy]')).toBeHidden();

    await kutu.locator('[data-secret-show]').click();

    await expect(kutu.locator('[data-secret-value]')).not.toHaveText('••••••••');
    await expect(kutu.locator('[data-secret-copy]')).toBeVisible();
});

test('tema degistirilebilir', async ({ page }) => {
    await girisYap(page);

    await page.click('[data-theme-toggle]');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

    await page.click('[data-theme-toggle]');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});

test('sayfa yatay kaymaz', async ({ page }) => {
    await girisYap(page);

    const tasma = await page.evaluate(() =>
        document.documentElement.scrollWidth - document.documentElement.clientWidth);

    expect(tasma).toBeLessThanOrEqual(1);
});

test('listenin son satiri ekranin dibine yapismaz', async ({ page }) => {
    await girisYap(page);

    /*
     * Son satır pencerenin en dibindeyken tarayıcı, bağlantı adresini tam
     * oraya basıyor ve satırın üstünü kapatıyordu. Kaydırmanın sonunda bir
     * boşluk kalmalı.
     */
    const govde = page.locator('.column--fill .column__body');
    await govde.evaluate((el) => el.scrollTo(0, el.scrollHeight));

    const sonSatir = await page.locator('.grid-row').last().boundingBox();
    const alan = await govde.boundingBox();

    expect(alan.y + alan.height - (sonSatir.y + sonSatir.height)).toBeGreaterThan(24);
});

/**
 * Portal oturumu: SEKME ONAYDAN SONRA açılır.
 *
 * Fırlatma sekmesi onay penceresinden önce açılırsa kullanıcı düğmeye
 * basar basmaz boş bir sekme açılır ve vazgeçse bile ortada kalır.
 */
test('portal sekmesi onay verilmeden acilmaz', async ({ page, context }) => {
    await girisYap(page);

    await page.locator('.row-item').first().click();
    await page.locator('.grid-row', { hasText: 'iLO Deneme' }).click();

    const dugme = page.locator('form[data-portal-landing] button[type="submit"]');
    await expect(dugme).toBeVisible();

    await dugme.click();

    // Onay penceresi açıldı...
    await expect(page.locator('[data-confirm-dialog]')).toBeVisible();

    // ...ama sekme AÇILMADI.
    expect(context.pages()).toHaveLength(1);

    // Vazgeçildiğinde de açılmamalı.
    await page.click('[data-confirm-cancel]');
    expect(context.pages()).toHaveLength(1);
});
