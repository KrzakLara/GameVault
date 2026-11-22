<script setup lang="ts">
import Badge, { type BadgeTone } from '@/components/ui/gv/Badge.vue';
import ConfirmDialog from '@/components/ui/gv/ConfirmDialog.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import Pagination from '@/components/ui/gv/Pagination.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { OutboxMessage, OutboxStats, OutboxStatus, Paginated } from '@/types';
import { router } from '@inertiajs/vue3';
import { Inbox, RotateCcw } from 'lucide-vue-next';
import { ref } from 'vue';

type Filter = 'all' | 'pending' | 'retrying' | 'processing' | 'published' | 'dead_letter';

const props = defineProps<{
    stats: OutboxStats;
    retrying: number;
    messages: Paginated<OutboxMessage>;
    filters: { status: Filter };
    statusFilters: Filter[];
}>();

const labels: Record<Filter, string> = {
    all: 'All',
    pending: 'Pending',
    retrying: 'Retrying',
    processing: 'Processing',
    published: 'Published',
    dead_letter: 'Dead letters',
};
const tones: Record<OutboxStatus, BadgeTone> = { pending: 'cyan', processing: 'violet', published: 'success', dead_letter: 'danger' };

function count(filter: Filter): number | null {
    if (filter === 'all') {
        return null;
    }
    return filter === 'retrying' ? props.retrying : props.stats.counts[filter];
}

function select(filter: Filter) {
    router.get(route('admin.outbox.index'), filter === 'all' ? {} : { status: filter }, {
        only: ['messages', 'filters'],
        preserveState: true,
        replace: true,
    });
}

const busy = ref(false);
const confirmAll = ref(false);
function requeue(message: OutboxMessage) {
    busy.value = true;
    router.post(route('admin.outbox.requeue', message.id), {}, { preserveScroll: true, onFinish: () => (busy.value = false) });
}
function requeueAll() {
    busy.value = true;
    router.post(route('admin.outbox.requeue-all'), {}, { preserveScroll: true, onFinish: () => ((busy.value = false), (confirmAll.value = false)) });
}
</script>

<template>
    <AdminLayout title="Outbox" description="Transactional outbox: delivery e-mails are published after the order commits, at least once.">
        <template #actions>
            <button
                v-if="stats.counts.dead_letter > 0"
                type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground"
                @click="confirmAll = true"
            >
                <RotateCcw class="size-4" aria-hidden="true" /> Requeue all dead letters
            </button>
        </template>

        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Message status">
            <button
                v-for="filter in statusFilters"
                :key="filter"
                type="button"
                role="tab"
                :aria-selected="filters.status === filter"
                :class="[
                    'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition',
                    filters.status === filter
                        ? 'border-primary/50 bg-primary/15 text-foreground'
                        : 'border-border text-muted-foreground hover:bg-accent',
                ]"
                :data-testid="`outbox-filter-${filter}`"
                @click="select(filter)"
            >
                {{ labels[filter] }}
                <span v-if="count(filter) !== null" class="rounded-md bg-muted px-1.5 text-xs tabular-nums">{{ count(filter) }}</span>
            </button>
        </div>
        <p class="text-xs text-muted-foreground">
            Oldest due message: {{ stats.oldest_due_seconds === null ? 'none' : `${stats.oldest_due_seconds}s` }} · expired leases (publisher died):
            {{ stats.expired_leases }}
        </p>

        <div class="gv-surface overflow-hidden">
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm" data-testid="outbox-table">
                    <thead class="border-b border-border/80 bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Event</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 font-medium">Attempts</th>
                            <th scope="col" class="px-4 py-3 font-medium">Timing</th>
                            <th scope="col" class="px-4 py-3 font-medium">Last error</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="m in messages.data" :key="m.id">
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ m.event_id }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :tone="tones[m.status]" dot>{{ m.status.replace('_', ' ') }}</Badge>
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ m.attempts }}</td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                <template v-if="m.status === 'published'">published {{ formatRelative(m.published_at) }}</template>
                                <template v-else-if="m.status === 'dead_letter'">dead-lettered {{ formatRelative(m.dead_lettered_at) }}</template>
                                <template v-else-if="m.status === 'processing'">lease until {{ formatDateTime(m.locked_until) }}</template>
                                <template v-else>due {{ formatRelative(m.available_at) }}</template>
                            </td>
                            <td class="max-w-xs truncate px-4 py-3 text-xs text-muted-foreground" :title="m.last_error ?? undefined">
                                {{ m.last_error ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    v-if="m.status === 'dead_letter'"
                                    type="button"
                                    class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-accent disabled:opacity-50"
                                    :disabled="busy"
                                    @click="requeue(m)"
                                >
                                    Requeue
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!messages.data.length" :icon="Inbox" title="No messages here" class="m-4" />
        </div>
        <Pagination :meta="messages.meta" noun="messages" preserve-scroll />

        <ConfirmDialog
            v-model:open="confirmAll"
            title="Requeue every dead letter?"
            :description="`${stats.counts.dead_letter} message(s) get a fresh retry budget. Do this after fixing the cause. Audited.`"
            confirm-label="Requeue all"
            :busy="busy"
            @confirm="requeueAll"
        />
    </AdminLayout>
</template>
