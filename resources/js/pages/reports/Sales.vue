<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface SalesRow {
    month?: string;
    invoice_count: number;
    billed_cents: number;
    collected_cents: number;
    outstanding_cents: number;
}

const props = defineProps<{
    filters: { from: string; to: string };
    report: { rows: (SalesRow & { month: string })[]; totals: SalesRow };
    errors: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Report', href: '/reports/sales' },
    { title: 'Sales Report', href: '/reports/sales' },
];

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const loading = ref(false);
const loadError = ref(false);

function visit() {
    router.get(
        route('reports.sales'),
        { from: from.value || undefined, to: to.value || undefined },
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
</script>

<template>
    <Head title="Sales Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Sales Report</h1>
            <p class="text-sm text-muted-foreground">
                Issued invoices by issue month. Collected is the amount paid so far on those invoices (voided payments excluded). Drafts and void
                invoices are left out.
            </p>

            <div class="flex flex-wrap items-start gap-3">
                <label class="grid gap-1 text-sm">
                    From
                    <Input v-model="from" type="date" class="w-40" @change="visit" />
                    <InputError :message="errors.from" />
                </label>
                <label class="grid gap-1 text-sm">
                    To
                    <Input v-model="to" type="date" class="w-40" @change="visit" />
                    <InputError :message="errors.to" />
                </label>
                <span v-if="loading" class="pt-7 text-sm text-muted-foreground">Loading...</span>
            </div>

            <div v-if="report.rows.length" class="overflow-x-auto rounded-lg border" :class="{ 'opacity-60': loading }">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2">Month</th>
                            <th class="px-3 py-2 text-right">Invoices</th>
                            <th class="px-3 py-2 text-right">Billed</th>
                            <th class="px-3 py-2 text-right">Collected</th>
                            <th class="px-3 py-2 text-right">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in report.rows" :key="row.month" class="border-b">
                            <td class="px-3 py-2">{{ row.month }}</td>
                            <td class="px-3 py-2 text-right">{{ row.invoice_count }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(row.billed_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(row.collected_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(row.outstanding_cents) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-muted/50 font-semibold">
                        <tr>
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right">{{ report.totals.invoice_count }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(report.totals.billed_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(report.totals.collected_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(report.totals.outstanding_cents) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <EmptyState v-else title="No invoices in this period" description="Choose another date range." />
        </div>
    </AppLayout>
</template>
