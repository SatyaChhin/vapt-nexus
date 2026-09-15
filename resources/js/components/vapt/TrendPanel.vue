<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, useTemplateRef } from 'vue';
import { cn } from '@/lib/utils';
import type { SeverityKey } from '@/types';
import { SEVERITY_STYLES } from './severity';

/**
 * One severity's open findings over the window, as a 2px line over a light
 * wash. Each panel has its own y-scale (labelled with its maximum), so a
 * change of 2 criticals stays visible next to hundreds of mediums.
 */
const props = defineProps<{
    severity: SeverityKey;
    /** Null before the first recorded day. */
    values: (number | null)[];
}>();

/** Index of the day under the pointer, shared by all panels. */
const hovered = defineModel<number | null>('hovered', { required: true });

const HEIGHT = 96;
const PAD_TOP = 8;
const PAD_BOTTOM = 4;
const PLOT_LEFT = 30;
/** Room for the end marker (r 4 plus its 2px ring). */
const PAD_RIGHT = 7;

const container = useTemplateRef<HTMLElement>('container');
const { width } = useElementSize(container);

const style = computed(() => SEVERITY_STYLES[props.severity]);
const firstIndex = computed(() => props.values.findIndex((v) => v !== null));
const lastIndex = computed(() => props.values.length - 1);

function niceCeil(value: number): number {
    if (value <= 0) {
        return 1;
    }

    const power = 10 ** Math.floor(Math.log10(value));
    const step = [1, 2, 5, 10].find((f) => value <= f * power) ?? 10;

    return step * power;
}

const max = computed(() =>
    niceCeil(Math.max(0, ...props.values.map((v) => v ?? 0))),
);

const plotWidth = computed(() =>
    Math.max(0, width.value - PLOT_LEFT - PAD_RIGHT),
);

function x(index: number): number {
    const steps = Math.max(1, props.values.length - 1);

    return PLOT_LEFT + (index / steps) * plotWidth.value;
}

function y(value: number): number {
    return (
        PAD_TOP + (1 - value / max.value) * (HEIGHT - PAD_TOP - PAD_BOTTOM)
    );
}

const baseline = computed(() => y(0));

const line = computed(() => {
    if (firstIndex.value < 0) {
        return '';
    }

    return props.values
        .slice(firstIndex.value)
        .map((value, i) => {
            const index = firstIndex.value + i;

            return `${i === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(value ?? 0).toFixed(1)}`;
        })
        .join('');
});

const area = computed(() =>
    line.value === ''
        ? ''
        : `${line.value}L${x(lastIndex.value).toFixed(1)},${baseline.value}L${x(firstIndex.value).toFixed(1)},${baseline.value}Z`,
);

const hoveredValue = computed(() =>
    hovered.value === null ? null : (props.values[hovered.value] ?? null),
);

function indexAt(offsetX: number): number {
    const steps = props.values.length - 1;
    const ratio = plotWidth.value > 0 ? (offsetX - PLOT_LEFT) / plotWidth.value : 1;

    return Math.min(steps, Math.max(0, Math.round(ratio * steps)));
}

function onKey(event: KeyboardEvent) {
    const delta = { ArrowLeft: -1, ArrowRight: 1 }[event.key];

    if (delta === undefined) {
        return;
    }

    event.preventDefault();
    const start = hovered.value ?? lastIndex.value;
    hovered.value = Math.min(
        lastIndex.value,
        Math.max(Math.max(0, firstIndex.value), start + delta),
    );
}
</script>

<template>
    <div ref="container" class="w-full">
        <svg
            v-if="width > 0"
            :width="width"
            :height="HEIGHT"
            :viewBox="`0 0 ${width} ${HEIGHT}`"
            class="block touch-none outline-none"
            tabindex="0"
            role="img"
            :aria-label="`${style.label} open findings over time. Use the arrow keys to read each day.`"
            @pointermove="hovered = indexAt($event.offsetX)"
            @pointerleave="hovered = null"
            @keydown="onKey"
            @blur="hovered = null"
        >
            <!-- Recessive hairline grid: top of scale and baseline -->
            <line
                :x1="PLOT_LEFT"
                :x2="width - PAD_RIGHT"
                :y1="y(max) + 0.5"
                :y2="y(max) + 0.5"
                class="stroke-border"
                stroke-width="1"
            />
            <line
                :x1="PLOT_LEFT"
                :x2="width - PAD_RIGHT"
                :y1="baseline + 0.5"
                :y2="baseline + 0.5"
                class="stroke-border"
                stroke-width="1"
            />
            <text
                :x="PLOT_LEFT - 6"
                :y="y(max) + 4"
                text-anchor="end"
                class="fill-muted-foreground text-[10px] tabular-nums"
            >
                {{ max }}
            </text>
            <text
                :x="PLOT_LEFT - 6"
                :y="baseline + 4"
                text-anchor="end"
                class="fill-muted-foreground text-[10px] tabular-nums"
            >
                0
            </text>

            <path :d="area" :class="style.area" />
            <path
                :d="line"
                fill="none"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
                :class="style.stroke"
            />

            <!-- Crosshair on the hovered day -->
            <template v-if="hovered !== null">
                <line
                    :x1="x(hovered)"
                    :x2="x(hovered)"
                    :y1="PAD_TOP"
                    :y2="baseline"
                    class="stroke-foreground/30"
                    stroke-width="1"
                />
                <circle
                    v-if="hoveredValue !== null"
                    :cx="x(hovered)"
                    :cy="y(hoveredValue)"
                    r="4"
                    stroke-width="2"
                    :class="cn('stroke-background', style.dot)"
                />
            </template>

            <!-- End marker for the latest day -->
            <circle
                v-else-if="firstIndex >= 0"
                :cx="x(lastIndex)"
                :cy="y(values[lastIndex] ?? 0)"
                r="4"
                stroke-width="2"
                :class="cn('stroke-background', style.dot)"
            />
        </svg>
        <div v-else :style="{ height: `${HEIGHT}px` }" />
    </div>
</template>
