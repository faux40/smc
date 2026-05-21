import { createInertiaApp, router } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import ErrorBoundary from '@/components/ErrorBoundary.vue';
import { initializeTheme } from '@/composables/useAppearance';
import { realtimeTabId } from '@/echo';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
    setup({ el, App, props, plugin }) {
        if (!el) {
            throw new Error(
                'Inertia mount element not found — the #app root is missing from the page.',
            );
        }

        createApp({
            render: () =>
                h(ErrorBoundary, () => h(App as DefineComponent, props)),
        })
            .use(plugin)
            .use(createPinia())
            .mount(el);
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Stamp every Inertia visit with the per-tab UUID so backend broadcasts
// can echo it back and the originating tab self-filters. Raw fetch and
// Pinia store calls should add this header explicitly.
router.on('before', (event) => {
    const visit = event.detail.visit as { headers?: Record<string, string> };
    visit.headers = {
        ...(visit.headers ?? {}),
        'X-Origin-Tab': realtimeTabId(),
    };
});
