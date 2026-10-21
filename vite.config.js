import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

/*
 * Tasarım düz CSS ile yazılmıştır (resources/css/tokens.css → components/*).
 * Tailwind kullanılmaz: token katmanı zaten tek doğruluk kaynağı ve iki ayrı
 * stil sistemi bir arada tutulamaz.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
