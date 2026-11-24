<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Item {
    id: number;
    description: string;
    quantity: string;
    unit_price_cents: number;
    line_total_cents: number;
}

interface PaymentRow {
    id: number;
    amount_cents: number;
    paid_at: string;
    method: string;
    reference: string | null;
    voided: boolean;
    void_reason: string | null;
    can_void: boolean;
}

const props = defineProps<{
    invoice: {
        id: number;
        number: string | null;
        state: string;
        display_status: string;
        issue_date: string;
        due_date: string;
        subtotal_cents: number;
        total_cents: number;
        amount_paid_cents: number;
        balance_cents: number;
        void_reason: string | null;
        customer: { id: number; name: string; email: string | null };
        items: Item[];
        payments: PaymentRow[];
    };
    can: { update: boolean; issue: boolean; void: boolean; delete: boolean; record_payment: boolean };
    methods: { value: string; label: string }[];
    today: string;
}>();

const title = props.invoice.number ?? 'Draft invoice';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Invoices', href: '/invoices' },
    { title, href: '#' },
];

const busy = ref(false);
const showVoid = ref(false);
const voidForm = useForm({ reason: '' });

const paymentForm = useForm({
    amount: (props.invoice.balance_cents / 100).toFixed(2),
    paid_at: props.today,
    method: props.methods[0]?.value ?? 'cash',
    reference: '',
});

function submitPayment() {
    paymentForm.post(route('invoices.payments.store', { invoice: props.invoice.id }), {
        preserveScroll: true,
        onSuccess: () => paymentForm.reset('reference'),
    });
}

// Voiding a payment asks for a reason first.
const voidingPaymentId = ref<number | null>(null);
const paymentVoidForm = useForm({ reason: '' });

function startVoidPayment(id: number) {
    voidingPaymentId.value = id;
    paymentVoidForm.reset();
    paymentVoidForm.clearErrors();
}

function submitVoidPayment(id: number) {
    paymentVoidForm.post(route('payments.void', { payment: id }), {
        preserveScroll: true,
        onSuccess: () => (voidingPaymentId.value = null),
    });
}

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm';

