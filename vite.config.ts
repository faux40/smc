/// <reference types="vitest/config" />
import { fileURLToPath } from 'node:url';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    resolve: {
        // The app imports via `@/…` everywhere; make the alias explicit so
        // both the build and Vitest resolve it the same way.
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        // happy-dom over jsdom: faster, lighter, enough DOM for component
        // mounts. `globals` so specs read like Pest (describe/it/expect with
        // no imports). Specs live beside source as *.spec.ts / *.test.ts.
        globals: true,
        environment: 'happy-dom',
        include: ['resources/js/**/*.{test,spec}.ts'],
        setupFiles: ['resources/js/__tests__/setup.ts'],
        css: false,
    },
    server: {
        // Vite is started with `--host 0.0.0.0` inside Docker so the
        // container is reachable from the host. But we want the URLs
        // Vite writes into the page (assets, HMR client) to use
        // `localhost`, since that's what the browser can actually
        // reach. Harmless when running outside Docker — the browser
        // sees `localhost` either way.
        origin: 'http://localhost:5173',
        // Vite 6+ blocks cross-origin asset requests by default. The
        // Laravel app and Vite are on different ports in dev (8000 vs
        // 5173). `true` sends Access-Control-Allow-Origin: * — fine
        // for a local dev server.
        cors: true,
        hmr: {
            host: 'localhost',
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
