<script setup lang="ts">
import { useKeyReveal } from '@/composables/useKeyReveal';
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

type Order = {
    id: string;
    status: string;
    price_cents: number;
    currency: string;
    can_reveal: boolean;
    failure_message: string | null;
    created_at: string;
    delivered_at: string | null;
    game: { name: string; slug: string; platform: string };
    timeline: { status: string; at: string }[];
};
const props = defineProps<{ order: Order }>();
const { revealed, errors, busy, reveal, hide } = useKeyReveal();

// Poll while fulfillment is in flight (partial reload of the order prop only).
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (props.order.status === 'pending' || props.order.status === 'processing') router.reload({ only: ['order'] });
        else clearInterval(timer);
    }, 2000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>
<template>
    <main class="min-h-screen bg-[#090b18] px-6 py-8 text-white">
        <div class="mx-auto max-w-3xl">
            <header class="flex items-center justify-between"><Link href="/games" class="text-2xl font-black">GAME<span class="text-violet-400">VAULT</span></Link><Link href="/my-orders" class="text-sm text-violet-300">← My orders</Link></header>
            <p v-if="$page.props.flash?.success" class="mt-8 rounded-lg bg-emerald-500/15 p-3 text-sm text-emerald-200">{{ $page.props.flash.success }}</p>
            <h1 class="mt-10 text-4xl font-black">{{ order.game.name }}</h1>
            <p class="mt-2 font-mono text-xs text-slate-400">Order {{ order.id }} · {{ order.game.platform }} · {{ (order.price_cents / 100).toLocaleString('en-IE', { style: 'currency', currency: order.currency }) }}</p>
            <p class="mt-6"><span class="rounded-lg bg-white/10 px-3 py-1 text-sm font-semibold">{{ order.status }}</span></p>
            <p v-if="order.failure_message" class="mt-4 text-rose-300">{{ order.failure_message }}</p>
            <section v-if="order.can_reveal" class="mt-8 rounded-2xl border border-white/10 bg-[#141728] p-6">
                <p class="font-mono text-lg">{{ revealed[order.id] || 'XXXXX-XXXXX-XXXXX' }}</p>
                <button class="mt-3 text-sm text-violet-300 underline" :disabled="busy[order.id]" @click="revealed[order.id] ? hide(order.id) : reveal(order.id)">{{ busy[order.id] ? 'Loading...' : revealed[order.id] ? 'Hide key' : 'Reveal key' }}</button>
                <p v-if="errors[order.id]" role="alert" class="mt-2 text-sm text-rose-300">{{ errors[order.id] }}</p>
            </section>
            <ol class="mt-10 space-y-2 border-l border-white/10 pl-5 text-sm text-slate-300">
                <li v-for="(step, i) in order.timeline" :key="i"><span class="font-semibold text-white">{{ step.status }}</span> · {{ new Date(step.at).toLocaleString() }}</li>
            </ol>
        </div>
    </main>
</template>