function post(url: string, confirmText: string) {
    if (!confirm(confirmText)) {
        return;
    }
    router.post(url, {}, { onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
}

function issue() {
    post(route('invoices.issue', { invoice: props.invoice.id }), 'Issue this invoice? It gets its number and can no longer be deleted.');
}

function destroy() {
    if (confirm('Delete this draft? This cannot be undone.')) {
        router.delete(route('invoices.destroy', { invoice: props.invoice.id }), {
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        });
    }
}

function submitVoid() {
    voidForm.post(route('invoices.void', { invoice: props.invoice.id }), {
        onSuccess: () => (showVoid.value = false),
    });
}
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold">{{ title }}</h1>
                    <StatusBadge :status="invoice.display_status" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.issue" :disabled="busy" @click="issue">Issue</Button>
                    <Button v-if="can.update" variant="outline" as-child>
                        <Link :href="route('invoices.edit', { invoice: invoice.id })">Edit</Link>
                    </Button>
                    <Button v-if="can.void" variant="outline" :disabled="busy" @click="showVoid = !showVoid">Void</Button>
                    <Button v-if="can.delete" variant="destructive" :disabled="busy" @click="destroy">Delete</Button>
                </div>
            </div>

            <FlashMessage />

            <form v-if="showVoid && can.void" class="flex max-w-xl flex-col gap-2 rounded-lg border p-3" @submit.prevent="submitVoid">
                <label class="text-sm font-medium" for="reason">Reason for voiding</label>
                <Input id="reason" v-model="voidForm.reason" required />
                <InputError :message="voidForm.errors.reason" />
                <div class="flex gap-2">
                    <Button type="submit" variant="destructive" :disabled="voidForm.processing">
                        {{ voidForm.processing ? 'Voiding...' : 'Void invoice' }}
                    </Button>
                    <Button type="button" variant="outline" @click="showVoid = false">Cancel</Button>
                </div>
            </form>

            <div v-if="invoice.state === 'void' && invoice.void_reason" class="rounded-md border bg-muted/50 px-4 py-2 text-sm">
                Voided: {{ invoice.void_reason }}
            </div>

            <dl class="grid max-w-2xl grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">Customer</dt>
                <dd>
                    <Link :href="route('customers.show', { customer: invoice.customer.id })" class="underline">{{ invoice.customer.name }}</Link>
                </dd>
                <dt class="text-muted-foreground">Issue date</dt>
                <dd>{{ invoice.issue_date }}</dd>
                <dt class="text-muted-foreground">Due date</dt>
                <dd>{{ invoice.due_date }}</dd>
            </dl>

            <h2 class="text-lg font-semibold">Items</h2>
            <div v-if="invoice.items.length" class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2">Description</th>
                            <th class="px-3 py-2 text-right">Qty</th>
                            <th class="px-3 py-2 text-right">Unit price</th>
                            <th class="px-3 py-2 text-right">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in invoice.items" :key="item.id" class="border-b last:border-0">
                            <td class="px-3 py-2">{{ item.description }}</td>
                            <td class="px-3 py-2 text-right">{{ item.quantity }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(item.unit_price_cents) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(item.line_total_cents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="No items" description="This invoice has no items." />

            <dl class="ml-auto grid w-full max-w-xs grid-cols-2 gap-x-4 gap-y-1 text-sm">
                <dt class="text-muted-foreground">Subtotal</dt>
                <dd class="text-right">{{ formatMoney(invoice.subtotal_cents) }}</dd>
                <dt class="font-semibold">Total</dt>
                <dd class="text-right font-semibold">{{ formatMoney(invoice.total_cents) }}</dd>
                <dt class="text-muted-foreground">Paid</dt>
                <dd class="text-right">{{ formatMoney(invoice.amount_paid_cents) }}</dd>
                <dt class="font-semibold">Balance</dt>
                <dd class="text-right font-semibold">{{ formatMoney(invoice.balance_cents) }}</dd>
            </dl>

            <h2 class="text-lg font-semibold">Payments</h2>

            <form v-if="can.record_payment" class="grid max-w-3xl gap-3 rounded-lg border p-3 sm:grid-cols-2" @submit.prevent="submitPayment">
                <label class="grid gap-1 text-sm">
                    Amount (balance {{ formatMoney(invoice.balance_cents) }})
                    <Input v-model="paymentForm.amount" inputmode="decimal" required />
                    <InputError :message="paymentForm.errors.amount" />
                </label>
                <label class="grid gap-1 text-sm">
                    Payment date
                    <Input v-model="paymentForm.paid_at" type="date" required />
                    <InputError :message="paymentForm.errors.paid_at" />
                </label>
                <label class="grid gap-1 text-sm">
                    Method
                    <select v-model="paymentForm.method" :class="selectClass">
                        <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                    <InputError :message="paymentForm.errors.method" />
                </label>
                <label class="grid gap-1 text-sm">
                    Reference (optional)
                    <Input v-model="paymentForm.reference" maxlength="100" />
                    <InputError :message="paymentForm.errors.reference" />
                </label>
                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="paymentForm.processing">
                        {{ paymentForm.processing ? 'Recording...' : 'Record payment' }}
                    </Button>
                </div>
            </form>

            <div v-if="invoice.payments.length" class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2">Method</th>
                            <th class="px-3 py-2">Reference</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="payment in invoice.payments" :key="payment.id">
                            <tr class="border-b last:border-0" :class="{ 'text-muted-foreground': payment.voided }">
                                <td class="px-3 py-2">{{ payment.paid_at }}</td>
                                <td class="px-3 py-2 capitalize">{{ payment.method.replace('_', ' ') }}</td>
                                <td class="px-3 py-2">{{ payment.reference ?? '-' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <span :class="{ 'line-through': payment.voided }">{{ formatMoney(payment.amount_cents) }}</span>
                                    <StatusBadge v-if="payment.voided" status="void" class="ml-2" />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <Button v-if="payment.can_void" variant="outline" size="sm" @click="startVoidPayment(payment.id)">Void</Button>
                                    <span v-else-if="payment.void_reason" class="text-xs">{{ payment.void_reason }}</span>
                                </td>
                            </tr>
                            <tr v-if="voidingPaymentId === payment.id" class="border-b bg-muted/30">
                                <td colspan="5" class="px-3 py-2">
                                    <form class="flex max-w-xl flex-col gap-2" @submit.prevent="submitVoidPayment(payment.id)">
                                        <label class="text-sm font-medium" :for="`void-reason-${payment.id}`">Reason for voiding this payment</label>
                                        <Input :id="`void-reason-${payment.id}`" v-model="paymentVoidForm.reason" required maxlength="255" />
                                        <InputError :message="paymentVoidForm.errors.reason" />
                                        <div class="flex gap-2">
                                            <Button type="submit" variant="destructive" size="sm" :disabled="paymentVoidForm.processing">
                                                {{ paymentVoidForm.processing ? 'Voiding...' : 'Void payment' }}
                                            </Button>
                                            <Button type="button" variant="outline" size="sm" @click="voidingPaymentId = null">Cancel</Button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="No payments yet" description="Payments recorded against this invoice will appear here." />
        </div>
    </AppLayout>
</template>
