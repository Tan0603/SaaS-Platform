<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-10 text-center">
        <p class="text-base font-medium">{{ title }}</p>
        <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
        <div class="mt-2">
            <slot />
        </div>
    </div>
</template>
