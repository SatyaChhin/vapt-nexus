<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

defineProps<{
    title: string;
    description?: string;
    icon?: LucideIcon;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center rounded-xl border border-dashed px-6 py-10 text-center"
    >
        <component
            :is="icon"
            v-if="icon"
            class="text-muted-foreground mb-3 size-8"
        />
        <p class="font-medium">{{ title }}</p>
        <p
            v-if="description"
            class="text-muted-foreground mt-1 max-w-md text-sm"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
