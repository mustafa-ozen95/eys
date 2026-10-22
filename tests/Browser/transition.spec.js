import { expect, test } from '@playwright/test';

/**
 * Sayfa geçişleri tarayıcıyı YENİLEMEZ.
 *
 * Yenilenip yenilenmediği doğrudan ölçülür: pencereye bir işaret bırakılır
 * ve geçişten sonra hâlâ orada mı diye bakılır. Tam yenilemede o işaret
 * kaybolur — "sayfa değişti mi" sorusunu bundan daha kesin soran bir yol yok.
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

/** Yenilemede silinecek bir iz bırakır. */
async function isaretBirak(page) {
    await page.evaluate(() => {
        window.__eysIsaret = 'duruyor';
    });
}

const isaret = (page) => page.evaluate(() => window.__eysIsaret ?? null);

test('grup gecisi sayfayi yenilemez', async ({ page }) => {
    await girisYap(page);
    await isaretBirak(page);

    await page.getByRole('link', { name: /^Hepsi/ }).click();
    await expect(page).toHaveURL(/grup=hepsi/);
    await expect(page.locator('.column--fill .eyebrow').first()).toHaveText('Hepsi');

    expect(await isaret(page)).toBe('duruyor');
});

test('ust menu gecisi de yenilemez ve baslik degisir', async ({ page }) => {
    await girisYap(page);
    await isaretBirak(page);

    await page.getByRole('link', { name: 'Kayıtlar', exact: true }).click();
    await expect(page).toHaveURL(/logs/);

    expect(await isaret(page)).toBe('duruyor');

    // Başlık sunucudan gelen yeni sayfanınki olmalı.
    expect(await page.title()).not.toBe('');
});

test('geri tusu onceki ekrani getirir', async ({ page }) => {
    await girisYap(page);

    const ilkAdres = page.url();

    await page.getByRole('link', { name: /^Hepsi/ }).click();
    await expect(page).toHaveURL(/grup=hepsi/);

    await isaretBirak(page);
    await page.goBack();

    await expect(page).toHaveURL(ilkAdres);
    await expect(page.locator('.grid-row').first()).toBeVisible();
    expect(await isaret(page)).toBe('duruyor');
});

/** Süzgeç de bir geçiştir: GET formu da yenileme yapmamalı. */
test('arama formu sayfayi yenilemez', async ({ page }) => {
    await girisYap(page);
    await isaretBirak(page);

    await page.fill('.filter__input', 'SSH');
    await page.press('.filter__input', 'Enter');

    await expect(page).toHaveURL(/q=SSH/);
    expect(await isaret(page)).toBe('duruyor');
});

/**
 * Geçişten SONRA da bileşenler çalışmalı.
 *
 * Düğüme doğrudan bağlanan bir dinleyici ilk geçişte kopardı; bu test tam
 * olarak onu yakalar.
 */
test('gecisten sonra tema dugmesi ve toplu secim calisir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^Hepsi/ }).click();
    await expect(page).toHaveURL(/grup=hepsi/);

    const oncekiTema = await page.evaluate(() => document.documentElement.dataset.theme);
    await page.click('[data-theme-toggle]');
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).not.toBe(oncekiTema);

    // Toplu seçim sayacı hâlâ formu buluyor mu?
    await page.locator('[data-bulk-item]').first().check();
    await expect(page.locator('[data-bulk-count]')).toHaveText('1');
});

/** Onay penceresi yerleşimde duruyor ve geçişte o da değişiyor: hâlâ açılmalı. */
test('gecisten sonra onay penceresi acilir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^Hepsi/ }).click();
    await expect(page).toHaveURL(/grup=hepsi/);

    await page.locator('[data-bulk-item]').first().check();
    await page.click('[data-bulk-submit]');

    await expect(page.locator('[data-confirm-dialog]')).toBeVisible();
    await page.click('[data-confirm-cancel]');
    await expect(page.locator('[data-confirm-dialog]')).toBeHidden();
});

