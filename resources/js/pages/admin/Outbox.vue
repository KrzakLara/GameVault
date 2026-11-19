<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';

type DeadLetter = { id: number; event_id: string; type: string; attempts: number; last_error: string | null; dead_lettered_at: string | null };
defineProps<{ stats: { counts: Record<string, number>; oldest_due_seconds: number | null; expired_leases: number }; deadLetters: DeadLetter[] }>();
const requeue = (id: number) => router.post(`/admin/outbox/${id}/requeue`, {}, { preserveScroll: true });
</script>
<template>
    <main class="min-h-screen bg-[#090b18] px-6 py-8 text-white">
        <div class="mx-auto max-w-5xl space-y-8">
            <header class="flex items-center justify-between"><h1 class="text-3xl font-black">Outbox · Admin</h1><Link href="/admin/orders" class="text-sm text-violet-300">← Orders</Link></header>
            <p v-if="$page.props.flash?.success" class="rounded-lg bg-emerald-500/15 p-3 text-sm text-emerald-200">{{ $page.props.flash.success }}</p>
            <section class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div v-for="(n, s) in stats.counts" :key="s" class="rounded-xl border border-white/10 bg-[#141728] p-4"><div class="text-xs uppercase text-slate-400">{{ s }}</div><div class="text-2xl font-bold">{{ n }}</div></div>
            </section>
            <p class="text-sm text-slate-300">Oldest due message: {{ stats.oldest_due_seconds === null ? 'none' : `${stats.oldest_due_seconds}s` }} · Expired leases: {{ stats.expired_leases }}</p>
            <section>
                <h2 class="mb-2 font-bold">Dead letters</h2>
                <table class="w-full text-left text-sm">
                    <thead class="text-slate-400"><tr><th class="py-2">Event</th><th>Attempts</th><th>Last error</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="m in deadLetters" :key="m.id" class="border-t border-white/10">
                            <td class="py-2 font-mono">{{ m.event_id }}</td>
                            <td>{{ m.attempts }}</td>
                            <td class="max-w-md truncate text-slate-400">{{ m.last_error }}</td>
                            <td><button class="text-violet-300 underline" @click="requeue(m.id)">Requeue</button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="!deadLetters.length" class="text-sm text-slate-400">No dead-lettered messages.</p>
            </section>
        </div>
    </main>
</template>
