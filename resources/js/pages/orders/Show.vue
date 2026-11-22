<script setup lang="ts">
import GameCover from '@/components/games/GameCover.vue';
import FulfillmentProgress from '@/components/orders/FulfillmentProgress.vue';
import KeyReveal from '@/components/orders/KeyReveal.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import { useOrderPolling } from '@/composables/useOrderPolling';
import StoreLayout from '@/layouts/StoreLayout.vue';
import { formatMoney } from '@/lib/format';
import type { Order } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, Clock, RefreshCw } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{ order: Order }>();

// Local copy that polling keeps fresh while fulfillment runs.
const order = ref<Order>(props.order);
watch(
    () => props.order,
    (fresh) => (order.value = fresh),
);
const { timedOut, restart } = useOrderPolling(order);
</script>

<template>
    <Head :title="`Order ${order.id}`" />
    <StoreLayout>
        <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
            <Link :href="route('orders.index')" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                <ChevronLeft class="size-4" aria-hidden="true" /> My orders
            </Link>

            <header class="relative isolate mt-6 overflow-hidden rounded-2xl border border-white/10 shadow-xl shadow-black/30">
                <div v-if="order.game" class="absolute inset-0 -z-10" aria-hidden="true">
                    <GameCover :slug="order.game.slug" :name="order.game.name" :genre="order.game.genre" format="landscape" decorative />
                    <div class="absolute inset-0 bg-gradient-to-r from-background via-background/85 to-background/40" />
                </div>
                <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:p-6">
                    <span class="aspect-[4/5] w-20 shrink-0 overflow-hidden rounded-xl border border-white/15 shadow-lg shadow-black/40 sm:w-24">
                        <GameCover
                            v-if="order.game"
                            :slug="order.game.slug"
                            :name="order.game.name"
                            :genre="order.game.genre"
                            :cover-url="order.game.cover_url"
                            :show-title="false"
                            decorative
                        />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p v-if="order.game" class="text-[11px] font-medium uppercase tracking-[0.16em] text-[hsl(258_100%_86%)]">
                            {{ order.game.genre }} · {{ order.game.platform === 'Steam' ? 'Steam (PC)' : order.game.platform }}
                        </p>
                        <div class="mt-1 flex flex-wrap items-center gap-3">
                            <h1 class="text-2xl font-bold sm:text-3xl">{{ order.game?.name }}</h1>
                            <OrderStatusBadge :status="order.status" />
                        </div>
                        <p class="mt-1.5 text-sm text-[hsl(224_20%_80%)]">
                            Order <span class="font-mono">{{ order.id }}</span> · {{ formatMoney(order.price_cents, order.currency) }} · simulated
                            payment
                        </p>
                    </div>
                </div>
            </header>

            <div class="mt-8 grid gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <section class="gv-surface p-6" aria-labelledby="progress-heading">
                    <h2 id="progress-heading" class="mb-5 text-sm font-semibold">Delivery</h2>
                    <FulfillmentProgress :order="order" />
                    <p v-if="timedOut" class="mt-5 flex items-center gap-2 rounded-lg bg-warning/10 p-3 text-sm text-warning" role="status">
                        <Clock class="size-4 shrink-0" aria-hidden="true" />
                        Taking longer than usual.
                        <button type="button" class="ml-auto inline-flex items-center gap-1 font-medium underline" @click="restart">
                            <RefreshCw class="size-3.5" aria-hidden="true" /> Check again
                        </button>
                    </p>
                </section>

                <div class="space-y-6">
                    <KeyReveal v-if="order.can_reveal" :order-id="order.id" />
                    <section v-else-if="order.status === 'failed' || order.status === 'cancelled'" class="gv-surface p-6" data-testid="order-failure">
                        <h2 class="text-sm font-semibold">What happened</h2>
                        <p class="mt-2 text-sm text-muted-foreground">{{ order.failure_message ?? 'This order was closed before delivery.' }}</p>
                        <p class="mt-3 text-xs text-muted-foreground">No payment was taken. No key was issued for this order.</p>
                    </section>
                    <section v-else class="gv-surface p-6" aria-live="polite">
                        <h2 class="text-sm font-semibold">Your key is on its way</h2>
                        <p class="mt-2 text-sm text-muted-foreground">
                            This page updates by itself. You can also leave and come back: the key stays in your account.
                        </p>
                    </section>
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
