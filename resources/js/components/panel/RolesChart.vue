<script setup lang="ts">
import { barX, defineChart } from '@tanstack/charts';
import { scaleBand } from '@tanstack/charts/scales/band';
import { scaleLinear } from '@tanstack/charts/scales/linear';
import { tooltip } from '@tanstack/charts/tooltip';
import { Chart } from '@tanstack/charts/vue';
import { computed } from 'vue';
import type { RoleCount } from '@/types';

const props = defineProps<{
    roles: RoleCount[];
}>();

const definition = computed(() => {
    const data = props.roles.map((role) => ({
        rol: role.name,
        usuarios: role.users,
    }));
    const top = Math.max(4, ...data.map((row) => row.usuarios));

    return defineChart(
        {
            marks: [
                barX(data, {
                    id: 'roles',
                    x: 'usuarios',
                    y: 'rol',
                    inset: 3,
                    radius: { end: 5 },
                    maxThickness: 28,
                }),
            ],
            scales: {
                x: {
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
                y: {
                    scale: () =>
                        scaleBand<string>()
                            .domain(data.map((row) => row.rol))
                            .padding(0.25),
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
        aria-label="Usuarios por rol"
        :height="260"
        :initial-width="360"
        class="chart-surface"
    />
</template>
