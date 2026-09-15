<script setup lang="ts">
import { ArrowDown, ArrowUp, ChartLine, Table2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { cn } from '@/lib/utils';
import type { SeverityKey, TrendPoint } from '@/types';
import { SEVERITY_STYLES } from './severity';
import TrendPanel from './TrendPanel.vue';

/**
 * Open findings over time as small multiples, one panel per severity. The
 * Nessus severity colours are too close to tell apart as lines on one plot,
 * so each severity gets its own labelled panel instead. Info is left out:
 * its volume says nothing about risk.
 */
const props = defineProps<{ points: TrendPoint[] }>();

const KEYS: SeverityKey[] = ['critical', 'high', 'medium', 'low'];
const RANGES = [
    { days: 30, label: '30 days' },
    { days: 90, label: '90 days' },
    { days: 365, label: '1 year' },
];
const DAY_MS = 86_400_000;

const range = ref(30);
const showTable = ref(false);
const hovered = ref<number | null>(null);

const shortDate = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const longDate = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeZone: 'UTC',
});

function parse(date: string): number {
    return Date.parse(`${date}T00:00:00Z`);
}

/** Every day in the window, oldest first; null before recording started. */
const days = computed(() => {
    const last = props.points.at(-1);

    if (!last) {
        return [];
    }

    const end = parse(last.date);
    const byDate = new Map(props.points.map((p) => [p.date, p]));

    return Array.from({ length: range.value }, (_, i) => {
        const date = new Date(end - (range.value - 1 - i) * DAY_MS)
            .toISOString()
            .slice(0, 10);

        return { date, point: byDate.get(date) ?? null };
    });
});

const firstRecorded = computed(() =>
    days.value.findIndex((day) => day.point !== null),
);

type Panel = {
    key: SeverityKey;
    values: (number | null)[];
    /** The hovered day's value, or the latest. */
    value: number | null;
    caption: string;
    /** Latest minus the first recorded day in range; null while hovering. */
    change: number | null;
};

const panels = computed<Panel[]>(() => {
    const start = days.value[firstRecorded.value];
    const onlyLastDay = firstRecorded.value === days.value.length - 1;
    const hoveredDay =
        hovered.value === null ? null : days.value[hovered.value];

    return KEYS.map((key) => {
        const values = days.value.map((day) => day.point?.[key] ?? null);

        if (hoveredDay) {
            return {
                key,
                values,
                value: hoveredDay.point?.[key] ?? null,
                caption: longDate.format(parse(hoveredDay.date)),
                change: null,
            };
        }

        const latest = values.at(-1) ?? 0;

        return {
            key,
            values,
            value: latest,
            caption:
                !start || onlyLastDay
                    ? 'No earlier data in this range'
                    : `since ${shortDate.format(parse(start.date))}`,
            change:
                !start || onlyLastDay
                    ? null
                    : latest - (start.point?.[key] ?? 0),
        };
    });
});

const tableRows = computed(() =>
    days.value
        .filter((day) => day.point !== null)
        .reverse()
        .map((day) => ({ date: day.date, point: day.point as TrendPoint })),
);
</script>

<template>
    <section class="rounded-xl border p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-medium">Open findings over time</h3>
                <p class="text-muted-foreground mt-0.5 text-xs">
                    <template v-if="points.length === 0">
                        No history yet.
                    </template>
                    <template v-else>
                        Recorded daily since
                        {{ longDate.format(parse(points[0].date)) }} · Info
                        excluded · Each panel has its own scale
                    </template>
                </p>
            </div>

            <div v-if="points.length > 0" class="flex items-center gap-2">
                <div
                    class="inline-flex rounded-md border p-0.5"
                    role="group"
                    aria-label="Date range"
                >
                    <button
                        v-for="option in RANGES"
                        :key="option.days"
                        type="button"
                        :aria-pressed="range === option.days"
                        :class="
                            cn(
                                'rounded px-2.5 py-1 text-xs font-medium transition-colors',
                                range === option.days
                                    ? 'bg-foreground text-background'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="
                            range = option.days;
                            hovered = null;
                        "
                    >
                        {{ option.label }}
                    </button>
                </div>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground hover:bg-muted inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors"
                    :aria-pressed="showTable"
                    @click="showTable = !showTable"
                >
                    <ChartLine v-if="showTable" class="size-3.5" />
                    <Table2 v-else class="size-3.5" />
                    {{ showTable ? 'Chart' : 'Table' }}
                </button>
            </div>
        </div>

        <p
            v-if="points.length === 0"
            class="text-muted-foreground mt-4 rounded-lg border border-dashed p-6 text-center text-sm"
        >
            The trend starts with the next scan import, or tonight's daily
            snapshot. Earlier history can't be rebuilt, because re-imports
            replace old findings.
        </p>

        <!-- Table view: the same numbers, no pointer needed -->
        <div
            v-else-if="showTable"
            class="mt-4 max-h-80 overflow-auto rounded-lg border"
        >
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/50 text-muted-foreground sticky top-0 text-left"
                >
                    <tr>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th
                            v-for="key in KEYS"
                            :key="key"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ SEVERITY_STYLES[key].label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="row in tableRows" :key="row.date">
                        <td class="px-3 py-1.5 whitespace-nowrap">
                            {{ longDate.format(parse(row.date)) }}
                        </td>
                        <td
                            v-for="key in KEYS"
                            :key="key"
                            class="px-3 py-1.5 text-right tabular-nums"
                        >
                            {{ row.point[key] }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="mt-4 grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="panel in panels" :key="panel.key" class="min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                    <span
                        class="text-muted-foreground flex items-center gap-1.5 text-xs font-medium"
                    >
                        <span
                            :class="
                                cn(
                                    'h-0.5 w-3 rounded-full',
                                    SEVERITY_STYLES[panel.key].bar,
                                )
                            "
                            aria-hidden="true"
                        />
                        {{ SEVERITY_STYLES[panel.key].label }}
                    </span>
                    <span class="text-muted-foreground truncate text-xs">
                        {{ panel.caption }}
                    </span>
                </div>

                <div class="mt-1 flex items-baseline gap-2">
                    <span class="text-2xl leading-none font-semibold">
                        {{ panel.value ?? '—' }}
                    </span>
                    <!-- Fewer open findings is the good direction -->
                    <span
                        v-if="panel.change !== null && panel.change < 0"
                        class="inline-flex items-center gap-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400"
                    >
                        <ArrowDown class="size-3" aria-hidden="true" />
                        {{ Math.abs(panel.change) }} fewer
                    </span>
                    <span
                        v-else-if="panel.change !== null && panel.change > 0"
                        class="inline-flex items-center gap-0.5 text-xs font-medium text-red-700 dark:text-red-400"
                    >
                        <ArrowUp class="size-3" aria-hidden="true" />
                        {{ panel.change }} more
                    </span>
                    <span
                        v-else-if="panel.change === 0"
                        class="text-muted-foreground text-xs"
                    >
                        No change
                    </span>
                </div>

                <TrendPanel
                    v-model:hovered="hovered"
                    class="mt-2"
                    :severity="panel.key"
                    :values="panel.values"
                />

                <div
                    class="text-muted-foreground mt-1 flex justify-between pl-[30px] text-[10px]"
                >
                    <span>{{
                        shortDate.format(parse(days[0].date))
                    }}</span>
                    <span>{{
                        shortDate.format(parse(days[days.length - 1].date))
                    }}</span>
                </div>
            </div>
        </div>
    </section>
</template>
