import { expect, test } from '@playwright/test';

/**
 * Sistem güncellemeleri ekranı.
 *
 * Sorulanlar: solda Hepsi ve gruplar, makineye tıklayınca sağda kart ve
 * güncelleme geçmişi açılıyor mu, kilitli makinenin kutusu kapalı mı, seçim sayısı doğru mu,
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

test('solda gruplar ve Hepsi, makineye tiklayinca sagda kart ve gecmis', async ({ page }) => {
    await girisYap(page);
    await page.goto('/system-updates');

    const gruplar = page.locator('.column').first();
    await expect(gruplar.locator('.row-item').first()).toContainText('Hepsi');
    await expect(gruplar.locator('.row-item[aria-selected="true"]')).toContainText('Hepsi');

    /* Gruba tıklayınca orta liste daralır. */
    const grup = gruplar.locator('.row-item').nth(1);
    const grupAdi = (await grup.locator('.row-item__label').textContent()).trim();
    await grup.click();
    await expect(page.locator('.column--fill .column__head .eyebrow')).toHaveText(grupAdi);

    await page.goto('/system-updates?grup=hepsi');
    const satir = page.locator('.os-update-row', { hasText: 'srv-a SSH' }).filter({ hasNotText: 'Yedek' });
    await satir.locator('.os-update-row__link').click();

    const kart = page.locator('aside.detail');
    await expect(kart).toBeVisible();
    await expect(kart.locator('.detail__title')).toHaveText('srv-a SSH');
    await expect(kart.getByText('Güncelleme geçmişi')).toBeVisible();
    await expect(kart.getByText('2 kritik')).toBeVisible();
    await expect(page.locator('.os-update-row[aria-selected="true"]')).toContainText('srv-a SSH');
});

test('kilitli makine secilemez, secim sayaci ve kurulum onayi', async ({ page }) => {
    await girisYap(page);
    /* ?grup= ile gelinince dar ekranda da liste bölmesi açılır (columns.js). */
    await page.goto('/system-updates?grup=hepsi');
    await expect(page).toHaveTitle(/Sistem güncellemeleri/);

    const kritikSatir = page.locator('.os-update-row', { hasText: 'srv-a SSH' }).filter({ hasNotText: 'Yedek' });
    await expect(kritikSatir.getByText('2 kritik')).toBeVisible();
    await expect(kritikSatir.getByText('5 normal')).toBeVisible();
    await expect(kritikSatir.getByText('Yeniden başlatma gerekli')).toBeVisible();

    const kilitliSatir = page.locator('.os-update-row', { hasText: 'srv-a Yedek SSH' });
    await expect(kilitliSatir.getByText('Kilitli')).toBeVisible();
    await expect(kilitliSatir.locator('input[type="checkbox"]')).toBeDisabled();
    await expect(kilitliSatir.locator('[data-bulk-item]')).toHaveCount(0);

    /* Dar ekranda toplu araçlar düğmenin arkasında. */
    const toplu = page.locator('.bulk-tools__button');
    if (await toplu.isVisible()) {
        await toplu.click();
    }

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
