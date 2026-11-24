<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import type { BreadcrumbItem } from '@/types';
import type { Paginated } from '@/types/pagination';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Bucket {
    balance_cents: number;
    count: number;
}

interface OutstandingRow {
    id: number;
    number: string | null;
    customer: string;
    issue_date: string;
    due_date: string;
    total_cents: number;
    paid_cents: number;
    balance_cents: number;
    days_overdue: number;
    display_status: string;
}

const props = defineProps<{
    filters: { customer_id: number | ''; overdue_only: boolean };
    aging: Record<'current' | 'days_1_30' | 'days_31_60' | 'days_61_90' | 'days_90_plus' | 'total', Bucket>;
    invoices: Paginated<OutstandingRow>;
    customers: { id: number; name: string }[];
    errors: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Report', href: '/reports/sales' },
    { title: 'Outstanding Invoice Report', href: '/reports/outstanding' },
];

const buckets: { key: keyof typeof props.aging; label: string }[] = [
    { key: 'current', label: 'Current' },
    { key: 'days_1_30', label: '1-30 days' },
    { key: 'days_31_60', label: '31-60 days' },
    { key: 'days_61_90', label: '61-90 days' },
    { key: 'days_90_plus', label: '90+ days' },
    { key: 'total', label: 'Total' },
];

const customerId = ref(props.filters.customer_id);
const overdueOnly = ref(props.filters.overdue_only);
const loading = ref(false);
const loadError = ref(false);

function visit() {
    router.get(
        route('reports.outstanding'),
        { customer_id: customerId.value || undefined, overdue_only: overdueOnly.value ? 1 : undefined },
        {
            preserveState: true,
            replace: true,
            onStart: () => {
                loading.value = true;
                loadError.value = false;
            },
            onError: () => (loadError.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm';
</script>

<template>
    <Head title="Outstanding Invoice Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Outstanding Invoice Report</h1>
            <p class="text-sm text-muted-foreground">Issued invoices with a balance, aged by days past the due date. "Current" means not yet due.</p>

            <div class="flex flex-wrap items-start gap-3">
                <label class="grid gap-1 text-sm">
                    Customer
                    <select v-model="customerId" :class="selectClass" @change="visit">
                        <option value="">All customers</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <InputError :message="errors.customer_id" />
                </label>
                <label class="flex items-center gap-2 pt-7 text-sm">
                    <input v-model="overdueOnly" type="checkbox" @change="visit" />
                    Overdue only
                </label>
                <span v-if="loading" class="pt-7 text-sm text-muted-foreground">Loading...</span>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-6" :class="{ 'opacity-60': loading }">
                <div
                    v-for="bucket in buckets"
                    :key="bucket.key"
                    class="rounded-lg border p-3"
                    :class="{ 'bg-muted/50 font-semibold': bucket.key === 'total' }"
                >
                    <p class="text-xs text-muted-foreground">{{ bucket.label }}</p>
                    <p class="text-base">{{ formatMoney(aging[bucket.key].balance_cents) }}</p>
                    <p class="text-xs text-muted-foreground">{{ aging[bucket.key].count }} invoice(s)</p>
                </div>
            </div>

            <div v-if="invoices.data.length" class="overflow-x-auto rounded-lg border" :class="{ 'opacity-60': loading }">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2">Invoice</th>
                            <th class="px-3 py-2">Customer</th>
                            <th class="px-3 py-2">Issued</th>
                            <th class="px-3 py-2">Due</th>
                            <th class="px-3 py-2 text-right">Total</th>
                            <th class="px-3 py-2 text-right">Paid</th>
                            <th class="px-3 py-2 text-right">Balance</th>
                            <th class="px-3 py-2 text-right">Days overdue</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices.data" :key="invoice.id" class="border-b last:border-0">
                            <td class="px-3 py-2">
                                <Link :href="route('invoices.show', { invoice: invoice.id })" class="underline">{{ invoice.number }}</Link>
                            </td>
                            <td class="px-3 py-2">{{ invoice.customer }}</td>
                            <td class="px-3 py-2">{{ invoice.issue_date }}</td>
                            <td class="px-3 py-2">{{ invoice.due_date }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(invoice.total_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(invoice.paid_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(invoice.balance_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ invoice.days_overdue || '-' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="invoice.display_status" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState v-else title="Nothing outstanding" description="No issued invoice has an unpaid balance for these filters." />

            <Pagination :links="invoices.links" />
        </div>
    </AppLayout>
</template>
