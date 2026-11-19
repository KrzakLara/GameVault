<script setup lang="ts">
import GameCover from '@/components/games/GameCover.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import Pagination from '@/components/ui/gv/Pagination.vue';
import StoreLayout from '@/layouts/StoreLayout.vue';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { Order, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, PackageOpen } from 'lucide-vue-next';

defineProps<{ orders: Paginated<Order> }>();
</script>

<template>
    <Head title="My orders" />
    <StoreLayout>
        <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
            <header class="mb-8">
                <h1 class="text-3xl font-bold">My orders</h1>
                <p class="mt-2 text-muted-foreground">Every key you have bought, with its delivery status.</p>
            </header>

            <ul v-if="orders.data.length" class="space-y-3" data-testid="order-list">
                <li v-for="order in orders.data" :key="order.id">
                    <Link
                        :href="route('orders.show', order.id)"
                        class="gv-surface group flex items-center gap-4 p-4 transition hover:border-primary/50"
                        :data-testid="`order-row-${order.id}`"
                    >
                        <span class="size-16 shrink-0 overflow-hidden rounded-lg">
                            <GameCover
                                v-if="order.game"
                                :slug="order.game.slug"
                                :name="order.game.name"
                                :platform="order.game.platform"
                                :cover-url="order.game.cover_url"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold">{{ order.game?.name }}</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground"
                                >{{ formatDateTime(order.created_at) }} · <span class="font-mono">{{ order.id }}</span></span
                            >
                            <span v-if="order.failure_message" class="mt-1 block text-xs text-[hsl(356_90%_76%)]">{{ order.failure_message }}</span>
                        </span>
                        <span class="flex flex-col items-end gap-2">
                            <OrderStatusBadge :status="order.status" />
                            <span class="text-sm font-medium tabular-nums">{{ formatMoney(order.price_cents, order.currency) }}</span>
                        </span>
                        <ChevronRight class="size-5 text-muted-foreground transition group-hover:text-foreground" aria-hidden="true" />
                    </Link>
                </li>
            </ul>
            <EmptyState v-else :icon="PackageOpen" title="No orders yet" description="Keys you buy appear here the moment you check out.">
                <Link :href="route('games.index')" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                    >Browse the catalogue</Link
                >
            </EmptyState>

            <div class="mt-8"><Pagination :meta="orders.meta" noun="orders" preserve-scroll /></div>
        </div>
    </StoreLayout>
</template>
