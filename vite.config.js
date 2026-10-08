import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/document.css', 'resources/js/document.js'],
            refresh: true,
            fonts: [
                bunny('Nunito', { weights: [400, 600, 700, 800] }),
                bunny('Rubik', { weights: [500, 600, 700] }),
            ],
        }),
        tailwindcss(),
    ],
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
});
