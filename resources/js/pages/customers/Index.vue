<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Paginated } from '@/types/pagination';
import { Head, Link, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { ref } from 'vue';

interface CustomerRow {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    payment_terms_days: number;
    deleted_at: string | null;
    invoices_count: number;
}

const props = defineProps<{
    customers: Paginated<CustomerRow>;
    filters: { search: string; archived: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customers', href: '/customers' }];

const search = ref(props.filters.search);
const archived = ref(props.filters.archived);
const loading = ref(false);

function visit() {
    router.get(
        route('customers.index'),
        { search: search.value || undefined, archived: archived.value ? 1 : undefined },
        {
            preserveState: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}

watchDebounced(search, visit, { debounce: 300 });

function archive(customer: CustomerRow) {
    if (confirm(`Archive ${customer.name}? Their invoices are kept.`)) {
        router.delete(route('customers.destroy', { customer: customer.id }), { preserveScroll: true });
    }
}

function forceDelete(customer: CustomerRow) {
    if (confirm(`Permanently delete ${customer.name}? This cannot be undone.`)) {
        router.delete(route('customers.force-destroy', { customer: customer.id }), { preserveScroll: true });
    }
}

function restore(customer: CustomerRow) {
    router.patch(route('customers.restore', { customer: customer.id }), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Customers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-semibold">Customers</h1>
                <Button as-child>
                    <Link :href="route('customers.create')">Add customer</Link>
                </Button>
            </div>

            <FlashMessage />

            <div class="flex flex-wrap items-center gap-4">
                <Input v-model="search" type="search" placeholder="Search name or email" class="max-w-sm" />
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="archived" type="checkbox" @change="visit" />
                    Show archived
                </label>
                <span v-if="loading" class="text-sm text-muted-foreground">Loading...</span>
            </div>

            <div v-if="customers.data.length" class="overflow-x-auto rounded-lg border" :class="{ 'opacity-60': loading }">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2">Email</th>
                            <th class="px-3 py-2">Phone</th>
                            <th class="px-3 py-2">Terms</th>
                            <th class="px-3 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="customer in customers.data" :key="customer.id" class="border-b last:border-0">
                            <td class="px-3 py-2 font-medium">
                                <span v-if="customer.deleted_at">{{ customer.name }}</span>
                                <Link v-else :href="route('customers.show', { customer: customer.id })" class="underline">{{ customer.name }}</Link>
                            </td>
                            <td class="px-3 py-2">{{ customer.email ?? '-' }}</td>
                            <td class="px-3 py-2">{{ customer.phone ?? '-' }}</td>
                            <td class="px-3 py-2">
                                {{ customer.payment_terms_days === 0 ? 'Due on receipt' : customer.payment_terms_days + ' days' }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-2">
                                    <template v-if="customer.deleted_at">
                                        <Button size="sm" variant="outline" @click="restore(customer)">Restore</Button>
                                        <Button v-if="customer.invoices_count === 0" size="sm" variant="destructive" @click="forceDelete(customer)">
                                            Delete
                                        </Button>
                                    </template>
                                    <template v-else>
                                        <Button size="sm" variant="outline" as-child>
                                            <Link :href="route('customers.edit', { customer: customer.id })">Edit</Link>
                                        </Button>
                                        <Button v-if="customer.invoices_count === 0" size="sm" variant="destructive" @click="forceDelete(customer)">
                                            Delete
                                        </Button>
                                        <Button v-else size="sm" variant="outline" title="Has invoices: archive keeps their history" @click="archive(customer)">
                                            Archive
                                        </Button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState
                v-else
                :title="filters.search || filters.archived ? 'No customers match your filters' : 'No customers yet'"
                :description="
                    filters.search || filters.archived ? 'Try another search or clear the filters.' : 'Add your first customer to start invoicing.'
                "
            >
                <Button v-if="!filters.search && !filters.archived" as-child>
                    <Link :href="route('customers.create')">Add customer</Link>
                </Button>
            </EmptyState>

            <Pagination :links="customers.links" />
        </div>
    </AppLayout>
</template>