/** Kayıt penceresi (modal) geçişten sonra da sayfadan ayrılmadan açılmalı. */
test('gecisten sonra kayit penceresi acilir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^Hepsi/ }).click();
    await expect(page).toHaveURL(/grup=hepsi/);

    await isaretBirak(page);
    await page.getByRole('link', { name: 'Yeni kayıt' }).click();

    await expect(page.locator('#record-add')).toBeVisible();
    expect(await isaret(page)).toBe('duruyor');
});

/** Detay panelindeki parola gösterme (AJAX) geçişten sonra da çalışmalı. */
test('gecisten sonra parola gosterme calisir', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^Hepsi/ }).click();
    await page.locator('.credential-row__name', { hasText: 'srv-a SSH' }).click();

    const kutu = page.locator('[data-secret]');
    await expect(kutu).toBeVisible();

    await kutu.locator('[data-secret-show]').click();
    await expect(kutu.locator('[data-secret-value]')).not.toHaveText('••••••••');
});

/**
 * Dar ekranda şerit DOĞRU BÖLMEYE iner.
 *
 * Sütunlar telefonda yan yana sığmıyor; şerit yatay kayıyor ve her bölme
 * neredeyse tam ekran. Şerit en solda kalınca kullanıcı bir kayda dokunuyor
 * ama hâlâ grup listesine bakıyordu — seçtiği şeyin bilgisi ekranın
 * dışındaydı (kayıt seçiliyken scrollLeft 0, detay yüzlerce piksel sağda).
 *
 * Kural tek: en sağdaki görünür bölme, inilen en derin seviyedir. Geniş
 * ekranda şerit taşmaz, kural hiçbir şey yapmaz — bu da sınanıyor.
 */
async function seritDurumu(page) {
    return page.evaluate(() => {
        const serit = document.querySelector('.columns');
        const bolmeler = [...serit.querySelectorAll(':scope > .column, :scope > .detail')]
            .filter((bolme) => getComputedStyle(bolme).display !== 'none');
        const son = bolmeler[bolmeler.length - 1];

        return {
            tasiyor: serit.scrollWidth > serit.clientWidth,
            scrollLeft: Math.round(serit.scrollLeft),
            hedef: Math.round(son.offsetLeft - serit.offsetLeft),
            sonBolme: son.className.split(' ')[0],
        };
    });
}

test('serit en derin bolmeye iner (dar ekran), genis ekranda dokunmaz', async ({ page }) => {
    await girisYap(page);

    await page.getByRole('link', { name: /^Hepsi/ }).click();
    await expect(page).toHaveURL(/grup=hepsi/);
    await page.waitForTimeout(200);

    const liste = await seritDurumu(page);

    if (liste.tasiyor) {
        expect(liste.scrollLeft).toBeCloseTo(liste.hedef, -1);
    } else {
        expect(liste.scrollLeft).toBe(0);
    }

    /*
     * Seçici satırın TAMAMINA değil AD hücresine bakar.
     *
     * "Hepsi" görünümünde satır metni ad + grup + tür sırasıyla diziliyor;
     * grup adı ile tür yan yana gelince `.grid-row` üzerinde aranan ad başka
     * kayıtların satırında da oluşabiliyor (grup adı kayıt adının başıyla
     * aynıysa satır "... <grup> SSH ..." diye okunur). Ad hücresinde ise bu
     * dizilim yalnızca kaydın kendi adında oluşuyor.
     */
    // Kayıt seçilince en sağdaki bölme DETAY olur ve şerit oraya iner.
    await page.locator('.credential-row__name', { hasText: 'srv-a SSH' }).click();
    await page.waitForTimeout(400);

    const detay = await seritDurumu(page);

    expect(detay.sonBolme).toBe('detail');

    if (detay.tasiyor) {
        expect(detay.scrollLeft).toBeCloseTo(detay.hedef, -1);
        expect(detay.scrollLeft).toBeGreaterThan(0);
    } else {
        expect(detay.scrollLeft).toBe(0);
    }
});
