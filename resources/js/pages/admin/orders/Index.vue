<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type AdminOrder = { id: number; public_id: string; status: string; failure_code: string | null; attempts: number; created_at: string; user: { name: string; email: string } | null; game: { name: string } };
const props = defineProps<{
    orders: { data: AdminOrder[]; links: { prev: string | null; next: string | null } };
    filters: { status: string | null; failure_code: string | null; q: string };
    statuses: string[];
    failureCodes: string[];
}>();
const status = ref(props.filters.status ?? '');
const failureCode = ref(props.filters.failure_code ?? '');
const q = ref(props.filters.q);
const apply = () => router.get('/admin/orders', { status: status.value, failure_code: failureCode.value, q: q.value }, { preserveState: true, replace: true });
</script>
<template>
    <main class="min-h-screen bg-[#090b18] px-6 py-8 text-white">
        <div class="mx-auto max-w-6xl">
            <header class="flex items-center justify-between"><h1 class="text-3xl font-black">Orders · Admin</h1><Link href="/admin/outbox" class="text-sm text-violet-300">Outbox →</Link></header>
            <div class="mt-6 flex flex-wrap gap-3">
                <select v-model="status" aria-label="Status" class="rounded-lg border border-white/10 bg-[#17182c] px-3 py-2"><option value="">Any status</option><option v-for="s in statuses" :key="s">{{ s }}</option></select>
                <select v-model="failureCode" aria-label="Failure code" class="rounded-lg border border-white/10 bg-[#17182c] px-3 py-2"><option value="">Any failure</option><option v-for="f in failureCodes" :key="f">{{ f }}</option></select>
                <input v-model="q" @keyup.enter="apply" aria-label="Order id" placeholder="Order id prefix" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2" />
                <button @click="apply" class="rounded-lg bg-violet-500 px-4 py-2 font-semibold">Filter</button>
            </div>
            <table class="mt-6 w-full text-left text-sm">
                <thead class="text-slate-400"><tr><th class="py-2">Order</th><th>Customer</th><th>Game</th><th>Status</th><th>Failure</th><th>Attempts</th></tr></thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id" class="border-t border-white/10">
                        <td class="py-2 font-mono"><Link :href="`/admin/orders/${o.public_id}`" class="text-violet-300">{{ o.public_id }}</Link></td>
                        <td>{{ o.user?.email ?? '(deleted user)' }}</td>
                        <td>{{ o.game.name }}</td>
                        <td>{{ o.status }}</td>
                        <td>{{ o.failure_code ?? '—' }}</td>
                        <td>{{ o.attempts }}</td>
                    </tr>
                </tbody>
            </table>
            <div class="mt-6 flex gap-4"><Link v-if="orders.links.prev" :href="orders.links.prev" class="text-violet-300">← Previous</Link><Link v-if="orders.links.next" :href="orders.links.next" class="text-violet-300">Next →</Link></div>
        </div>
    </main>
</template>
