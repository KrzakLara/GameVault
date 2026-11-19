<script setup lang="ts">
import { formatAmount, formatRelative } from '@/lib/format';
import type { MarketPrice } from '@/types';
import { CircleSlash, LineChart, WifiOff } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ market: MarketPrice }>();

const offline = computed(
    () => props.market.status === 'unavailable' && ['temporarily_unavailable', 'rate_limited', 'invalid_response'].includes(props.market.reason),
);
</script>

<template>
    <section class="rounded-xl border border-border/80 bg-raised/60 p-4" aria-labelledby="market-heading" data-testid="market-price">
        <div class="flex items-center gap-2">
            <LineChart class="size-4 text-highlight" aria-hidden="true" />
            <h2 id="market-heading" class="text-sm font-semibold">Market reference</h2>
        </div>

        <template v-if="market.status === 'available'">
            <p class="mt-3 flex items-baseline gap-2">
                <span class="font-display text-2xl font-semibold tabular-nums">{{ formatAmount(market.lowest, market.currency) }}</span>
                <span v-if="market.retail && market.retail > market.lowest" class="text-sm text-muted-foreground line-through">
                    {{ formatAmount(market.retail, market.currency) }}
                </span>
                <span v-if="market.savings_percent" class="text-sm font-medium text-success">−{{ market.savings_percent }}%</span>
            </p>
            <p class="mt-2 text-xs text-muted-foreground">
                Lowest of {{ market.deal_count }} {{ market.scope }} offers via {{ market.source }}, in {{ market.currency }} · updated
                {{ formatRelative(market.fetched_at) }}. A different market and currency: not directly comparable with this demo's EUR price.
            </p>
        </template>

        <p v-else class="mt-3 flex items-start gap-2 text-sm text-muted-foreground" :data-testid="`market-${market.reason}`">
            <component :is="offline ? WifiOff : CircleSlash" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ market.message }}
        </p>
    </section>
</template>
