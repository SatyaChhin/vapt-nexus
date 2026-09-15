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
        src="/npca_logo.png"
        alt="NPCA logo"
        :class="['rounded-full bg-white object-contain', className]"
        v-bind="$attrs"
    />
</template>
