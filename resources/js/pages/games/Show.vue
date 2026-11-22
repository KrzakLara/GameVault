<script setup lang="ts">
import PurchasePanel from '@/components/checkout/PurchasePanel.vue';
import GameCard from '@/components/games/GameCard.vue';
import GameCover from '@/components/games/GameCover.vue';
import MarketPriceCard from '@/components/games/MarketPriceCard.vue';
import PlatformBadge from '@/components/games/PlatformBadge.vue';
import Badge from '@/components/ui/gv/Badge.vue';
import { Skeleton } from '@/components/ui/skeleton';
import StoreLayout from '@/layouts/StoreLayout.vue';
import type { Game, MarketPrice } from '@/types';
import { Deferred, Head, Link } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';

defineProps<{ game: Game; marketPrice?: MarketPrice; related: Game[]; lowStockThreshold: number }>();
</script>

<template>
    <Head :title="game.name" />
    <StoreLayout>
        <div class="relative isolate">
            <!-- Banner: the same artwork in landscape, fading into the page -->
            <div class="absolute inset-x-0 top-0 -z-10 h-[26rem] overflow-hidden sm:h-[30rem]" aria-hidden="true">
                <GameCover :slug="game.slug" :name="game.name" :genre="game.genre" format="landscape" decorative />
                <div class="absolute inset-0 bg-gradient-to-b from-background/50 via-background/80 to-background" />
                <div class="absolute inset-0 bg-gradient-to-r from-background/70 via-transparent to-background/70" />
            </div>

            <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <Link
                    :href="route('games.index')"
                    class="inline-flex items-center gap-1 rounded-md text-sm text-[hsl(224_20%_80%)] transition hover:text-foreground"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" /> Back to catalogue
                </Link>

                <div class="mt-6 grid gap-10 lg:grid-cols-[minmax(0,1fr)_380px]">
                    <article class="grid gap-8 md:grid-cols-[280px_minmax(0,1fr)]">
                        <div
                            class="relative mx-auto w-60 overflow-hidden rounded-2xl border border-white/10 shadow-2xl shadow-black/60 ring-1 ring-white/5 md:w-full md:self-start"
                        >
                            <div class="aspect-[4/5]">
                                <GameCover :slug="game.slug" :name="game.name" :genre="game.genre" :cover-url="game.cover_url" />
                            </div>
                        </div>
                        <div class="min-w-0 md:pt-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <PlatformBadge :platform="game.platform" />
                                <Badge>{{ game.genre }}</Badge>
                                <Badge v-if="game.is_featured" tone="violet">Featured</Badge>
                            </div>
                            <h1 class="mt-4 text-3xl font-bold sm:text-5xl" data-testid="game-title">{{ game.name }}</h1>
                            <p class="mt-4 max-w-prose text-base leading-relaxed text-[hsl(224_20%_80%)]">
                                {{ game.description ?? 'No description yet.' }}
                            </p>
                            <dl class="mt-6 grid max-w-lg grid-cols-2 gap-3 text-sm">
                                <div class="gv-surface bg-card/80 p-3 backdrop-blur-sm">
                                    <dt class="text-xs uppercase tracking-wider text-muted-foreground">Platform</dt>
                                    <dd class="mt-1 font-medium">{{ game.platform === 'Steam' ? 'Steam (PC)' : game.platform }}</dd>
                                </div>
                                <div class="gv-surface bg-card/80 p-3 backdrop-blur-sm">
                                    <dt class="text-xs uppercase tracking-wider text-muted-foreground">Genre</dt>
                                    <dd class="mt-1 font-medium">{{ game.genre }}</dd>
                                </div>
                                <div class="gv-surface bg-card/80 p-3 backdrop-blur-sm">
                                    <dt class="text-xs uppercase tracking-wider text-muted-foreground">Delivery</dt>
                                    <dd class="mt-1 font-medium">Digital key, instant</dd>
                                </div>
                                <div class="gv-surface bg-card/80 p-3 backdrop-blur-sm">
                                    <dt class="text-xs uppercase tracking-wider text-muted-foreground">Checkout</dt>
                                    <dd class="mt-1 font-medium">Simulated, no payment</dd>
                                </div>
                            </dl>
                            <div class="mt-6 max-w-lg">
                                <Deferred data="marketPrice">
                                    <template #fallback>
                                        <div
                                            class="space-y-2 rounded-xl border border-border/80 bg-raised/60 p-4"
                                            aria-busy="true"
                                            aria-label="Loading market price"
                                        >
                                            <Skeleton class="h-4 w-32" />
                                            <Skeleton class="h-7 w-24" />
                                            <Skeleton class="h-3 w-full" />
                                        </div>
                                    </template>
                                    <MarketPriceCard v-if="marketPrice" :market="marketPrice" />
                                </Deferred>
                            </div>
                        </div>
                    </article>

                    <aside class="lg:sticky lg:top-24 lg:self-start">
                        <PurchasePanel :game="game" :low-stock-threshold="lowStockThreshold" />
                    </aside>
                </div>
            </div>
        </div>

        <section v-if="related.length" class="mx-auto mt-16 max-w-7xl px-4 sm:px-6 lg:px-8" aria-labelledby="related-heading">
            <div class="mb-6 flex items-end justify-between gap-4">
                <div>
                    <h2 id="related-heading" class="text-2xl font-semibold">You might also like</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Titles that share its genre or its platform.</p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-4">
                <GameCard v-for="g in related" :key="g.slug" :game="g" :low-stock-threshold="lowStockThreshold" />
            </div>
        </section>
    </StoreLayout>
</template>
