<script setup lang="ts">
/*
 * Same brand PNG as AppLogo (1401 × 326). This component takes a class
 * prop so callers can pick their own height (auth screens use h-12,
 * the mobile sheet uses h-8). Intrinsic width/height attrs + object-
 * contain protect against any flex parent trying to squeeze the image
 * into a square.
 */
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/img/smc_logo.png"
        alt="Salt Mine Compliance"
        width="1401"
        height="326"
        :class="['object-contain', className]"
        v-bind="$attrs"
    />
</template>
