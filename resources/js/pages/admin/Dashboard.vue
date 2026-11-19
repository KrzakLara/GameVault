<script setup lang="ts">
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import Badge from '@/components/ui/gv/Badge.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import StatCard from '@/components/ui/gv/StatCard.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatRelative } from '@/lib/format';
import type { AdminOrder, OutboxStats, Platform } from '@/types';
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, Boxes, CheckCircle2, Clock3, KeyRound, Package, PackageCheck, Send, XCircle } from 'lucide-vue-next';

defineProps<{
    orders: { total: number; delivered: number; in_flight: number; failed: number; cancelled: number };
    inventory: { available: number; reserved: number; sold: number };
    lowStock: { threshold: number; games: { slug: string; name: string; platform: Platform; stock: number }[] };
    outbox: OutboxStats;
    recentFailures: AdminOrder[];
}>();
</script>

<template>
    <AdminLayout title="Overview" description="Live figures from the database. Nothing here is sampled or estimated.">
        <section aria-labelledby="orders-heading">
            <h2 id="orders-heading" class="sr-only">Orders</h2>
            <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard label="Total orders" :value="orders.total.toLocaleString('en')" :icon="Package" />
                <StatCard label="Delivered" :value="orders.delivered.toLocaleString('en')" :icon="CheckCircle2" tone="success" />
                <StatCard
                    label="In flight"
                    :value="orders.in_flight.toLocaleString('en')"
                    hint="Pending or being delivered"
                    :icon="Clock3"
                    tone="cyan"
                />
                <StatCard
                    label="Failed"
                    :value="orders.failed.toLocaleString('en')"
                    :hint="`${orders.cancelled} cancelled`"
                    :icon="XCircle"
                    tone="danger"
                />
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="gv-surface p-5 xl:col-span-1" aria-labelledby="inventory-heading">
                <div class="flex items-center justify-between">
                    <h2 id="inventory-heading" class="flex items-center gap-2 font-semibold">
                        <Boxes class="size-4 text-primary" aria-hidden="true" /> Inventory
                    </h2>
                    <Link :href="route('admin.inventory.index')" class="text-sm text-[hsl(258_100%_84%)] hover:underline">Manage</Link>
                </div>
                <dl class="mt-4 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg bg-success/10 p-3">
                        <dt class="text-xs text-muted-foreground">Available</dt>
                        <dd class="mt-1 font-display text-xl font-semibold text-success">{{ inventory.available }}</dd>
                    </div>
                    <div class="rounded-lg bg-highlight/10 p-3">
                        <dt class="text-xs text-muted-foreground">Reserved</dt>
                        <dd class="mt-1 font-display text-xl font-semibold text-highlight">{{ inventory.reserved }}</dd>
                    </div>
                    <div class="rounded-lg bg-muted p-3">
                        <dt class="text-xs text-muted-foreground">Sold</dt>
                        <dd class="mt-1 font-display text-xl font-semibold">{{ inventory.sold }}</dd>
                    </div>
                </dl>
                <h3 class="mt-6 flex items-center gap-2 text-sm font-medium">
                    <AlertTriangle class="size-4 text-warning" aria-hidden="true" /> Low stock (fewer than {{ lowStock.threshold }})
                </h3>
                <ul v-if="lowStock.games.length" class="mt-3 space-y-2" data-testid="low-stock">
                    <li v-for="game in lowStock.games" :key="game.slug" class="flex items-center justify-between gap-2 text-sm">
                        <span class="truncate"
                            >{{ game.name }} <span class="text-muted-foreground">· {{ game.platform }}</span></span
                        >
                        <Badge :tone="game.stock === 0 ? 'danger' : 'warning'">{{ game.stock === 0 ? 'Sold out' : `${game.stock} left` }}</Badge>
                    </li>
                </ul>
                <p v-else class="mt-3 text-sm text-muted-foreground">Every active game is well stocked.</p>
            </section>

            <section class="gv-surface p-5" aria-labelledby="outbox-heading">
                <div class="flex items-center justify-between">
                    <h2 id="outbox-heading" class="flex items-center gap-2 font-semibold">
                        <Send class="size-4 text-primary" aria-hidden="true" /> Notifications outbox
                    </h2>
                    <Link :href="route('admin.outbox.index')" class="text-sm text-[hsl(258_100%_84%)] hover:underline">Open</Link>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-muted p-3">
                        <dt class="text-xs text-muted-foreground">Pending</dt>
                        <dd class="mt-1 font-display text-xl font-semibold">{{ outbox.counts.pending }}</dd>
                    </div>
                    <div class="rounded-lg bg-muted p-3">
                        <dt class="text-xs text-muted-foreground">Processing</dt>
                        <dd class="mt-1 font-display text-xl font-semibold">{{ outbox.counts.processing }}</dd>
                    </div>
                    <div class="rounded-lg bg-success/10 p-3">
                        <dt class="text-xs text-muted-foreground">Published</dt>
                        <dd class="mt-1 font-display text-xl font-semibold text-success">{{ outbox.counts.published }}</dd>
                    </div>
                    <div class="rounded-lg p-3" :class="outbox.counts.dead_letter ? 'bg-destructive/10' : 'bg-muted'">
                        <dt class="text-xs text-muted-foreground">Dead letters</dt>
                        <dd class="mt-1 font-display text-xl font-semibold" :class="outbox.counts.dead_letter ? 'text-destructive' : ''">
                            {{ outbox.counts.dead_letter }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-muted-foreground">
                    Oldest due message: {{ outbox.oldest_due_seconds === null ? 'none' : `${outbox.oldest_due_seconds}s` }} · expired leases:
                    {{ outbox.expired_leases }}
                </p>
            </section>

            <section class="gv-surface p-5" aria-labelledby="failures-heading">
                <div class="flex items-center justify-between">
                    <h2 id="failures-heading" class="flex items-center gap-2 font-semibold">
                        <PackageCheck class="size-4 text-primary" aria-hidden="true" /> Recent failures
                    </h2>
                    <Link :href="route('admin.orders.index', { status: 'failed' })" class="text-sm text-[hsl(258_100%_84%)] hover:underline"
                        >All failed</Link
                    >
                </div>
                <ul v-if="recentFailures.length" class="mt-4 space-y-3">
                    <li v-for="order in recentFailures" :key="order.id">
                        <Link
                            :href="route('admin.orders.show', order.public_id)"
                            class="flex items-center justify-between gap-2 rounded-lg p-2 text-sm hover:bg-accent"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ order.game?.name }}</span>
                                <span class="text-xs text-muted-foreground">{{ order.failure_code }} · {{ formatRelative(order.failed_at) }}</span>
                            </span>
                            <OrderStatusBadge :status="order.status" />
                        </Link>
                    </li>
                </ul>
                <EmptyState v-else :icon="KeyRound" title="No failed orders" class="mt-4 py-8" />
            </section>
        </div>
    </AdminLayout>
</template>
