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

interface InvoiceRow {
    id: number;
    number: string | null;
    customer: string;
    issue_date: string;
    due_date: string;
    display_status: string;
    total_cents: number;
    balance_cents: number;
}

const props = defineProps<{
    invoices: Paginated<InvoiceRow>;
    filters: { status: string; customer_id: number | string; from: string; to: string };
    statuses: string[];
    customers: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Invoices', href: '/invoices' }];

const status = ref(props.filters.status);
const customerId = ref(props.filters.customer_id);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const loading = ref(false);
const loadError = ref(false);

const hasFilters = () => Boolean(props.filters.status || props.filters.customer_id || props.filters.from || props.filters.to);

function visit() {
    router.get(
        route('invoices.index'),
        {
            status: status.value || undefined,
            customer_id: customerId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
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
    status.value = '';
    customerId.value = '';
    from.value = '';
    to.value = '';
    visit();
}

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm';
</script>

<template>
    <Head title="Invoices" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-semibold">Invoices</h1>
                <Button as-child>
                    <Link :href="route('invoices.create')">New invoice</Link>
                </Button>
            </div>

            <FlashMessage />

            <div v-if="loadError" role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">
                The invoices could not be loaded. Check the filters and try again.
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm">
                    Status
                    <select v-model="status" :class="selectClass" @change="visit">
                        <option value="">All</option>
                        <option v-for="s in statuses" :key="s" :value="s" class="capitalize">{{ s }}</option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    Customer
                    <select v-model="customerId" :class="selectClass" @change="visit">
                        <option value="">All</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    Issued from
                    <Input v-model="from" type="date" class="w-40" @change="visit" />
                </label>
                <label class="grid gap-1 text-sm">
                    Issued to
                    <Input v-model="to" type="date" class="w-40" @change="visit" />
                </label>
                <Button v-if="hasFilters()" variant="outline" size="sm" @click="clearFilters">Clear filters</Button>
                <span v-if="loading" class="text-sm text-muted-foreground">Loading...</span>
            </div>

            <div v-if="invoices.data.length" class="overflow-x-auto rounded-lg border" :class="{ 'opacity-60': loading }">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2">Number</th>
                            <th class="px-3 py-2">Customer</th>
                            <th class="px-3 py-2">Issue date</th>
                            <th class="px-3 py-2">Due date</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2 text-right">Total</th>
                            <th class="px-3 py-2 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices.data" :key="invoice.id" class="border-b last:border-0">
                            <td class="px-3 py-2 font-medium">
                                <Link :href="route('invoices.show', { invoice: invoice.id })" class="underline">{{ invoice.number ?? 'Draft' }}</Link>
                            </td>
                            <td class="px-3 py-2">{{ invoice.customer }}</td>
                            <td class="px-3 py-2">{{ invoice.issue_date }}</td>
                            <td class="px-3 py-2">{{ invoice.due_date }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="invoice.display_status" /></td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(invoice.total_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(invoice.balance_cents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState
                v-else
                :title="hasFilters() ? 'No invoices match your filters' : 'No invoices yet'"
                :description="hasFilters() ? 'Try other filters or clear them.' : 'Create your first invoice.'"
            >
                <Button v-if="!hasFilters()" as-child>
                    <Link :href="route('invoices.create')">New invoice</Link>
                </Button>
            </EmptyState>

            <Pagination :links="invoices.links" />
        </div>
    </AppLayout>
</template>
