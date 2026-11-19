<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

type AdminOrder = {
    public_id: string;
    status: string;
    failure_code: string | null;
    retryable: boolean;
    attempts: number;
    lease_expires_at: string | null;
    user: { name: string; email: string } | null;
    game: { name: string };
    key: { id: number; status: string; reserved_at: string | null; sold_at: string | null } | null;
};
type Entry = { type?: string; action?: string; from?: string | null; to?: string | null; actor: string | null; meta: Record<string, unknown> | null; at: string };
const props = defineProps<{ order: AdminOrder; timeline: Entry[]; movements: { type: string; key_id: number; at: string }[]; audit: Entry[] }>();

const retryForm = useForm({ note: '' });
const cancelForm = useForm({ reason: '' });
const retry = () => retryForm.post(`/admin/orders/${props.order.public_id}/retry`, { preserveScroll: true });
const cancel = () => {
    if (confirm('Cancel this order permanently?')) cancelForm.post(`/admin/orders/${props.order.public_id}/cancel`, { preserveScroll: true });
};
</script>
<template>
    <main class="min-h-screen bg-[#090b18] px-6 py-8 text-white">
        <div class="mx-auto max-w-5xl space-y-8">
            <header class="flex items-center justify-between"><h1 class="font-mono text-2xl font-black">{{ order.public_id }}</h1><Link href="/admin/orders" class="text-sm text-violet-300">← Orders</Link></header>
            <p v-if="$page.props.flash?.success" class="rounded-lg bg-emerald-500/15 p-3 text-sm text-emerald-200">{{ $page.props.flash.success }}</p>
            <p v-if="$page.props.errors?.order" role="alert" class="rounded-lg bg-rose-500/15 p-3 text-sm text-rose-200">{{ $page.props.errors.order }}</p>
            <section class="grid gap-2 text-sm sm:grid-cols-2">
                <div>Status: <strong>{{ order.status }}</strong> <span v-if="order.failure_code">({{ order.failure_code }})</span></div>
                <div>Attempts this cycle: {{ order.attempts }}</div>
                <div>Customer: {{ order.user?.email ?? '(deleted user)' }}</div>
                <div>Game: {{ order.game.name }}</div>
                <div>Key: <span v-if="order.key">#{{ order.key.id }} · {{ order.key.status }}</span><span v-else>none</span></div>
                <div>Lease expires: {{ order.lease_expires_at ?? '—' }}</div>
            </section>
            <section v-if="order.status === 'failed'" class="flex flex-wrap gap-6">
                <form v-if="order.retryable" @submit.prevent="retry" class="flex gap-2"><input v-model="retryForm.note" placeholder="Note (optional)" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2" /><button :disabled="retryForm.processing" class="rounded-lg bg-violet-500 px-4 py-2 font-semibold">Retry</button></form>
                <form @submit.prevent="cancel" class="flex gap-2"><input v-model="cancelForm.reason" required minlength="5" placeholder="Reason (required)" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2" /><button :disabled="cancelForm.processing" class="rounded-lg bg-rose-600 px-4 py-2 font-semibold">Cancel order</button></form>
                <p v-if="cancelForm.errors.reason" class="text-sm text-rose-300">{{ cancelForm.errors.reason }}</p>
            </section>
            <section><h2 class="mb-2 font-bold">Timeline</h2><ol class="space-y-1 text-sm text-slate-300"><li v-for="(e, i) in timeline" :key="i">{{ new Date(e.at).toLocaleString() }} · {{ e.type }} <span v-if="e.to">{{ e.from }} → {{ e.to }}</span> <span v-if="e.actor">by {{ e.actor }}</span> <code v-if="e.meta" class="text-xs text-slate-500">{{ JSON.stringify(e.meta) }}</code></li></ol></section>
            <section><h2 class="mb-2 font-bold">Inventory movements</h2><ol class="space-y-1 text-sm text-slate-300"><li v-for="(m, i) in movements" :key="i">{{ new Date(m.at).toLocaleString() }} · {{ m.type }} · key #{{ m.key_id }}</li></ol></section>
            <section><h2 class="mb-2 font-bold">Audit</h2><ol class="space-y-1 text-sm text-slate-300"><li v-for="(a, i) in audit" :key="i">{{ new Date(a.at).toLocaleString() }} · {{ a.action }} by {{ a.actor ?? 'system' }} <code v-if="a.meta" class="text-xs text-slate-500">{{ JSON.stringify(a.meta) }}</code></li></ol></section>
        </div>
    </main>
</template>
