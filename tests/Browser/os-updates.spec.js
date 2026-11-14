import { expect, test } from '@playwright/test';

/**
 * Sistem güncellemeleri ekranı.
 *
 * Sorulanlar: kilitli makinenin kutusu kapalı mı, seçim sayısı doğru mu,
 * onay penceresi KURULACAĞINI ve kaç makine olduğunu söylüyor mu, gerekçesiz
 * gönderilebiliyor mu, telefonda yatay taşma var mı.
 *
 * Veri VisualTestSeeder'dan: "srv-a SSH" kritik bekliyor, "srv-a Yedek SSH" kilitli.
 */
const EPOSTA = process.env.E2E_EMAIL;
const PAROLA = process.env.E2E_PASSWORD;

async function girisYap(page) {
    await page.goto('/login');

    const katlanir = page.locator('details.gate__collapsible');
    if (await katlanir.count() && await katlanir.getAttribute('open') === null) {
        await page.locator('summary.gate__collapsible-title').click();
    }

    await page.fill('#email', EPOSTA);
    await page.fill('#password', PAROLA);
    await page.click('form button[type="submit"]');
    await expect(page).toHaveURL(/access/);
}

test('kilitli makine secilemez, secim sayaci ve kurulum onayi', async ({ page }) => {
    await girisYap(page);
    await page.goto('/system-updates');

    await expect(page.locator('h1')).toHaveText('Sistem güncellemeleri');

    const kritikSatir = page.locator('tr', { hasText: 'srv-a SSH' }).filter({ hasNotText: 'Yedek' });
    await expect(kritikSatir.getByText('2 kritik')).toBeVisible();
    await expect(kritikSatir.getByText('5 normal')).toBeVisible();
    await expect(kritikSatir.getByText('Yeniden başlatma gerekli')).toBeVisible();

    const kilitliSatir = page.locator('tr', { hasText: 'srv-a Yedek SSH' });
    await expect(kilitliSatir.getByText('Üretim yedek sunucusu')).toBeVisible();
    await expect(kilitliSatir.locator('input[type="checkbox"]')).toBeDisabled();
    await expect(kilitliSatir.locator('[data-bulk-item]')).toHaveCount(0);

    const dugme = page.locator('[data-bulk-submit]');
    await expect(dugme).toBeDisabled();

    await kritikSatir.locator('[data-bulk-item]').check();
    await expect(page.locator('[data-bulk-count]')).toHaveText('1');
    await expect(dugme).toBeEnabled();

    await dugme.click();

    const pencere = page.locator('[data-confirm-dialog]');
    await expect(pencere).toBeVisible();
    await expect(pencere.locator('[data-confirm-message]')).toContainText('srv-a SSH makinesine sistem güncellemesi KURULACAK');

    /* Gerekçe yazılmadan onay düğmesi kapalı. */
    await expect(pencere.locator('[data-confirm-accept]')).toBeDisabled();
    await pencere.getByRole('button', { name: 'Vazgeç' }).click();
    await expect(pencere).toBeHidden();
});

test('dar ekranda yatay tasma yok', async ({ page }) => {
    await girisYap(page);
    await page.goto('/system-updates');

    for (const genislik of [320, 390, 820, 1280]) {
        await page.setViewportSize({ width: genislik, height: 900 });
        const tasma = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(tasma, `${genislik}px genişlikte yatay taşma`).toBeLessThanOrEqual(0);
    }
});
