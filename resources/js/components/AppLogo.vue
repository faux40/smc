<script setup lang="ts">
/*
 * Brand logo. PNG at public/img/smc_logo.png is 1401 × 326 (~4.3:1).
 *
 * Render contract for the header:
 *   - h-16 matches the header height exactly (no breathing margin —
 *     fills the row).
 *   - w-auto derives width from intrinsic aspect.
 *   - shrink-0 stops the parent flex container from compressing it
 *     under any width pressure (the recurring bug).
 *   - object-contain + explicit width/height attrs are belt + braces:
 *     the browser knows the aspect ratio at first paint, and any box
 *     it lands in letterboxes instead of squashing.
 */
</script>

<template>
    <img
        src="/img/smc_logo.png"
        alt="Salt Mine Compliance"
        width="1401"
        height="326"
        class="h-14 py-1 w-auto shrink-0 object-contain"
    />
</template>
