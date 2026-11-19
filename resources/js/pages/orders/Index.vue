<script setup lang="ts">
import { useKeyReveal } from '@/composables/useKeyReveal';
import { Link } from '@inertiajs/vue3';

type Order = { id: string; status: string; created_at: string; price_cents: number; currency: string; can_reveal: boolean; failure_message: string | null; game: { name: string; platform: string } };
defineProps<{ orders: { data: Order[]; links: { prev: string | null; next: string | null } } }>();
const { revealed, errors, busy, reveal, hide } = useKeyReveal();
const badge = (status: string) => (status === 'delivered' ? 'bg-emerald-500/20 text-emerald-300' : status === 'failed' || status === 'cancelled' ? 'bg-rose-500/20 text-rose-300' : 'bg-amber-500/20 text-amber-300');
</script>
<template>
    <main class="min-h-screen bg-[#090b18] px-6 py-8 text-white">
        <div class="mx-auto max-w-5xl">
            <header class="flex items-center justify-between"><Link href="/games" class="text-2xl font-black">GAME<span class="text-violet-400">VAULT</span></Link><Link href="/games" class="text-sm text-violet-300">← Browse games</Link></header>
            <h1 class="mb-3 mt-16 text-5xl font-black">Your collection.</h1>
            <p v-if="$page.props.flash?.success" class="mb-6 rounded-lg bg-emerald-500/15 p-3 text-sm text-emerald-200">{{ $page.props.flash.success }}</p>
            <div class="space-y-4">
                <article v-for="order in orders.data" :key="order.id" class="flex flex-wrap items-center gap-5 rounded-2xl border border-white/10 bg-[#141728] p-6">
                    <div class="flex h-16 w-16 items-center justify-center rounded-xl bg-violet-700/40 font-bold">GV</div>
                    <div class="min-w-48 flex-1">
                        <h2 class="font-bold"><Link :href="`/my-orders/${order.id}`" class="hover:text-violet-300">{{ order.game.name }}</Link></h2>
                        <p class="mt-1 font-mono text-xs text-slate-400">{{ order.id }} · {{ order.game.platform }}</p>
                        <p v-if="order.failure_message" class="mt-1 text-xs text-rose-300">{{ order.failure_message }}</p>
                    </div>
                    <div class="text-right">
                        <span class="rounded-lg px-3 py-1 text-xs font-semibold" :class="badge(order.status)">{{ order.status }}</span>
                        <p class="mt-3 font-mono text-sm">{{ revealed[order.id] || 'XXXXX-XXXXX-XXXXX' }}</p>
                        <button v-if="order.can_reveal" @click="revealed[order.id] ? hide(order.id) : reveal(order.id)" class="mt-2 text-xs text-violet-300 underline" :disabled="busy[order.id]">{{ busy[order.id] ? 'Loading...' : revealed[order.id] ? 'Hide key' : 'Reveal key' }}</button>
                        <p v-if="errors[order.id]" class="mt-2 text-xs text-rose-300" role="alert">{{ errors[order.id] }}</p>
                    </div>
                </article>
            </div>
            <p v-if="!orders.data.length" class="mt-12 rounded-xl border border-white/10 p-10 text-center text-slate-400">No orders yet. Your next adventure awaits.</p>
            <div class="mt-8 flex gap-4"><Link v-if="orders.links.prev" :href="orders.links.prev" class="text-violet-300">← Previous</Link><Link v-if="orders.links.next" :href="orders.links.next" class="text-violet-300">Next →</Link></div>
        </div>
    </main>
</template>
