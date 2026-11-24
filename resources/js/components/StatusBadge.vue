<script setup lang="ts">
// One coloured badge per invoice display status. "overdue" is computed by the server (Invoice::display_status).
defineProps<{ status: string }>();

const statusClass: Record<string, string> = {
    paid: 'bg-green-100 text-green-800',
    partial: 'bg-amber-100 text-amber-800',
    unpaid: 'bg-slate-100 text-slate-800',
    overdue: 'bg-red-100 text-red-800',
    draft: 'bg-slate-100 text-slate-600',
    void: 'bg-slate-200 text-slate-600 line-through',
};
</script>

<template>
    <span class="inline-block rounded px-2 py-0.5 text-xs font-medium capitalize" :class="statusClass[status] ?? 'bg-slate-100 text-slate-800'">
        {{ status }}
    </span>
</template>
