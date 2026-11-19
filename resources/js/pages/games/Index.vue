<script setup lang="ts">
import GameGrid from '@/components/games/GameGrid.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import Pagination from '@/components/ui/gv/Pagination.vue';
import StoreLayout from '@/layouts/StoreLayout.vue';
import type { CatalogSort, Game, Paginated, Platform } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { SearchX, SlidersHorizontal, X } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    games: Paginated<Game>;
    filters: { search: string; platform: string; genre: string; sort: CatalogSort; in_stock: boolean };
    platforms: Platform[];
    genres: string[];
    sorts: CatalogSort[];
}>();

const sortLabels: Record<CatalogSort, string> = {
    featured: 'Featured first',
    price_asc: 'Price: low to high',
    price_desc: 'Price: high to low',
    name: 'Name A–Z',
    newest: 'Newest',
};

const form = reactive({ ...props.filters });
const loading = ref(false);
const activeFilters = computed(() => Boolean(form.search || form.platform || form.genre || form.in_stock || form.sort !== 'featured'));

// The server filters, sorts and paginates; the URL stays shareable (query string).
function apply() {
    // Only non-default values go into the URL, so links stay short and shareable.
    const query: Record<string, string | number> = {};
    if (form.search) query.search = form.search;
    if (form.platform) query.platform = form.platform;
    if (form.genre) query.genre = form.genre;
    if (form.sort !== 'featured') query.sort = form.sort;
    if (form.in_stock) query.in_stock = 1;

    router.get(route('games.index'), query, {
        only: ['games', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}
const applySearch = useDebounceFn(apply, 350);

function reset() {
    Object.assign(form, { search: '', platform: '', genre: '', sort: 'featured', in_stock: false });
    apply();
}

const selectClass =
    'h-10 rounded-lg border border-input bg-card px-3 text-sm text-foreground focus:border-primary disabled:opacity-50 [&>option]:bg-card';
</script>

<template>
    <Head title="Catalogue" />
    <StoreLayout>
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <header class="mb-8">
                <h1 class="text-3xl font-bold sm:text-4xl">Catalogue</h1>
                <p class="mt-2 text-muted-foreground">Every title is original and fictional, with live stock levels.</p>
            </header>

            <form
                class="gv-surface mb-8 flex flex-col gap-3 p-4 lg:flex-row lg:items-center"
                role="search"
                aria-label="Filter games"
                @submit.prevent="apply"
            >
                <div class="flex-1">
                    <label for="catalog-search" class="sr-only">Search by name</label>
                    <input
                        id="catalog-search"
                        v-model="form.search"
                        type="search"
                        maxlength="100"
                        placeholder="Search by name…"
                        class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm placeholder:text-muted-foreground focus:border-primary"
                        data-testid="catalog-search"
                        @input="applySearch"
                    />
                </div>
                <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
                    <label class="sr-only" for="filter-platform">Platform</label>
                    <select id="filter-platform" v-model="form.platform" :class="selectClass" data-testid="filter-platform" @change="apply">
                        <option value="">All platforms</option>
                        <option v-for="p in platforms" :key="p" :value="p">{{ p }}</option>
                    </select>
                    <label class="sr-only" for="filter-genre">Genre</label>
                    <select id="filter-genre" v-model="form.genre" :class="selectClass" @change="apply">
                        <option value="">All genres</option>
                        <option v-for="g in genres" :key="g" :value="g">{{ g }}</option>
                    </select>
                    <label class="sr-only" for="filter-sort">Sort by</label>
                    <select id="filter-sort" v-model="form.sort" :class="selectClass" data-testid="filter-sort" @change="apply">
                        <option v-for="s in sorts" :key="s" :value="s">{{ sortLabels[s] }}</option>
                    </select>
                    <label class="flex h-10 cursor-pointer items-center gap-2 rounded-lg border border-input bg-card px-3 text-sm">
                        <input v-model="form.in_stock" type="checkbox" class="size-4 accent-[hsl(262_84%_64%)]" @change="apply" />
                        In stock
                    </label>
                </div>
                <button
                    v-if="activeFilters"
                    type="button"
                    class="inline-flex h-10 items-center justify-center gap-1.5 rounded-lg px-3 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="reset"
                >
                    <X class="size-4" aria-hidden="true" /> Reset
                </button>
            </form>

            <p class="mb-4 flex items-center gap-2 text-sm text-muted-foreground" aria-live="polite">
                <SlidersHorizontal class="size-4" aria-hidden="true" />
                <span v-if="loading">Updating results…</span>
                <span v-else data-testid="result-count"
                    >{{ games.meta.total.toLocaleString('en') }} {{ games.meta.total === 1 ? 'game' : 'games' }}</span
                >
            </p>

            <GameGrid v-if="loading || games.data.length" :games="games.data" :loading="loading" :skeletons="games.data.length || 8" />
            <EmptyState v-else :icon="SearchX" title="No games match these filters" description="Try a different search term or reset the filters.">
                <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground" @click="reset">
                    Reset filters
                </button>
            </EmptyState>

            <div class="mt-10"><Pagination :meta="games.meta" noun="games" /></div>
        </div>
    </StoreLayout>
</template>
