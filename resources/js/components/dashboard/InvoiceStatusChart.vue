<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import { formatMoney } from '@/lib/format';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Doughnut } from 'vue-chartjs';
import './chartSetup';
import ChartCard from './ChartCard.vue';
import type { StatusSlice } from './types';

const props = defineProps<{ data: StatusSlice[] }>();

const theme = useChartTheme();

const isEmpty = computed(() => props.data.every((slice) => slice.count === 0));

const chartData = computed<ChartData<'doughnut'>>(() => ({
    labels: props.data.map((slice) => slice.label),
    datasets: [
        {
            data: props.data.map((slice) => slice.count),
            backgroundColor: props.data.map((slice) => theme.value.status[slice.key]),
            borderColor: theme.value.surface,
            borderWidth: 2,
        },
    ],
}));

const options = computed<ChartOptions<'doughnut'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 300 },
    cutout: '62%',
    plugins: {
        legend: { position: 'bottom', labels: { color: theme.value.text, boxWidth: 12, boxHeight: 12 } },
        tooltip: {
            callbacks: {
                label: (ctx) => {
                    const slice = props.data[ctx.dataIndex];

                    return `${slice.label}: ${slice.count} invoice${slice.count === 1 ? '' : 's'}, ${formatMoney(slice.total_cents)}`;
                },
            },
        },
    },
}));
</script>

<template>
    <ChartCard title="Invoices by status" description="Issued invoices, same grouping as the status badges" :empty="isEmpty" empty-text="No issued invoices yet.">
        <Doughnut :data="chartData" :options="options" />
    </ChartCard>
</template>
