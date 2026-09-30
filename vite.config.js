import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// No Tailwind: the project keeps the template's Bootstrap 5.3 + design tokens (oppam-ui-standards).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/admin.css', 'resources/js/admin.js'],
            refresh: true,
        }),
    ],
});
