import { defineConfig, devices } from '@playwright/test';

/*
 * Görsel testler CANLI adrese değil, kendi geliştirme sunucusuna bakar
 * (bkz. .ai/rules/work.md — canlı sisteme izinsiz bağlanılmaz).
 * Adres PLAYWRIGHT_BASE_URL ile değiştirilebilir.
 */
export default defineConfig({
    testDir: './tests/Browser',
    timeout: 30000,
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'masaustu', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
        { name: 'telefon', use: { ...devices['Pixel 7'] } },
    ],
});
