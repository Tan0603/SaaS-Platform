<script setup lang="ts">
import type { PaginationLink } from '@/types/pagination';
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: PaginationLink[];
}>();
</script>

<template>
    <nav v-if="links.length > 3" class="flex flex-wrap items-center gap-1" aria-label="Pagination">
        <template v-for="(link, index) in links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="rounded-md border px-3 py-1 text-sm hover:bg-muted"
                :class="{ 'bg-primary text-primary-foreground hover:bg-primary': link.active }"
            >
                <span v-html="link.label" />
            </Link>
            <span v-else class="rounded-md border px-3 py-1 text-sm text-muted-foreground opacity-60" v-html="link.label" />
        </template>
    </nav>
</template>
