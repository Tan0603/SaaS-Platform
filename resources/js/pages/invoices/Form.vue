<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney, lineTotalCents } from '@/lib/format';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface ItemInput {
    description: string;
    quantity: string;
    unit_price: string;
}

interface InvoiceData {
    id: number;
    number: string | null;
    state: string;
    customer_id: number;
    issue_date: string;
    due_date: string;
    items: ItemInput[];
}

const props = defineProps<{
    invoice: InvoiceData | null;
    customers: { id: number; name: string; payment_terms_days: number }[];
    defaultPaymentTerms: number;
    today: string;
}>();

const isEdit = props.invoice !== null;
const isDraft = !props.invoice || props.invoice.state === 'draft';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Invoices', href: '/invoices' },
    { title: isEdit ? 'Edit invoice' : 'New invoice', href: '#' },
];

const blankItem = (): ItemInput => ({ description: '', quantity: '1', unit_price: '' });

const form = useForm<{
    customer_id: number | '';
    issue_date: string;
    due_date: string;
    action: 'save' | 'issue';
    items: ItemInput[];
}>({
    customer_id: props.invoice?.customer_id ?? '',
    issue_date: props.invoice?.issue_date ?? props.today,
    due_date: props.invoice?.due_date ?? props.today,
    action: 'save',
    items: props.invoice?.items.length ? props.invoice.items.map((i) => ({ ...i })) : [blankItem()],
});

// Due date = issue date + the customer's payment terms, until the user types their own date.
const dueTouched = ref(isEdit);

function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

function applyTerms() {
    if (dueTouched.value || !form.issue_date) {
        return;
    }
    const customer = props.customers.find((c) => c.id === form.customer_id);
    form.due_date = addDays(form.issue_date, customer ? customer.payment_terms_days : props.defaultPaymentTerms);
}

if (!isEdit) {
    applyTerms();
}
watch(() => [form.customer_id, form.issue_date], applyTerms);

const lineTotals = computed(() => form.items.map((item) => lineTotalCents(item.quantity, item.unit_price)));
// Display only: the server recalculates everything and ignores what the browser shows.
const runningTotal = computed(() => lineTotals.value.reduce((sum, cents) => sum + cents, 0));

function addItem() {
    if (form.items.length < 100) {
        form.items.push(blankItem());
    }
}

function removeItem(index: number) {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
}

function submit(action: 'save' | 'issue') {
    form.action = action;

    if (props.invoice) {
        form.put(route('invoices.update', { invoice: props.invoice.id }));
    } else {
        form.post(route('invoices.store'));
    }
}

const itemError = (index: number, field: string) => (form.errors as Record<string, string>)[`items.${index}.${field}`];
const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-sm';
</script>

<template>
    <Head :title="isEdit ? 'Edit invoice' : 'New invoice'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-4xl flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">{{ isEdit ? `Edit invoice ${invoice?.number ?? '(draft)'}` : 'New invoice' }}</h1>

            <FlashMessage />

            <EmptyState
                v-if="!customers.length"
                title="You have no customers yet"
                description="Add an active customer before creating an invoice."
            >
                <Button as-child>
                    <Link :href="route('customers.create')">Add customer</Link>
                </Button>
            </EmptyState>

            <template v-else>
                <div
                    v-if="Object.keys(form.errors).length"
                    role="alert"
                    class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800"
                >
                    Please fix the errors below.
                </div>

                <form class="space-y-4" @submit.prevent="submit('save')">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="customer_id">Customer</Label>
                            <select id="customer_id" v-model="form.customer_id" :class="selectClass" required>
                                <option value="" disabled>Choose a customer</option>
                                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <InputError :message="form.errors.customer_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="issue_date">Issue date</Label>
                            <Input id="issue_date" v-model="form.issue_date" type="date" required />
                            <InputError :message="form.errors.issue_date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="due_date">Due date</Label>
                            <Input id="due_date" v-model="form.due_date" type="date" required @input="dueTouched = true" />
                            <InputError :message="form.errors.due_date" />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <h2 class="text-lg font-semibold">Items</h2>
                        <InputError :message="form.errors.items" />

                        <div v-for="(item, index) in form.items" :key="index" class="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_6rem_8rem_7rem_auto]">
                            <div class="grid gap-1">
                                <Label :for="`description-${index}`">Description</Label>
                                <Input :id="`description-${index}`" v-model="item.description" required />
                                <InputError :message="itemError(index, 'description')" />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`quantity-${index}`">Qty</Label>
                                <Input :id="`quantity-${index}`" v-model="item.quantity" inputmode="decimal" required />
                                <InputError :message="itemError(index, 'quantity')" />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`price-${index}`">Unit price</Label>
                                <Input :id="`price-${index}`" v-model="item.unit_price" inputmode="decimal" placeholder="0.00" required />
                                <InputError :message="itemError(index, 'unit_price')" />
                            </div>
                            <div class="grid gap-1 text-right">
                                <span class="text-sm font-medium">Line total</span>
                                <span class="pt-2 text-sm">{{ formatMoney(lineTotals[index]) }}</span>
                            </div>
                            <div class="flex items-end">
                                <Button type="button" variant="outline" size="sm" :disabled="form.items.length === 1" @click="removeItem(index)">
                                    Remove
                                </Button>
                            </div>
                        </div>

                        <Button type="button" variant="outline" size="sm" :disabled="form.items.length >= 100" @click="addItem">Add item</Button>
                    </div>

                    <p class="text-right text-base font-semibold">
                        Total: {{ formatMoney(runningTotal) }}
                        <span class="block text-xs font-normal text-muted-foreground">Calculated again by the server when you save.</span>
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button type="button" :disabled="form.processing" @click="submit('save')">
                            {{ form.processing ? 'Saving...' : isDraft ? 'Save as draft' : 'Save changes' }}
                        </Button>
                        <Button v-if="isDraft" type="button" variant="secondary" :disabled="form.processing" @click="submit('issue')">
                            Save &amp; issue
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="invoice ? route('invoices.show', { invoice: invoice.id }) : route('invoices.index')">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </template>
        </div>
    </AppLayout>
</template>
