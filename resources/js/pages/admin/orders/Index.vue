<script setup lang="ts">
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import ConfirmDialog from '@/components/ui/gv/ConfirmDialog.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import Pagination from '@/components/ui/gv/Pagination.vue';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useAdminOrderActions } from '@/composables/useAdminOrderActions';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatDateTime, formatMoney, formatRelative } from '@/lib/format';
import type { AdminOrder, FailureCode, OrderStatus, Paginated } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Ban, ExternalLink, PackageSearch, RotateCcw } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

const props = defineProps<{
    orders: Paginated<AdminOrder>;
    filters: { status: OrderStatus | null; failure_code: FailureCode | null; q: string };
    statuses: OrderStatus[];
    failureCodes: FailureCode[];
}>();

const form = reactive({ status: props.filters.status ?? '', failure_code: props.filters.failure_code ?? '', q: props.filters.q ?? '' });
const loading = ref(false);

function apply() {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get(route('admin.orders.index'), query, {
        only: ['orders', 'filters'],
        preserveState: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}
const applySearch = useDebounceFn(apply, 350);

const selected = ref<AdminOrder | null>(null);
const confirm = ref<'retry' | 'cancel' | null>(null);
const actions = useAdminOrderActions();

function runConfirmed(reason: string) {
    if (!selected.value) {
        return;
    }
    const done = () => {
        confirm.value = null;
        selected.value = null;
    };
    if (confirm.value === 'retry') {
        actions.retry(selected.value, done);
    } else if (confirm.value === 'cancel') {
        actions.cancel(selected.value, reason, done);
    }
}

const selectClass = 'h-10 rounded-lg border border-input bg-card px-3 text-sm [&>option]:bg-card';
</script>

<template>
    <AdminLayout title="Orders" description="Search, inspect and recover customer orders.">
        <form class="flex flex-col gap-3 sm:flex-row" role="search" aria-label="Filter orders" @submit.prevent="apply">
            <label for="order-search" class="sr-only">Search by order id or customer e-mail</label>
            <input
                id="order-search"
                v-model="form.q"
                type="search"
                placeholder="Order id or customer e-mail…"
                class="h-10 flex-1 rounded-lg border border-input bg-background px-3 text-sm placeholder:text-muted-foreground"
                data-testid="admin-order-search"
                @input="applySearch"
            />
            <label for="order-status" class="sr-only">Status</label>
            <select id="order-status" v-model="form.status" :class="selectClass" data-testid="admin-filter-status" @change="apply">
                <option value="">Any status</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
            </select>
            <label for="order-failure" class="sr-only">Failure reason</label>
            <select id="order-failure" v-model="form.failure_code" :class="selectClass" @change="apply">
                <option value="">Any failure</option>
                <option v-for="f in failureCodes" :key="f" :value="f">{{ f.replaceAll('_', ' ') }}</option>
            </select>
        </form>

        <div class="gv-surface overflow-hidden" :aria-busy="loading">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm" data-testid="admin-orders-table">
                    <thead class="border-b border-border/80 bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Order</th>
                            <th scope="col" class="px-4 py-3 font-medium">Customer</th>
                            <th scope="col" class="px-4 py-3 font-medium">Game</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 font-medium">Attempts</th>
                            <th scope="col" class="px-4 py-3 font-medium">Placed</th>
                        </tr>
                    </thead>
                    <tbody :class="['divide-y divide-border/60 transition-opacity', loading ? 'opacity-50' : '']">
                        <tr
                            v-for="order in orders.data"
                            :key="order.id"
                            class="cursor-pointer focus-within:bg-accent/40 hover:bg-accent/40"
                            :data-testid="`admin-order-${order.public_id}`"
                            @click="selected = order"
                        >
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    class="font-mono text-xs text-[hsl(258_100%_84%)] hover:underline"
                                    @click.stop="selected = order"
                                >
                                    {{ order.public_id }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ order.user?.email ?? 'deleted account' }}</td>
                            <td class="px-4 py-3">{{ order.game?.name }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    <OrderStatusBadge :status="order.status" />
                                    <span v-if="order.failure_code" class="text-xs text-muted-foreground">{{
                                        order.failure_code.replaceAll('_', ' ')
                                    }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-muted-foreground">{{ order.attempts }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ formatRelative(order.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-if="!orders.data.length"
                :icon="PackageSearch"
                title="No orders match"
                description="Adjust the filters or search term."
                class="m-4"
            />
        </div>

        <Pagination :meta="orders.meta" noun="orders" preserve-scroll />

        <Sheet :open="selected !== null" @update:open="(open) => !open && (selected = null)">
            <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-md">
                <template v-if="selected">
                    <SheetHeader>
                        <SheetTitle class="font-display">{{ selected.game?.name }}</SheetTitle>
                        <SheetDescription class="font-mono text-xs">{{ selected.public_id }}</SheetDescription>
                    </SheetHeader>
                    <dl class="mt-6 space-y-3 text-sm" data-testid="order-drawer">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Status</dt>
                            <dd><OrderStatusBadge :status="selected.status" /></dd>
                        </div>
                        <div v-if="selected.failure_code" class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Failure</dt>
                            <dd>{{ selected.failure_code.replaceAll('_', ' ') }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Customer</dt>
                            <dd class="truncate">{{ selected.user?.email ?? 'deleted account' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Price</dt>
                            <dd>{{ formatMoney(selected.price_cents, selected.currency) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Attempts this cycle</dt>
                            <dd>{{ selected.attempts }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Key</dt>
                            <dd>{{ selected.key ? `#${selected.key.id} · ${selected.key.status}` : 'none' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Placed</dt>
                            <dd>{{ formatDateTime(selected.created_at) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground">Key codes are never shown to administrators.</p>
                    <div class="mt-6 flex flex-col gap-2">
                        <button
                            v-if="selected.status === 'failed' && selected.retryable"
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
                        <button
                            v-if="selected.status === 'failed'"
                            type="button"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-destructive/50 px-4 py-2 text-sm font-semibold text-[hsl(356_90%_76%)] hover:bg-destructive/10"
                            @click="
                                actions.reset();
                                confirm = 'cancel';
                            "
                        >
                            <Ban class="size-4" aria-hidden="true" /> Cancel order
                        </button>
                        <Link
                            :href="route('admin.orders.show', selected.public_id)"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-border px-4 py-2 text-sm hover:bg-accent"
                        >
                            Full timeline <ExternalLink class="size-4" aria-hidden="true" />
                        </Link>
                    </div>
                </template>
            </SheetContent>
        </Sheet>

        <ConfirmDialog
            :open="confirm === 'retry'"
            title="Retry fulfillment?"
            description="A key is reserved again (if in stock) and delivery is re-queued. The action is audited."
            confirm-label="Retry"
            :busy="actions.busy.value"
            :error="actions.error.value"
            @update:open="(v) => !v && (confirm = null)"
            @confirm="runConfirmed"
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
            @confirm="runConfirmed"
        />
    </AdminLayout>
</template>
