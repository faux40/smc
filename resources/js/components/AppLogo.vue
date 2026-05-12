<script setup lang="ts">
/*
 * Brand logo. The PNG at public/img/smc_logo.png is 1401 × 326 — a wide
 * ~4.3:1 banner. Render rules:
 *   - intrinsic width + height attrs so the browser reserves the right
 *     aspect ratio at first paint (no CLS, no square shrink while CSS
 *     resolves).
 *   - h-12 (48px) inside a h-16 (64px) header → 8px breathing room top
 *     and bottom.
 *   - object-contain belt-and-suspenders so any parent that flex-shrinks
 *     the image still gets letterboxed instead of squashed into a square.
 */
</script>

<template>
    <img
        src="/img/smc_logo.png"
        alt="Salt Mine Compliance"
        width="1401"
        height="326"
        class="h-12 w-auto object-contain"
    />
</template>
