import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin-tools.js', 'resources/js/tools/team-app-stats.js', 'resources/js/tools/asp-tracker.js', 'resources/js/tools/team-sponsorship.js', 'resources/js/tools/team-sponsorship-stats.js', 'resources/js/tools/listings.js'],
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
