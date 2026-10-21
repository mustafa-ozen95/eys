import { expect, test } from '@playwright/test';

/**
 * Gece/gündüz seçimi BÜTÜN PANELLERDE ortaktır.
 *
 * Seçim çerezde ve üst alan adına yazılıyor: merkez ile paneller ayrı
 * köklerdir ve tarayıcı depolaması kök başınadır — depoda dursaydı birinde
 * yapılan seçim diğerinde görünmezdi.
 *
 * Hiç seçim yapılmadığında TARAYICININ tercihi geçerlidir.
 */
const tema = (page) => page.evaluate(() => document.documentElement.dataset.theme);

test('bir panelde yapılan seçim diğerlerinde de geçerli', async ({ page }) => {
    await page.goto('https://eys.ornek.test/giris');

    const dugme = page.locator('[data-theme-toggle]');
    await expect(dugme).toBeVisible();

    await dugme.click();
    const secilen = await tema(page);
    console.log('### EYS giriş ekranında seçildi:', secilen);

    for (const [ad, adres] of [
        ['NYS', 'https://panel-b.ornek.test/giris'],
        ['KYM', 'https://merkez.ornek.test/'],
    ]) {
        await page.goto(adres);
        const gorulen = await tema(page);
        console.log(`### ${ad}: ${gorulen}`);
        expect(gorulen, `${ad} seçimi izlemedi`).toBe(secilen);
    }

    /* Ters yön: merkezde değiştir, panelde görünsün. */
    await page.goto('https://merkez.ornek.test/');
    await page.locator('#theme').click();
    const merkezde = await tema(page);
    console.log('### merkezde değiştirildi:', merkezde);

    await page.goto('https://eys.ornek.test/giris');
    console.log('### EYS:', await tema(page));
    expect(await tema(page)).toBe(merkezde);
});

test('seçim yokken tarayıcının tercihi izlenir', async ({ browser }) => {
    for (const [tercih, beklenen] of [['light', 'light'], ['dark', 'dark']]) {
        const baglam = await browser.newContext({ colorScheme: tercih });
        const sayfa = await baglam.newPage();

        await sayfa.goto('https://eys.ornek.test/giris');
        const gorulen = await sayfa.evaluate(() => document.documentElement.dataset.theme);
        console.log(`### tarayıcı ${tercih} → panel ${gorulen}`);

        expect(gorulen).toBe(beklenen);
        await baglam.close();
    }
});
