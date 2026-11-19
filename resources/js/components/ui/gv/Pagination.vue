<script setup lang="ts">
import type { PaginationMeta } from '@/types/models';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ meta: PaginationMeta; noun?: string; preserveScroll?: boolean }>(), { noun: 'results', preserveScroll: false });

// Laravel's first/last link labels are HTML ("&laquo; Previous"); render our own instead.
const pages = computed(() => props.meta.links.slice(1, -1));
const previous = computed(() => props.meta.links[0]?.url ?? null);
const next = computed(() => props.meta.links[props.meta.links.length - 1]?.url ?? null);
</script>

<template>
    <nav v-if="meta.last_page > 1 || meta.total > 0" class="flex flex-col items-center justify-between gap-4 sm:flex-row" aria-label="Pagination">
        <p class="text-sm text-muted-foreground">
            Showing <span class="font-medium text-foreground">{{ meta.from ?? 0 }}–{{ meta.to ?? 0 }}</span> of
            <span class="font-medium text-foreground">{{ meta.total.toLocaleString('en') }}</span> {{ noun }}
        </p>
        <ul v-if="meta.last_page > 1" class="flex items-center gap-1">
            <li>
                <Link
                    v-if="previous"
                    :href="previous"
                    :preserve-scroll="preserveScroll"
                    class="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-sm text-muted-foreground transition hover:bg-accent hover:text-foreground"
                    rel="prev"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" /> Previous
                </Link>
                <span v-else class="inline-flex h-9 cursor-not-allowed items-center gap-1 px-3 text-sm text-muted-foreground/40" aria-disabled="true">
                    <ChevronLeft class="size-4" aria-hidden="true" /> Previous
                </span>
            </li>
            <li v-for="(page, i) in pages" :key="i" class="hidden sm:block">
                <span v-if="!page.url" class="px-2 text-sm text-muted-foreground">…</span>
                <Link
                    v-else
                    :href="page.url"
                    :preserve-scroll="preserveScroll"
                    :aria-current="page.active ? 'page' : undefined"
                    :class="[
                        'inline-flex size-9 items-center justify-center rounded-lg text-sm transition',
                        page.active ? 'bg-primary font-semibold text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    ]"
                >
                    {{ page.label }}
                </Link>
            </li>
            <li>
                <Link
                    v-if="next"
                    :href="next"
                    :preserve-scroll="preserveScroll"
                    class="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-sm text-muted-foreground transition hover:bg-accent hover:text-foreground"
                    rel="next"
                >
                    Next <ChevronRight class="size-4" aria-hidden="true" />
                </Link>
                <span v-else class="inline-flex h-9 cursor-not-allowed items-center gap-1 px-3 text-sm text-muted-foreground/40" aria-disabled="true">
                    Next <ChevronRight class="size-4" aria-hidden="true" />
                </span>
            </li>
        </ul>
    </nav>
</template>
