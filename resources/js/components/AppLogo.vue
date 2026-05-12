<script setup lang="ts">
/*
 * Brand logo. PNG at public/img/smc_logo.png is 1401 × 326 (~4.3:1).
 *
 * Render contract for the header:
 *   - h-14 inside the h-16 header row, with py-1 → 4px breathing
 *     space top + bottom around the image.
 *   - w-auto derives width from intrinsic aspect.
 *   - shrink-0 stops the parent flex container from compressing it
 *     under any width pressure (the recurring bug).
 *   - object-contain + explicit width/height attrs are belt + braces:
 *     the browser knows the aspect ratio at first paint, and any box
 *     it lands in letterboxes instead of squashing.
 *
 * The fixed-width parent slot (Link with w-72 shrink-0 in AppHeader.vue)
 * is the other half of the contract — without it the image renders
 * fine but the flex row can still squeeze the surrounding box.
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
