<script setup lang="ts">
import GameCover from '@/components/games/GameCover.vue';
import PlatformBadge from '@/components/games/PlatformBadge.vue';
import StockIndicator from '@/components/games/StockIndicator.vue';
import { formatMoney } from '@/lib/format';
import type { Game } from '@/types';
import { Link } from '@inertiajs/vue3';

defineProps<{ game: Game; lowStockThreshold?: number }>();
</script>

<template>
    <Link
        :href="route('games.show', game.slug)"
        class="group relative flex flex-col overflow-hidden rounded-2xl border border-white/[0.07] bg-card shadow-[0_1px_0_0_hsl(var(--foreground)/0.04)_inset] transition duration-300 hover:-translate-y-1 hover:border-primary/40 hover:shadow-[0_24px_60px_-24px_hsl(var(--primary)/0.6)] focus-visible:-translate-y-1 motion-reduce:transform-none"
        :data-testid="`game-card-${game.slug}`"
    >
        <div class="relative aspect-[4/5] overflow-hidden bg-raised">
            <div class="size-full transition duration-500 ease-out group-hover:scale-[1.04] motion-reduce:group-hover:scale-100">
                <GameCover :slug="game.slug" :name="game.name" :genre="game.genre" :cover-url="game.cover_url" decorative />
            </div>
            <!-- One row that wraps on narrow cards, so the two badges can never overlap. -->
            <div class="absolute inset-x-3 top-3 flex flex-wrap items-start justify-between gap-1.5">
                <PlatformBadge :platform="game.platform" />
                <span
                    v-if="game.is_featured"
                    class="rounded-md bg-primary px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-primary-foreground shadow-lg shadow-black/30"
                >
                    Featured
                </span>
            </div>
        </div>
        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="min-w-0">
                <p class="text-[11px] font-medium uppercase tracking-[0.14em] text-muted-foreground">{{ game.genre }}</p>
                <h3
                    class="mt-1 line-clamp-1 font-display text-base font-semibold text-foreground transition-colors group-hover:text-[hsl(258_100%_86%)]"
                >
                    {{ game.name }}
                </h3>
            </div>
            <div class="mt-auto flex flex-wrap items-center justify-between gap-x-2 gap-y-1.5 border-t border-white/[0.06] pt-3">
                <span class="font-display text-lg font-bold tabular-nums tracking-tight">{{ formatMoney(game.price_cents, game.currency) }}</span>
                <StockIndicator v-if="game.stock !== undefined" :stock="game.stock" :low-threshold="lowStockThreshold" />
            </div>
        </div>
    </Link>
</template>
