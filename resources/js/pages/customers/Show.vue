<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import type { BreadcrumbItem } from '@/types';
import type { Paginated } from '@/types/pagination';
import { Head, Link } from '@inertiajs/vue3';

interface InvoiceRow {
    id: number;
    number: string | null;
    issue_date: string;
    due_date: string;
    display_status: string;
    total_cents: number;
    balance_cents: number;
}

const props = defineProps<{
    customer: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
        address: string | null;
        registration_no: string | null;
        payment_terms_days: number;
    };
    invoices: Paginated<InvoiceRow>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: props.customer.name, href: '#' },
];

const statusClass: Record<string, string> = {
    paid: 'bg-green-100 text-green-800',
    partial: 'bg-amber-100 text-amber-800',
    unpaid: 'bg-slate-100 text-slate-800',
    overdue: 'bg-red-100 text-red-800',
    draft: 'bg-slate-100 text-slate-600',
    void: 'bg-slate-200 text-slate-600',
};
</script>

<template>
    <Head :title="customer.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-semibold">{{ customer.name }}</h1>
                <Button variant="outline" as-child>
                    <Link :href="route('customers.edit', { customer: customer.id })">Edit</Link>
                </Button>
            </div>

            <FlashMessage />

            <dl class="grid max-w-2xl grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">Email</dt>
                <dd>{{ customer.email ?? '-' }}</dd>
                <dt class="text-muted-foreground">Phone</dt>
                <dd>{{ customer.phone ?? '-' }}</dd>
                <dt class="text-muted-foreground">Billing address</dt>
                <dd class="whitespace-pre-line">{{ customer.address ?? '-' }}</dd>
                <dt class="text-muted-foreground">Registration / TIN no.</dt>
                <dd>{{ customer.registration_no ?? '-' }}</dd>
                <dt class="text-muted-foreground">Payment terms</dt>
                <dd>{{ customer.payment_terms_days === 0 ? 'Due on receipt' : customer.payment_terms_days + ' days' }}</dd>
            </dl>

            <h2 class="mt-4 text-lg font-semibold">Invoices</h2>

            <div v-if="invoices.data.length" class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2">Number</th>
                            <th class="px-3 py-2">Issue date</th>
                            <th class="px-3 py-2">Due date</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2 text-right">Total</th>
                            <th class="px-3 py-2 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices.data" :key="invoice.id" class="border-b last:border-0">
                            <td class="px-3 py-2 font-medium">{{ invoice.number ?? 'Draft' }}</td>
                            <td class="px-3 py-2">{{ invoice.issue_date }}</td>
                            <td class="px-3 py-2">{{ invoice.due_date }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded px-2 py-0.5 text-xs font-medium capitalize" :class="statusClass[invoice.display_status]">
                                    {{ invoice.display_status }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(invoice.total_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(invoice.balance_cents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="No invoices yet" description="Invoices created for this customer will appear here." />

            <Pagination :links="invoices.links" />
        </div>
    </AppLayout>
</template>
