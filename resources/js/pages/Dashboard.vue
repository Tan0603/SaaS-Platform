<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InvoicedCollectedChart from '@/components/dashboard/InvoicedCollectedChart.vue';
import InvoiceStatusChart from '@/components/dashboard/InvoiceStatusChart.vue';
import TopCustomersChart from '@/components/dashboard/TopCustomersChart.vue';
import type { DashboardCharts } from '@/components/dashboard/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface DashboardStats {
    month_revenue_cents: number;
    month_payments_count: number;
    outstanding_cents: number;
    outstanding_count: number;
    paid_cents: number;
    paid_count: number;
    overdue_cents: number;
    overdue_count: number;
    generated_at: string;
}

const props = defineProps<{
    stats: DashboardStats;
    charts: DashboardCharts;
    month: { from: string; to: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const isEmpty = computed(
    () => props.stats.outstanding_count + props.stats.paid_count + props.stats.month_payments_count === 0,
);

const cards = computed(() => [
    {
        label: 'Revenue this month',
        hint: 'Payments received this month',
        cents: props.stats.month_revenue_cents,
        count: props.stats.month_payments_count,
        unit: 'payment',
        href: route('payments.index', { from: props.month.from, to: props.month.to }),
    },
    {
        label: 'Outstanding',
        hint: 'Unpaid balance on issued invoices',
        cents: props.stats.outstanding_cents,
        count: props.stats.outstanding_count,
        unit: 'invoice',
        href: route('invoices.index'),
    },
    {
        label: 'Paid',
        hint: 'Total of fully paid invoices',
        cents: props.stats.paid_cents,
        count: props.stats.paid_count,
        unit: 'invoice',
        href: route('invoices.index', { status: 'paid' }),
    },
    {
        label: 'Overdue',
        hint: 'Unpaid balance past the due date',
        cents: props.stats.overdue_cents,
        count: props.stats.overdue_count,
        unit: 'invoice',
        href: route('invoices.index', { status: 'overdue' }),
    },
]);

const asOf = computed(() => new Date(props.stats.generated_at).toLocaleString('en-MY'));

// Live data: re-fetch only the dashboard props every 30 seconds (Inertia pauses polling while the tab is hidden).
// The server answers from its per-tenant cache, which is cleared as soon as an invoice or payment changes.
const checkedAt = ref(new Date());
const pollFailed = ref(false);
usePoll(30_000, {
    only: ['stats', 'charts', 'month'],
    onSuccess: () => {
        checkedAt.value = new Date();
        pollFailed.value = false;
    },
    onError: () => (pollFailed.value = true),
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <EmptyState v-if="isEmpty" title="No invoices yet" description="Add a customer, then create your first invoice. Your numbers will show up here.">
                <div class="flex gap-2">
                    <Button as-child variant="outline"><Link :href="route('customers.create')">Add customer</Link></Button>
                    <Button as-child><Link :href="route('invoices.create')">Create invoice</Link></Button>
                </div>
            </EmptyState>

            <template v-else>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Link v-for="card in cards" :key="card.label" :href="card.href" class="block">
                        <Card class="h-full transition-colors hover:bg-muted/50">
                            <CardHeader>
                                <CardDescription>{{ card.label }}</CardDescription>
                                <CardTitle class="text-2xl">{{ formatMoney(card.cents) }}</CardTitle>
                            </CardHeader>
                            <CardContent class="text-sm text-muted-foreground">
                                <p>{{ card.count }} {{ card.unit }}{{ card.count === 1 ? '' : 's' }}</p>
                                <p>{{ card.hint }}</p>
                            </CardContent>
                        </Card>
                    </Link>
                </div>
                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2"><InvoicedCollectedChart :data="charts.monthly" /></div>
                    <InvoiceStatusChart :data="charts.status" />
                </div>
                <TopCustomersChart :data="charts.top_customers" />

                <p class="text-xs text-muted-foreground">
                    Data as of {{ asOf }}. Checked for changes every 30 seconds (last check {{ checkedAt.toLocaleTimeString('en-MY') }}).
                    <span v-if="pollFailed" class="text-destructive">The last refresh failed; showing the previous numbers.</span>
                </p>
            </template>
        </div>
    </AppLayout>
</template>
