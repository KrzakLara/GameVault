<script setup lang="ts">
import StockIndicator from '@/components/games/StockIndicator.vue';
import Spinner from '@/components/ui/gv/Spinner.vue';
import { useCheckout } from '@/composables/useCheckout';
import { formatMoney } from '@/lib/format';
import type { Game } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Lock, ShieldCheck, ShoppingBag, Zap } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ game: Game; lowStockThreshold: number }>();

const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);
const stock = computed(() => props.game.stock ?? 0);

const { state, busy, purchase } = useCheckout(props.game.slug, {
    onSoldOut: () => router.reload({ only: ['game'] }), // show the real, current stock
});

const message = computed(() => ('message' in state.value ? state.value.message : null));
const tone = computed(() => (state.value.kind === 'sold_out' || state.value.kind === 'error' ? 'danger' : 'warning'));
</script>

<template>
    <div class="gv-surface gv-glow space-y-5 p-6" data-testid="purchase-panel">
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Price</p>
                <p class="mt-1 font-display text-4xl font-semibold tabular-nums" data-testid="price">
                    {{ formatMoney(game.price_cents, game.currency) }}
                </p>
            </div>
            <StockIndicator :stock="stock" :low-threshold="lowStockThreshold" verbose />
        </div>

        <template v-if="signedIn">
            <button
                type="button"
                class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary text-base font-semibold text-primary-foreground shadow-lg shadow-primary/30 transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="busy || stock <= 0"
                :aria-busy="busy"
                data-testid="buy-button"
                @click="purchase"
            >
                <template v-if="state.kind === 'submitting'">
                    <Spinner :label="state.attempt > 1 ? 'Store busy, retrying…' : 'Placing your order…'" />
                </template>
                <template v-else-if="state.kind === 'redirecting'"><Spinner label="Opening your order…" /></template>
                <template v-else-if="stock <= 0">Sold out</template>
                <template v-else><ShoppingBag class="size-5" aria-hidden="true" /> Buy now · simulated</template>
            </button>
        </template>
        <Link
            v-else
            :href="route('games.buy', game.slug)"
            class="flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-primary/40 bg-primary/10 text-base font-semibold text-foreground transition hover:bg-primary/20"
            data-testid="login-to-buy"
        >
            <Lock class="size-4" aria-hidden="true" /> Sign in to buy
        </Link>

        <p
            v-if="message"
            role="alert"
            :data-testid="`checkout-${state.kind}`"
            :class="[
                'flex items-start gap-2 rounded-lg border p-3 text-sm',
                tone === 'danger'
                    ? 'border-destructive/40 bg-destructive/10 text-[hsl(356_90%_80%)]'
                    : 'border-warning/40 bg-warning/10 text-warning',
            ]"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ message }}
        </p>

        <ul class="space-y-2 border-t border-border/70 pt-4 text-sm text-muted-foreground">
            <li class="flex items-center gap-2"><Zap class="size-4 text-highlight" aria-hidden="true" /> Key delivered to your account in seconds</li>
            <li class="flex items-center gap-2"><ShieldCheck class="size-4 text-highlight" aria-hidden="true" /> Revealed only to you, on request</li>
        </ul>
        <p class="rounded-lg bg-muted/60 px-3 py-2 text-xs text-muted-foreground">
            Simulated checkout: no payment is taken and the key is synthetic. Clicking twice never buys twice.
        </p>
    </div>
</template>
