import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/legacy/landing.css', 'resources/js/legacy/landing.js', 'resources/css/legacy/login.css', 'resources/js/legacy/login.js', 'resources/css/legacy/catalog.css', 'resources/js/legacy/catalog.js', 'resources/css/legacy/product.css', 'resources/js/legacy/product.js', 'resources/css/legacy/compare.css', 'resources/js/legacy/compare.js', 'resources/css/legacy/mfa-pos.css', 'resources/js/legacy/mfa-pos.js', 'resources/css/legacy/mfa-hrms.css', 'resources/js/legacy/mfa-hrms.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
