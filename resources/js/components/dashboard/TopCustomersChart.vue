<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import { formatMoney, formatMoneyCompact } from '@/lib/format';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import './chartSetup';
import ChartCard from './ChartCard.vue';
import type { TopCustomer } from './types';

const props = defineProps<{ data: TopCustomer[] }>();

const theme = useChartTheme();

const chartData = computed<ChartData<'bar'>>(() => ({
    labels: props.data.map((customer) => customer.name),
    datasets: [{ label: 'Outstanding', data: props.data.map((customer) => customer.outstanding_cents), backgroundColor: theme.value.accent, borderRadius: 3 }],
}));

const options = computed<ChartOptions<'bar'>>(() => ({
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 300 },
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => {
                    const customer = props.data[ctx.dataIndex];

                    return `${formatMoney(customer.outstanding_cents)} on ${customer.invoice_count} invoice${customer.invoice_count === 1 ? '' : 's'}`;
                },
            },
        },
    },
    scales: {
        x: {
            beginAtZero: true,
            grid: { color: theme.value.grid },
            border: { display: false },
            ticks: { color: theme.value.text, callback: (value) => formatMoneyCompact(Number(value)) },
        },
        y: { grid: { display: false }, ticks: { color: theme.value.text } },
    },
}));
</script>

<template>
    <ChartCard title="Top customers by outstanding balance" description="Unpaid balance on issued invoices, top 5" :empty="data.length === 0" empty-text="Nobody owes anything right now.">
        <Bar :data="chartData" :options="options" />
    </ChartCard>
</template>
