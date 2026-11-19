<script setup lang="ts">
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import ConfirmDialog from '@/components/ui/gv/ConfirmDialog.vue';
import { useAdminOrderActions } from '@/composables/useAdminOrderActions';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { AdminOrder, AdminTimelineEntry } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Ban, ChevronLeft, RotateCcw } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    order: AdminOrder;
    timeline: AdminTimelineEntry[];
    movements: { type: string; key_id: number; at: string }[];
    audit: { action: string; actor: string | null; meta: Record<string, unknown> | null; at: string }[];
}>();

const confirm = ref<'retry' | 'cancel' | null>(null);
const actions = useAdminOrderActions();

function run(reason: string) {
    const done = () => (confirm.value = null);
    if (confirm.value === 'retry') {
        actions.retry(props.order, done);
    } else if (confirm.value === 'cancel') {
        actions.cancel(props.order, reason, done);
    }
}

const metaText = (meta: Record<string, unknown> | null) =>
    meta
        ? Object.entries(meta)
              .map(([k, v]) => `${k}: ${String(v)}`)
              .join(' · ')
        : '';
</script>

<template>
    <AdminLayout :title="order.game?.name ?? 'Order'" :description="order.public_id">
        <Link :href="route('admin.orders.index')" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <ChevronLeft class="size-4" aria-hidden="true" /> All orders
        </Link>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="gv-surface space-y-4 p-5" aria-labelledby="summary-heading">
                <div class="flex items-center justify-between">
                    <h2 id="summary-heading" class="font-semibold">Summary</h2>
                    <OrderStatusBadge :status="order.status" />
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Customer</dt>
                        <dd class="truncate">{{ order.user?.email ?? 'deleted account' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Price</dt>
                        <dd>{{ formatMoney(order.price_cents, order.currency) }}</dd>
                    </div>
                    <div v-if="order.failure_code" class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Failure</dt>
                        <dd>{{ order.failure_code }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Attempts this cycle</dt>
                        <dd>{{ order.attempts }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Lease expires</dt>
                        <dd>{{ formatDateTime(order.lease_expires_at) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Key</dt>
                        <dd>{{ order.key ? `#${order.key.id} · ${order.key.status}` : 'none' }}</dd>
                    </div>
                </dl>
                <div v-if="order.status === 'failed'" class="flex flex-col gap-2 border-t border-border/70 pt-4">
                    <button
                        v-if="order.retryable"
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                        data-testid="retry-order"
                        @click="
                            actions.reset();
                            confirm = 'retry';
                        "
                    >
                        <RotateCcw class="size-4" aria-hidden="true" /> Retry fulfillment
                    </button>
                    <p v-else class="text-xs text-muted-foreground">This failure needs investigation and cannot be retried.</p>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-destructive/50 px-4 py-2 text-sm font-semibold text-[hsl(356_90%_76%)] hover:bg-destructive/10"
                        @click="
                            actions.reset();
                            confirm = 'cancel';
                        "
                    >
                        <Ban class="size-4" aria-hidden="true" /> Cancel order
                    </button>
                </div>
            </section>

            <section class="gv-surface p-5 xl:col-span-2" aria-labelledby="timeline-heading">
                <h2 id="timeline-heading" class="font-semibold">Fulfillment timeline</h2>
                <ol class="mt-4 space-y-3 text-sm" data-testid="admin-timeline">
                    <li v-for="(entry, i) in timeline" :key="i" class="flex gap-3">
                        <span class="mt-1.5 size-2 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                        <div class="min-w-0">
                            <p>
                                <span class="font-medium">{{ entry.type.replaceAll('_', ' ') }}</span>
                                <span v-if="entry.to" class="text-muted-foreground"> · {{ entry.from }} → {{ entry.to }}</span>
                                <span v-if="entry.actor" class="text-muted-foreground"> · by {{ entry.actor }}</span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatDateTime(entry.at) }}<template v-if="entry.meta"> · {{ metaText(entry.meta) }}</template>
                            </p>
                        </div>
                    </li>
                </ol>
            </section>

            <section class="gv-surface p-5" aria-labelledby="movements-heading">
                <h2 id="movements-heading" class="font-semibold">Inventory movements</h2>
                <ul class="mt-4 space-y-2 text-sm">
                    <li v-for="(m, i) in movements" :key="i" class="flex justify-between gap-2">
                        <span>{{ m.type }} · key #{{ m.key_id }}</span
                        ><span class="text-xs text-muted-foreground">{{ formatDateTime(m.at) }}</span>
                    </li>
                    <li v-if="!movements.length" class="text-muted-foreground">No movements.</li>
                </ul>
            </section>

            <section class="gv-surface p-5 xl:col-span-2" aria-labelledby="audit-heading">
                <h2 id="audit-heading" class="font-semibold">Audit trail</h2>
                <ul class="mt-4 space-y-2 text-sm">
                    <li v-for="(a, i) in audit" :key="i">
                        <span class="font-medium">{{ a.action }}</span>
                        <span class="text-muted-foreground">by {{ a.actor ?? 'system' }} · {{ formatDateTime(a.at) }}</span>
                        <p v-if="a.meta" class="text-xs text-muted-foreground">{{ metaText(a.meta) }}</p>
                    </li>
                    <li v-if="!audit.length" class="text-muted-foreground">No privileged actions recorded for this order.</li>
                </ul>
            </section>
        </div>

        <ConfirmDialog
            :open="confirm === 'retry'"
            title="Retry fulfillment?"
            description="A key is reserved again (if in stock) and delivery is re-queued. The action is audited."
            confirm-label="Retry"
            :busy="actions.busy.value"
            :error="actions.error.value"
            @update:open="(v) => !v && (confirm = null)"
            @confirm="run"
        />
        <ConfirmDialog
            :open="confirm === 'cancel'"
            title="Cancel this order permanently?"
            description="The order can no longer be retried. A quarantined key stays off sale."
            confirm-label="Cancel order"
            destructive
            reason-label="Reason for cancelling"
            :busy="actions.busy.value"
            :error="actions.error.value"
            @update:open="(v) => !v && (confirm = null)"
            @confirm="run"
        />
    </AdminLayout>
</template>
