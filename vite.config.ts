/// <reference types="vitest/config" />
import { fileURLToPath } from 'node:url';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

const inDocker = !!process.env.VITE_DOCKER;

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        globals: true,
        environment: 'happy-dom',
        include: ['resources/js/**/*.{test,spec}.ts'],
        setupFiles: ['resources/js/__tests__/setup.ts'],
        css: false,
    },
    server: {
        origin: inDocker
            ? (process.env.VITE_DEV_SERVER_ORIGIN ?? 'http://localhost:5173')
            : 'http://localhost:5173',
        cors: true,
        hmr: {
            host: 'localhost',
            clientPort: inDocker
                ? parseInt(process.env.VITE_HMR_PORT ?? '5173')
                : 5173,
        },
        ...(inDocker ? { host: '0.0.0.0' } : {}),
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
