<script setup lang="ts">
import { Toaster as SonnerPrimitive } from 'vue-sonner';
import 'vue-sonner/style.css';

// Dark mode is disabled app-wide — toasts always render light.
</script>

<template>
    <SonnerPrimitive
        theme="light"
        class="toaster group"
        position="top-right"
        :offset="{ top: '4.5rem' }"
        rich-colors
        :style="{
            '--normal-bg': 'var(--popover)',
            '--normal-text': 'var(--popover-foreground)',
            '--normal-border': 'var(--border)',
        }"
    />
</template>
