import { expect, test } from '@playwright/test';

const EPOSTA = process.env.E2E_EMAIL, PAROLA = process.env.E2E_PASSWORD;

test('uzak masaüstü oturumu açılır', async ({ page }) => {
    test.setTimeout(90000);

    await page.goto('/giris');
    const k = page.locator('details.gate__katlanir');
    if (await k.count() && await k.getAttribute('open') === null) await page.locator('summary.gate__katlanir-baslik').click();
    await page.fill('#email', EPOSTA);
    await page.fill('#password', PAROLA);
    await page.click('form button[type="submit"]');
    await expect(page).toHaveURL(/erisim/);

    await page.goto(process.env.E2E_ADRES);
    await expect(page.locator('.detail__title')).toBeVisible();
    console.log('### kayıt:', await page.locator('.detail__title').textContent());

    const [sekme] = await Promise.all([
        page.context().waitForEvent('page'),
        page.getByRole('button', { name: /Konsolu aç/i }).click(),
    ]);

    await sekme.waitForLoadState('domcontentloaded');
    console.log('### konsol adresi:', sekme.url());

    /* Guacamole tuvali görünürse oturum kurulmuş demektir. */
    await expect(sekme.locator('canvas').first()).toBeVisible({ timeout: 45000 });
    console.log('### tuval çizildi — RDP oturumu kuruldu');
});
