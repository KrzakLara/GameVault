<script setup lang="ts">
import GameCover from '@/components/games/GameCover.vue';
import GameGrid from '@/components/games/GameGrid.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import StoreLayout from '@/layouts/StoreLayout.vue';
import { formatMoney, pluralize } from '@/lib/format';
import type { Game, Platform } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Gamepad2, Joystick, KeyRound, Monitor, Search, ShieldCheck, ShoppingBag, Sparkles, TrendingUp, Zap } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

const props = defineProps<{
    featured: Game[];
    trending: { rule: string; games: Game[] };
    platforms: { name: Platform; games: number }[];
    stats: { games: number; keys_in_stock: number };
}>();

const query = ref('');
function search() {
    router.get(route('games.index'), query.value.trim() ? { search: query.value.trim() } : {});
}

// The first featured title sets the scene: its artwork is the hero backdrop and the spotlight card.
const spotlight = computed(() => props.featured[0] ?? null);

const platformIcons: Record<Platform, Component> = { Steam: Monitor, PlayStation: Gamepad2, Xbox: Joystick };
const steps = [
    { icon: Search, title: 'Pick a title', text: 'Original games for Steam, PlayStation and Xbox, with stock counted live.' },
    { icon: ShoppingBag, title: 'Check out once', text: 'Simulated payment. A double click or a retry never buys twice.' },
    { icon: KeyRound, title: 'Reveal your key', text: 'Delivered in seconds, shown only to you and only when you ask.' },
];
</script>

