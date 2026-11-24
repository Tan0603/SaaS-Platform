<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import { formatMoney, formatMoneyCompact } from '@/lib/format';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import './chartSetup';
import ChartCard from './ChartCard.vue';
import type { MonthlyChart } from './types';

const props = defineProps<{ data: MonthlyChart }>();

const theme = useChartTheme();

const isEmpty = computed(() => [...props.data.invoiced_cents, ...props.data.collected_cents].every((cents) => cents === 0));

const chartData = computed<ChartData<'bar'>>(() => ({
    labels: props.data.labels,
    datasets: [
        { label: 'Invoiced', data: props.data.invoiced_cents, backgroundColor: theme.value.series.invoiced, borderRadius: 3 },
        { label: 'Collected', data: props.data.collected_cents, backgroundColor: theme.value.series.collected, borderRadius: 3 },
    ],
}));

const options = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 300 },
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { position: 'bottom', labels: { color: theme.value.text, boxWidth: 12, boxHeight: 12 } },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y)}` } },
    },
    scales: {
        x: { grid: { display: false }, ticks: { color: theme.value.text, maxRotation: 45, autoSkip: false } },
        y: {
            beginAtZero: true,
            grid: { color: theme.value.grid },
            border: { display: false },
            ticks: { color: theme.value.text, callback: (value) => formatMoneyCompact(Number(value)) },
        },
    },
}));
</script>

<template>
    <ChartCard title="Invoiced vs collected" description="Last 12 months: issued invoices by issue date, payments by payment date" :empty="isEmpty" empty-text="No invoices or payments in the last 12 months.">
        <Bar :data="chartData" :options="options" />
    </ChartCard>
</template>
