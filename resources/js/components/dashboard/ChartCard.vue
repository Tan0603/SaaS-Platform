<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    title: string;
    description?: string;
    empty: boolean;
    emptyText: string;
}>();
</script>

<template>
    <Card class="flex h-full flex-col">
        <CardHeader>
            <CardTitle class="text-base">{{ title }}</CardTitle>
            <CardDescription v-if="description">{{ description }}</CardDescription>
        </CardHeader>
        <CardContent class="flex flex-1 flex-col">
            <div v-if="empty" class="flex min-h-64 flex-1 items-center justify-center rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                {{ emptyText }}
            </div>
            <div v-else class="relative min-h-64 flex-1">
                <slot />
            </div>
        </CardContent>
    </Card>
</template>
