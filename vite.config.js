import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

/*
 * Vite builds ONLY the real-time bundle (Laravel Echo + pusher-js) into public/build.
 * Bootstrap, icons, Chart.js and our own JS/CSS are plain files in public/ (no build step).
 *
 *   npm ci && npm run build   -> production bundle
 *   npm run dev               -> hot reload while developing realtime.js
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/realtime.js'],
            refresh: false,
        }),
    ],
});
