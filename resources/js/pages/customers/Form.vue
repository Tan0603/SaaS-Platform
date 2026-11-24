<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface CustomerData {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    registration_no: string | null;
    payment_terms_days: number;
}

const props = defineProps<{
    customer: CustomerData | null;
    paymentTerms: number[];
    defaultPaymentTerms: number;
}>();

const isEdit = props.customer !== null;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: isEdit ? 'Edit customer' : 'New customer', href: '#' },
];

const form = useForm({
    name: props.customer?.name ?? '',
    email: props.customer?.email ?? '',
    phone: props.customer?.phone ?? '',
    address: props.customer?.address ?? '',
    registration_no: props.customer?.registration_no ?? '',
    payment_terms_days: props.customer?.payment_terms_days ?? props.defaultPaymentTerms,
});

function submit() {
    if (props.customer) {
        form.put(route('customers.update', { customer: props.customer.id }));
    } else {
        form.post(route('customers.store'));
    }
}

const termsLabel = (days: number) => (days === 0 ? 'Due on receipt' : `${days} days`);
</script>

<template>
    <Head :title="isEdit ? 'Edit customer' : 'New customer'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-2xl flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">{{ isEdit ? 'Edit customer' : 'New customer' }}</h1>

            <FlashMessage />

            <div
                v-if="Object.keys(form.errors).length"
                role="alert"
                class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800"
            >
                Please fix the errors below.
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input id="email" v-model="form.email" type="email" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input id="phone" v-model="form.phone" />
                    <InputError :message="form.errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="address">Billing address</Label>
                    <textarea
                        id="address"
                        v-model="form.address"
                        rows="3"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm"
                    />
                    <InputError :message="form.errors.address" />
                </div>

                <div class="grid gap-2">
                    <Label for="registration_no">Registration / TIN no. (optional)</Label>
                    <Input id="registration_no" v-model="form.registration_no" />
                    <InputError :message="form.errors.registration_no" />
                </div>

                <div class="grid gap-2">
                    <Label for="payment_terms_days">Payment terms</Label>
                    <select
                        id="payment_terms_days"
                        v-model="form.payment_terms_days"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                    >
                        <option v-for="days in paymentTerms" :key="days" :value="days">{{ termsLabel(days) }}</option>
                    </select>
                    <InputError :message="form.errors.payment_terms_days" />
                </div>

                <div class="flex items-center gap-2">
                    <Button type="submit" :disabled="form.processing">{{ form.processing ? 'Saving...' : 'Save' }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('customers.index')">Cancel</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
