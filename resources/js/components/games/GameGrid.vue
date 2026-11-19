<script setup lang="ts">
import GameCard from '@/components/games/GameCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { Game } from '@/types';

withDefaults(defineProps<{ games: Game[]; loading?: boolean; skeletons?: number; lowStockThreshold?: number }>(), { loading: false, skeletons: 8 });
</script>

<template>
    <div class="grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3 xl:grid-cols-4" :aria-busy="loading">
        <template v-if="loading">
            <div v-for="i in skeletons" :key="i" class="overflow-hidden rounded-xl border border-border/80 bg-card" aria-hidden="true">
                <Skeleton class="aspect-[4/5] w-full rounded-none" />
                <div class="space-y-3 p-4">
                    <Skeleton class="h-4 w-3/4" />
                    <Skeleton class="h-3 w-1/3" />
                    <Skeleton class="h-5 w-1/2" />
                </div>
            </div>
        </template>
        <GameCard v-for="game in games" v-else :key="game.slug" :game="game" :low-stock-threshold="lowStockThreshold" />
    </div>
</template>
