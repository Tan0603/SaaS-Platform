<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import type { BreadcrumbItem } from '@/types';
import type { Paginated } from '@/types/pagination';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface CustomerRow {
    id: number;
    name: string;
    archived: boolean;
    invoice_count: number;
    billed_cents: number;
    paid_cents: number;
    balance_cents: number;
    overdue_cents: number;
}

const props = defineProps<{
    filters: { from: string; to: string; sort: string; dir: string };
    customers: Paginated<CustomerRow>;
    errors: Record<string, string>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Report', href: '/reports/sales' },
    { title: 'Customer Invoice Summary', href: '/reports/customers' },
];

const columns: { key: string; label: string; numeric: boolean }[] = [
    { key: 'name', label: 'Customer', numeric: false },
    { key: 'invoices', label: 'Invoices', numeric: true },
    { key: 'billed', label: 'Billed', numeric: true },
    { key: 'paid', label: 'Paid', numeric: true },
    { key: 'balance', label: 'Balance', numeric: true },
    { key: 'overdue', label: 'Overdue', numeric: true },
];

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const loading = ref(false);
const loadError = ref(false);

function visit(sort = props.filters.sort, dir = props.filters.dir) {
    router.get(
        route('reports.customers'),
        { from: from.value || undefined, to: to.value || undefined, sort, dir },
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

function sortBy(key: string) {
    if (key === 'invoices') {
        return; // the invoice count is not a sort column
    }
    visit(key, props.filters.sort === key && props.filters.dir === 'asc' ? 'desc' : 'asc');
}

function clearRange() {
    from.value = '';
    to.value = '';
    visit();
}

function arrow(key: string): string {
    return props.filters.sort === key ? (props.filters.dir === 'asc' ? ' ↑' : ' ↓') : '';
}
</script>

<template>
    <Head title="Customer Invoice Summary" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Customer Invoice Summary</h1>
            <p class="text-sm text-muted-foreground">
                Issued invoices per customer. Leave the dates empty for all time. Overdue is the unpaid balance of invoices past their due date.
            </p>

            <div class="flex flex-wrap items-start gap-3">
                <label class="grid gap-1 text-sm">
                    Issued from
                    <Input v-model="from" type="date" class="w-40" @change="visit()" />
                    <InputError :message="errors.from" />
                </label>
                <label class="grid gap-1 text-sm">
                    Issued to
                    <Input v-model="to" type="date" class="w-40" @change="visit()" />
                    <InputError :message="errors.to" />
                </label>
                <Button v-if="from || to" class="mt-6" variant="outline" size="sm" @click="clearRange">Clear dates</Button>
                <span v-if="loading" class="pt-7 text-sm text-muted-foreground">Loading...</span>
            </div>
            <InputError :message="errors.sort || errors.dir" />

            <div v-if="customers.data.length" class="overflow-x-auto rounded-lg border" :class="{ 'opacity-60': loading }">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th v-for="col in columns" :key="col.key" class="px-3 py-2" :class="{ 'text-right': col.numeric }">
                                <button v-if="col.key !== 'invoices'" type="button" class="font-medium hover:underline" @click="sortBy(col.key)">
                                    {{ col.label }}{{ arrow(col.key) }}
                                </button>
                                <span v-else>{{ col.label }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in customers.data"
                            :key="row.id"
                            class="border-b last:border-0"
                            :class="{ 'text-muted-foreground': row.archived }"
                        >
                            <td class="px-3 py-2">{{ row.name }}<span v-if="row.archived" class="ml-1 text-xs">(archived)</span></td>
                            <td class="px-3 py-2 text-right">{{ row.invoice_count }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(row.billed_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(row.paid_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(row.balance_cents) }}</td>
                            <td class="px-3 py-2 text-right" :class="{ 'font-medium text-red-700': row.overdue_cents > 0 }">
                                {{ formatMoney(row.overdue_cents) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState v-else title="No customers yet" description="Add a customer to see their invoice summary." />

            <Pagination :links="customers.links" />
        </div>
    </AppLayout>
</template>
