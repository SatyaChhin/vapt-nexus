<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

defineProps<{
    label: string;
    value: number | string;
    icon?: LucideIcon;
    hint?: string;
}>();
</script>

<template>
    <div class="rounded-xl border p-4">
        <div
            class="text-muted-foreground flex items-center justify-between text-sm"
        >
            <span>{{ label }}</span>
            <component :is="icon" v-if="icon" class="size-4" />
        </div>
        <p class="mt-2 text-3xl font-semibold tabular-nums">{{ value }}</p>
        <p v-if="hint" class="text-muted-foreground mt-1 text-xs">
            {{ hint }}
        </p>
    </div>
</template>
