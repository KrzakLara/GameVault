<script setup lang="ts">
import GameCover from '@/components/games/GameCover.vue';
import GameGrid from '@/components/games/GameGrid.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import StoreLayout from '@/layouts/StoreLayout.vue';
import { formatMoney, pluralize } from '@/lib/format';
import type { Game, Platform } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Gamepad2, Joystick, Monitor, Search, ShieldCheck, Sparkles, TrendingUp, Zap } from 'lucide-vue-next';
import { ref, type Component } from 'vue';

defineProps<{
    featured: Game[];
    trending: { rule: string; games: Game[] };
    platforms: { name: Platform; games: number }[];
    stats: { games: number; keys_in_stock: number };
}>();

const query = ref('');
function search() {
    router.get(route('games.index'), query.value.trim() ? { search: query.value.trim() } : {});
}

const platformIcons: Record<Platform, Component> = { Steam: Monitor, PlayStation: Gamepad2, Xbox: Joystick };
</script>

<template>
    <Head title="Discover games" />
    <StoreLayout>
        <!-- Hero -->
        <section class="gv-aurora relative overflow-hidden border-b border-border/60">
            <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 md:py-24 lg:grid-cols-[1.1fr_0.9fr] lg:px-8">
                <div class="animate-rise-in">
                    <p
                        class="inline-flex items-center gap-2 rounded-full border border-primary/30 bg-primary/10 px-3 py-1 text-xs font-medium text-[hsl(258_100%_84%)]"
                    >
                        <Sparkles class="size-3.5" aria-hidden="true" /> Instant digital delivery
                    </p>
                    <h1 class="mt-6 text-4xl font-bold leading-[1.05] sm:text-5xl lg:text-6xl">
                        Your next world is <span class="gv-gradient-text">one key away.</span>
                    </h1>
                    <p class="mt-6 max-w-xl text-lg text-muted-foreground">
                        Discover {{ pluralize(stats.games, 'original title') }} across Steam, PlayStation and Xbox, with
                        {{ stats.keys_in_stock.toLocaleString('en') }} keys ready to deliver the moment you check out.
                    </p>
                    <form class="mt-8 flex max-w-xl flex-col gap-3 sm:flex-row" role="search" @submit.prevent="search">
                        <label for="hero-search" class="sr-only">Search games</label>
                        <div class="relative flex-1">
                            <Search
                                class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <input
                                id="hero-search"
                                v-model="query"
                                type="search"
                                placeholder="Search the catalogue…"
                                class="h-12 w-full rounded-xl border border-input bg-card/80 pl-12 pr-4 text-base placeholder:text-muted-foreground focus:border-primary"
                            />
                        </div>
                        <button
                            type="submit"
                            class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-primary px-6 font-semibold text-primary-foreground shadow-lg shadow-primary/30 transition hover:bg-primary/90"
                        >
                            Explore <ArrowRight class="size-4" aria-hidden="true" />
                        </button>
                    </form>
                    <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                        <li class="flex items-center gap-2"><Zap class="size-4 text-highlight" aria-hidden="true" /> Delivered in seconds</li>
                        <li class="flex items-center gap-2">
                            <ShieldCheck class="size-4 text-highlight" aria-hidden="true" /> Keys revealed only to you
                        </li>
                    </ul>
                </div>

                <div v-if="featured.length" class="relative hidden lg:block" aria-hidden="true">
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            v-for="(game, i) in featured.slice(0, 4)"
                            :key="game.slug"
                            :class="[
                                'overflow-hidden rounded-2xl border border-white/10 shadow-2xl shadow-black/50',
                                i % 2 === 1 ? 'translate-y-8' : '',
                            ]"
                        >
                            <div class="aspect-[4/5]"><GameCover :slug="game.slug" :name="game.name" :platform="game.platform" variant="hero" /></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="mx-auto max-w-7xl space-y-20 px-4 py-16 sm:px-6 lg:px-8">
            <!-- Featured -->
            <section aria-labelledby="featured-heading">
                <div class="mb-6 flex items-end justify-between gap-4">
                    <div>
                        <h2 id="featured-heading" class="text-2xl font-semibold">Featured</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Hand-picked by the GameVault team.</p>
                    </div>
                    <Link
                        :href="route('games.index')"
                        class="hidden items-center gap-1 text-sm font-medium text-[hsl(258_100%_84%)] hover:underline sm:inline-flex"
                    >
                        Browse all <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
                <GameGrid v-if="featured.length" :games="featured" />
                <EmptyState v-else :icon="Sparkles" title="No featured games yet" description="Featured titles will appear here." />
            </section>

            <!-- Platforms -->
            <section aria-labelledby="platforms-heading">
                <h2 id="platforms-heading" class="mb-6 text-2xl font-semibold">Shop by platform</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <Link
                        v-for="platform in platforms"
                        :key="platform.name"
                        :href="route('games.index', { platform: platform.name })"
                        class="gv-surface group flex items-center gap-4 p-5 transition hover:border-primary/50"
                        :data-testid="`platform-${platform.name}`"
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-xl bg-gradient-to-br from-primary/25 to-highlight/15 text-foreground ring-1 ring-primary/25"
                        >
                            <component :is="platformIcons[platform.name]" class="size-6" aria-hidden="true" />
                        </span>
                        <span class="flex-1">
                            <span class="block font-display font-semibold">{{ platform.name === 'Steam' ? 'Steam · PC' : platform.name }}</span>
                            <span class="text-sm text-muted-foreground">{{ pluralize(platform.games, 'game') }}</span>
                        </span>
                        <ArrowRight
                            class="size-5 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-foreground"
                            aria-hidden="true"
                        />
                    </Link>
                </div>
            </section>

            <!-- Trending -->
            <section aria-labelledby="trending-heading">
                <div class="mb-6">
                    <h2 id="trending-heading" class="flex items-center gap-2 text-2xl font-semibold">
                        <TrendingUp class="size-6 text-highlight" aria-hidden="true" /> Trending now
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground" data-testid="trending-rule">Ranking rule: {{ trending.rule }}.</p>
                </div>
                <ol v-if="trending.games.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <li v-for="(game, i) in trending.games" :key="game.slug">
                        <Link
                            :href="route('games.show', game.slug)"
                            class="gv-surface flex items-center gap-4 p-3 transition hover:border-primary/50"
                        >
                            <span class="w-6 text-center font-display text-lg font-bold text-muted-foreground">{{ i + 1 }}</span>
                            <span class="size-14 shrink-0 overflow-hidden rounded-lg">
                                <GameCover :slug="game.slug" :name="game.name" :platform="game.platform" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold">{{ game.name }}</span>
                                <span class="text-sm text-muted-foreground">{{ formatMoney(game.price_cents, game.currency) }}</span>
                            </span>
                        </Link>
                    </li>
                </ol>
                <EmptyState v-else :icon="TrendingUp" title="Nothing trending yet" description="Titles appear here once the catalogue has games." />
            </section>
        </div>
    </StoreLayout>
</template>