<template>
    <Head title="Discover games" />
    <StoreLayout>
        <!-- Hero: the spotlight title's artwork as a cinematic backdrop -->
        <section class="relative isolate overflow-hidden border-b border-border/60">
            <div class="absolute inset-0 -z-10" aria-hidden="true">
                <GameCover v-if="spotlight" :slug="spotlight.slug" :name="spotlight.name" :genre="spotlight.genre" format="landscape" decorative />
                <div
                    class="absolute inset-0 bg-gradient-to-r from-background via-background/90 to-background/40 lg:via-background/80 lg:to-background/10"
                />
                <div class="absolute inset-0 bg-gradient-to-t from-background via-background/10 to-background/70" />
                <div class="gv-aurora absolute inset-0 opacity-70" />
            </div>

            <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 md:py-24 lg:grid-cols-[1.2fr_0.8fr] lg:px-8">
                <div class="animate-rise-in">
                    <p
                        class="inline-flex items-center gap-2 rounded-full border border-primary/30 bg-primary/15 px-3 py-1 text-xs font-medium text-[hsl(258_100%_86%)] backdrop-blur-sm"
                    >
                        <Sparkles class="size-3.5" aria-hidden="true" /> Instant digital delivery
                    </p>
                    <h1 class="mt-6 text-4xl font-bold leading-[1.04] sm:text-5xl lg:text-6xl">
                        Your next world is <span class="gv-gradient-text">one key away.</span>
                    </h1>
                    <p class="mt-6 max-w-xl text-lg text-[hsl(224_20%_78%)]">
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
                                class="h-12 w-full rounded-xl border border-white/10 bg-background/70 pl-12 pr-4 text-base shadow-inner shadow-black/20 backdrop-blur-md placeholder:text-muted-foreground focus:border-primary"
                            />
                        </div>
                        <button
                            type="submit"
                            class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-primary px-6 font-semibold text-primary-foreground shadow-lg shadow-primary/30 transition hover:bg-primary/90"
                        >
                            Explore <ArrowRight class="size-4" aria-hidden="true" />
                        </button>
                    </form>
                    <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-[hsl(224_20%_78%)]">
                        <li class="flex items-center gap-2"><Zap class="size-4 text-highlight" aria-hidden="true" /> Delivered in seconds</li>
                        <li class="flex items-center gap-2">
                            <ShieldCheck class="size-4 text-highlight" aria-hidden="true" /> Keys revealed only to you
                        </li>
                    </ul>
                </div>

                <Link
                    v-if="spotlight"
                    :href="route('games.show', spotlight.slug)"
                    class="group relative mx-auto hidden w-full max-w-sm overflow-hidden rounded-3xl border border-white/10 bg-card/70 shadow-2xl shadow-black/60 ring-1 ring-white/5 backdrop-blur-md transition duration-300 hover:-translate-y-1 hover:border-primary/50 motion-reduce:transform-none lg:block"
                    data-testid="spotlight"
                >
                    <div class="aspect-[4/5] overflow-hidden">
                        <div class="size-full transition duration-700 ease-out group-hover:scale-[1.03] motion-reduce:group-hover:scale-100">
                            <GameCover :slug="spotlight.slug" :name="spotlight.name" :genre="spotlight.genre" decorative />
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-white/10 p-4">
                        <div class="min-w-0">
                            <p class="text-[11px] font-medium uppercase tracking-[0.16em] text-[hsl(258_100%_86%)]">
                                Spotlight · {{ spotlight.genre }}
                            </p>
                            <p class="mt-1 truncate font-display font-semibold">{{ spotlight.name }}</p>
                        </div>
                        <span
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-foreground px-3 py-1.5 text-sm font-semibold text-background"
                        >
                            {{ formatMoney(spotlight.price_cents, spotlight.currency) }}
                            <ArrowRight class="size-3.5 transition group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </div>
                </Link>
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
                        class="hidden items-center gap-1 rounded-md text-sm font-medium text-[hsl(258_100%_84%)] hover:underline sm:inline-flex"
                    >
                        Browse all <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
                <GameGrid v-if="featured.length" :games="featured" />
                <EmptyState v-else :icon="Sparkles" title="No featured games yet" description="Featured titles will appear here." />
            </section>

            <!-- How it works -->
            <section aria-labelledby="how-heading" class="gv-surface overflow-hidden">
                <h2 id="how-heading" class="sr-only">How it works</h2>
                <ol class="grid divide-y divide-border/70 md:grid-cols-3 md:divide-x md:divide-y-0">
                    <li v-for="(step, i) in steps" :key="step.title" class="flex gap-4 p-6">
                        <span
                            class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-primary/30 to-highlight/15 ring-1 ring-primary/30"
                        >
                            <component :is="step.icon" class="size-5 text-foreground" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="font-display font-semibold">
                                <span class="mr-1.5 font-mono text-xs text-muted-foreground">0{{ i + 1 }}</span
                                >{{ step.title }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ step.text }}</p>
                        </div>
                    </li>
                </ol>
            </section>

            <!-- Platforms -->
            <section aria-labelledby="platforms-heading">
                <h2 id="platforms-heading" class="mb-6 text-2xl font-semibold">Shop by platform</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <Link
                        v-for="platform in platforms"
                        :key="platform.name"
                        :href="route('games.index', { platform: platform.name })"
                        class="gv-surface group relative flex items-center gap-4 overflow-hidden p-5 transition hover:-translate-y-0.5 hover:border-primary/50 motion-reduce:transform-none"
                        :data-testid="`platform-${platform.name}`"
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-xl bg-gradient-to-br from-primary/30 to-highlight/20 text-foreground ring-1 ring-primary/30 transition group-hover:from-primary/45"
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
                            class="gv-surface group flex items-center gap-4 p-3 transition hover:border-primary/50 hover:bg-raised"
                        >
                            <span class="relative h-[4.5rem] w-14 shrink-0 overflow-hidden rounded-lg ring-1 ring-white/10">
                                <GameCover :slug="game.slug" :name="game.name" :genre="game.genre" :show-title="false" decorative />
                                <span
                                    class="absolute left-1 top-1 rounded bg-black/75 px-1.5 py-0.5 font-mono text-[10px] font-bold text-white"
                                    aria-hidden="true"
                                    >#{{ i + 1 }}</span
                                >
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold group-hover:text-[hsl(258_100%_86%)]">{{ game.name }}</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">{{ game.genre }}</span>
                                <span class="mt-1 block text-sm font-medium tabular-nums">{{ formatMoney(game.price_cents, game.currency) }}</span>
                            </span>
                        </Link>
                    </li>
                </ol>
                <EmptyState v-else :icon="TrendingUp" title="Nothing trending yet" description="Titles appear here once the catalogue has games." />
            </section>
        </div>
    </StoreLayout>
</template>
