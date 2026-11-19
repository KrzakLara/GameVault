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
        class="group flex flex-col overflow-hidden rounded-xl border border-border/80 bg-card transition duration-200 hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-[0_18px_50px_-20px_hsl(var(--primary)/0.55)] motion-reduce:hover:translate-y-0"
        :data-testid="`game-card-${game.slug}`"
    >
        <div class="relative aspect-[4/5] overflow-hidden">
            <div class="size-full transition duration-500 group-hover:scale-[1.03] motion-reduce:group-hover:scale-100">
                <GameCover :slug="game.slug" :name="game.name" :platform="game.platform" :cover-url="game.cover_url" />
            </div>
            <div class="absolute left-3 top-3"><PlatformBadge :platform="game.platform" /></div>
            <span
                v-if="game.is_featured"
                class="absolute right-3 top-3 rounded-md bg-primary/90 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-primary-foreground"
            >
                Featured
            </span>
        </div>
        <div class="flex flex-1 flex-col gap-3 p-4">
            <div>
                <h3 class="line-clamp-1 font-display text-base font-semibold text-foreground group-hover:text-[hsl(258_100%_84%)]">
                    {{ game.name }}
                </h3>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ game.genre }}</p>
            </div>
            <div class="mt-auto flex items-center justify-between gap-2">
                <span class="font-display text-lg font-semibold tabular-nums">{{ formatMoney(game.price_cents, game.currency) }}</span>
                <StockIndicator v-if="game.stock !== undefined" :stock="game.stock" :low-threshold="lowStockThreshold" />
            </div>
        </div>
    </Link>
</template>
