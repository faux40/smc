<script setup lang="ts">
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
        :class="className"
        v-bind="$attrs"
    />
</template>
