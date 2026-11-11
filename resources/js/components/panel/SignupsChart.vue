<script setup lang="ts">
import { barY, defineChart } from '@tanstack/charts';
import { scaleBand } from '@tanstack/charts/scales/band';
import { scaleLinear } from '@tanstack/charts/scales/linear';
import { tooltip } from '@tanstack/charts/tooltip';
import { Chart } from '@tanstack/charts/vue';
import { computed } from 'vue';
import type { SignupDay } from '@/types';

const props = defineProps<{
    signups: SignupDay[];
}>();

// The server sends UTC calendar days; formatting in UTC keeps the label on that day.
const dayLabel = new Intl.DateTimeFormat('es-CL', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

const rows = computed(() =>
    props.signups.map((day) => ({
        day: dayLabel.format(new Date(`${day.date}T00:00:00Z`)),
        altas: day.total,
    })),
);

const definition = computed(() => {
    const data = rows.value;
    // A floor on the domain keeps one sign-up from filling the whole plot.
    const top = Math.max(4, ...data.map((row) => row.altas));

    return defineChart(
        {
            marks: [
                barY(data, {
                    id: 'signups',
                    x: 'day',
                    y: 'altas',
                    inset: 2,
                    radius: { end: 5 },
                    maxThickness: 36,
                }),
            ],
            scales: {
                x: {
                    scale: () =>
                        scaleBand<string>()
                            .domain(data.map((row) => row.day))
                            .padding(0.2),
                },
                y: {
                    scale: () => scaleLinear().domain([0, top]),
                    nice: true,
                    grid: true,
                    axis: {
                        ticks: {
                            format: (value: number) =>
                                Number.isInteger(value) ? String(value) : '',
                        },
                    },
                },
            },
        },
        { tooltip },
    );
});
</script>

<template>
    <Chart
        :definition="definition"
        aria-label="Altas de usuarios por día"
        :height="260"
        :initial-width="720"
        class="chart-surface"
    />
</template>
