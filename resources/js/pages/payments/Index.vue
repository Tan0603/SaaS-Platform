<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import type { BreadcrumbItem } from '@/types';
import type { Paginated } from '@/types/pagination';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface PaymentRow {
    id: number;
    paid_at: string;
    invoice_id: number;
    invoice_number: string | null;
    customer: string;
    method: string;
    reference: string | null;
    amount_cents: number;
    voided: boolean;
}

const props = defineProps<{
    payments: Paginated<PaymentRow>;
    filters: { from: string; to: string; method: string; include_voided: boolean };
    methods: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Payments', href: '/payments' }];

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const method = ref(props.filters.method);
const includeVoided = ref(props.filters.include_voided);
const loading = ref(false);
const loadError = ref(false);

const hasFilters = () => Boolean(props.filters.from || props.filters.to || props.filters.method || props.filters.include_voided);

function visit() {
    router.get(
        route('payments.index'),
        {
            from: from.value || undefined,
            to: to.value || undefined,
            method: method.value || undefined,
            include_voided: includeVoided.value ? 1 : undefined,
        },
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

function clearFilters() {
    from.value = '';
    to.value = '';
    method.value = '';
    includeVoided.value = false;
    visit();
}

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm';
</script>

<template>
    <Head title="Payments" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Payments</h1>
            <p class="text-sm text-muted-foreground">Payments are recorded from an invoice. Open an invoice to add one.</p>

            <FlashMessage />

            <div v-if="loadError" role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">
                The payments could not be loaded. Check the filters and try again.
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm">
                    Paid from
                    <Input v-model="from" type="date" class="w-40" @change="visit" />
                </label>
                <label class="grid gap-1 text-sm">
                    Paid to
                    <Input v-model="to" type="date" class="w-40" @change="visit" />
                </label>
                <label class="grid gap-1 text-sm">
                    Method
                    <select v-model="method" :class="selectClass" @change="visit">
                        <option value="">All</option>
                        <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 pb-2 text-sm">
                    <input v-model="includeVoided" type="checkbox" @change="visit" />
                    Include voided
                </label>
                <Button v-if="hasFilters()" variant="outline" size="sm" @click="clearFilters">Clear filters</Button>
                <span v-if="loading" class="text-sm text-muted-foreground">Loading...</span>
            </div>

            <div v-if="payments.data.length" class="overflow-x-auto rounded-lg border" :class="{ 'opacity-60': loading }">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2">Invoice</th>
                            <th class="px-3 py-2">Customer</th>
                            <th class="px-3 py-2">Method</th>
                            <th class="px-3 py-2">Reference</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="payment in payments.data" :key="payment.id" class="border-b last:border-0" :class="{ 'text-muted-foreground': payment.voided }">
                            <td class="px-3 py-2">{{ payment.paid_at }}</td>
                            <td class="px-3 py-2">
                                <Link :href="route('invoices.show', { invoice: payment.invoice_id })" class="underline">{{ payment.invoice_number ?? 'Draft' }}</Link>
                            </td>
                            <td class="px-3 py-2">{{ payment.customer }}</td>
                            <td class="px-3 py-2 capitalize">{{ payment.method.replace('_', ' ') }}</td>
                            <td class="px-3 py-2">{{ payment.reference ?? '-' }}</td>
                            <td class="px-3 py-2 text-right" :class="{ 'line-through': payment.voided }">{{ formatMoney(payment.amount_cents) }}</td>
                            <td class="px-3 py-2">
                                <StatusBadge :status="payment.voided ? 'void' : 'paid'" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState
                v-else
                :title="hasFilters() ? 'No payments match your filters' : 'No payments yet'"
                :description="hasFilters() ? 'Try other filters or clear them.' : 'Record a payment from an issued invoice.'"
            >
                <Button v-if="!hasFilters()" as-child>
                    <Link :href="route('invoices.index')">Go to invoices</Link>
                </Button>
            </EmptyState>

            <Pagination :links="payments.links" />
        </div>
    </AppLayout>
</template>
