<script setup lang="ts">
import { cn } from '@/lib/utils';

defineProps<{
    options: { value: string; label: string }[];
    invalid?: boolean;
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <select
        v-model="model"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'border-input dark:bg-input/30 h-9 w-full rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none md:text-sm',
                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                'aria-invalid:border-destructive',
            )
        "
    >
        <option
            v-for="option in options"
            :key="option.value"
            :value="option.value"
        >
            {{ option.label }}
        </option>
    </select>
</template>
