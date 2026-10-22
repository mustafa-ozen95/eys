import { expect, test } from '@playwright/test';

/**
 * Ekleme ve düzenleme formu ARTIK AYRI SAYFA DEĞİL.
 *
 * Sorulan soru "pencere açılıyor mu" değil: liste ekranda kalıyor mu, doğrulama
 * hatasından sonra form kapanıp yazılanları yutuyor mu, dar ekranda pencere
 * taşıyor mu.
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

test('yeni kayit penceresi sayfadan ayrilmadan acilir', async ({ page }) => {
    await girisYap(page);

    const adres = page.url();
    await page.getByRole('link', { name: 'Yeni kayıt' }).click();

    const pencere = page.locator('#record-add');
    await expect(pencere).toBeVisible();

    // Liste yerinde: sayfa değişmedi, arkada duruyor.
    expect(page.url()).toBe(adres);
    await expect(page.locator('.column').first()).toBeVisible();

    // Pencere ekranı taşırmaz.
    const tasma = await page.evaluate(() =>
        document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(tasma).toBeLessThanOrEqual(1);

    /*
     * Bildirim şeridi MASKELENİR: üstte beliriyor ve metnin uzunluğuna göre
     * saniyelerce duruyor; önceki testten kalan bir bildirim karşılaştırmayı
     * kararsız yapıyordu.
     */
    await expect(page).toHaveScreenshot('kayit-ekle-penceresi.png', {
        maxDiffPixelRatio: 0.02,
        mask: [page.locator('.toast-host')],
    });

    await page.keyboard.press('Escape');
    await expect(pencere).toBeHidden();
});

test('dogrulama hatasi pencereyi acik geri getirir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: 'Yeni kayıt' }).click();

    /*
     * Hata SUNUCUDAN gelmeli: tarayıcının kendi "bu alanı doldurun" uyarısı
     * formu hiç göndermez ve asıl sınanmak istenen yol (hatadan sonra pencere
     * geri açılıyor mu) hiç çalışmazdı. Bu yüzden alan boş bırakılmıyor,
     * yalnızca sunucunun tanımadığı bir değer yazılıyor.
     */
    const pencere = page.locator('#record-add');
    await pencere.locator('#name').fill('Gecici kayit');
    await pencere.locator('#host').fill('203.0.113.99');
    // Portal alanları yalnız web/panel türünde ve kutu işaretliyken görünür.
    await pencere.locator('#access_type').selectOption('web');
    await pencere.locator('[data-condition="portal_acik"]').check();
    await pencere.locator('#login_username_field').fill('gecersiz alan!');
    await pencere.getByRole('button', { name: 'Kaydet' }).click();

    // Sunucu listeye döner AMA pencere açık ve yazılanlar duruyor.
    await expect(page.locator('#record-add')).toBeVisible();
    await expect(page.locator('#record-add .alert--danger')).toBeVisible();
    await expect(page.locator('#record-add #host')).toHaveValue('203.0.113.99');
    await expect(page.locator('#record-add #name')).toHaveValue('Gecici kayit');

    /* Kayıt oluşmadı: liste eskisi gibi. */
    await expect(page.locator('.grid-row', { hasText: 'Gecici kayit' })).toHaveCount(0);
});

test('duzenleme penceresi kaydin kendi degerleriyle acilir', async ({ page }) => {
    await girisYap(page);

    await page.locator('.row-item').first().click();
    await page.locator('.grid-row').first().click();

    const ad = await page.locator('.detail__title').innerText();

    await page.getByRole('link', { name: 'Düzenle' }).click();

    const pencere = page.locator('#record-edit');
    await expect(pencere).toBeVisible();
    await expect(pencere.locator('#name')).toHaveValue(ad);

    /* Parola alanı boş gelir: kayıtlı parola forma BASILMAZ. */
    await expect(pencere.locator('#password')).toHaveValue('');
});

test('betik yokken pencere adresi ayni ekrani acik getirir', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();

    await page.goto('/login');

    /* Betik kapalı: <details> yine de başlığa tıklayınca açılır. */
    await parolaFormunuAc(page);

    await page.fill('#email', EPOSTA);
    await page.fill('#password', PAROLA);
    await page.click('form button[type="submit"]');

    await page.goto('/access?pencere=ekle');

    /*
     * Betik olmadan pencere gizlenemez; sunucu onu zaten AÇIK basar.
     * Kullanıcı hiçbir durumda formsuz kalmaz.
     */
    await expect(page.locator('#record-add')).toHaveAttribute('data-modal-active', '');

    await context.close();
});

test('pencereden kaydedilen kayit listeye duser', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: 'Yeni kayıt' }).click();

    const pencere = page.locator('#record-add');
    await pencere.locator('#name').fill('Pencereden eklendi');
    await pencere.locator('#host').fill('203.0.113.98');
    await pencere.locator('#username').fill('root');
    await pencere.getByRole('button', { name: 'Kaydet' }).click();

    await expect(page.locator('.toast')).toContainText('Pencereden eklendi eklendi.');
    await expect(page.locator('#record-add')).toBeHidden();
    await expect(page.locator('.detail__title')).toHaveText('Pencereden eklendi');
});

test('pencereden guncellenen kayit listede degisir', async ({ page }) => {
    await girisYap(page);

    await page.locator('.row-item').first().click();
    await page.locator('.grid-row').first().click();
    await page.getByRole('link', { name: 'Düzenle' }).click();

    const pencere = page.locator('#record-edit');
    await pencere.locator('#platform').fill('Ubuntu 24');
    await pencere.getByRole('button', { name: 'Güncelle' }).click();

    await expect(page.locator('.toast')).toContainText('güncellendi.');
    await expect(page.locator('#record-edit')).toBeHidden();
    await expect(page.locator('.detail')).toContainText('Ubuntu 24');
});
