<script setup lang="ts">
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import StatCard from '@/components/ui/gv/StatCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { AuthenticatedPageProps, Order } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Clock3, Package, PackageOpen, XCircle } from 'lucide-vue-next';

defineProps<{ stats: { total: number; delivered: number; in_progress: number; unsuccessful: number }; recentOrders: Order[] }>();

const page = usePage<AuthenticatedPageProps>();
</script>

<template>
    <Head title="My account" />
    <AppLayout :breadcrumbs="[{ title: 'My account', href: '/dashboard' }]">
        <h1 class="text-3xl font-bold">Welcome back, {{ page.props.auth.user.name.split(' ')[0] }}</h1>
        <p class="mt-2 text-muted-foreground">Your purchases at a glance.</p>

        <div class="mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard label="Orders" :value="stats.total" :icon="Package" />
            <StatCard label="Delivered" :value="stats.delivered" :icon="CheckCircle2" tone="success" />
            <StatCard label="In progress" :value="stats.in_progress" :icon="Clock3" tone="cyan" />
            <StatCard label="Not delivered" :value="stats.unsuccessful" :icon="XCircle" tone="danger" />
        </div>

        <section class="mt-10" aria-labelledby="recent-heading">
            <div class="mb-4 flex items-center justify-between">
                <h2 id="recent-heading" class="text-xl font-semibold">Recent orders</h2>
                <Link :href="route('orders.index')" class="text-sm font-medium text-[hsl(258_100%_84%)] hover:underline">View all</Link>
            </div>
            <ul v-if="recentOrders.length" class="divide-y divide-border/70 overflow-hidden rounded-xl border border-border/80 bg-card">
                <li v-for="order in recentOrders" :key="order.id">
                    <Link :href="route('orders.show', order.id)" class="flex items-center gap-4 px-4 py-3 transition hover:bg-accent/50">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ order.game?.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ formatDateTime(order.created_at) }}</span>
                        </span>
                        <span class="text-sm tabular-nums text-muted-foreground">{{ formatMoney(order.price_cents, order.currency) }}</span>
                        <OrderStatusBadge :status="order.status" />
                    </Link>
                </li>
            </ul>
            <EmptyState v-else :icon="PackageOpen" title="No orders yet" description="Find your next game in the catalogue.">
                <Link :href="route('games.index')" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                    >Browse games</Link
                >
            </EmptyState>
        </section>
    </AppLayout>
</template>
