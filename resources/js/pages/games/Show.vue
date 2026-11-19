<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Game = { slug: string; name: string; platform: string; price_cents: number; currency: string; stock: number; description: string | null };
const props = defineProps<{ game: Game; marketPrice: { lowest_usd: number; source: string; currency: string } | null }>();
const game = computed<Game>(() => props.game);
const page = usePage<{ errors: Record<string, string> }>();
const busy = ref(false);

// One key per page view: a double click, a network retry or a resubmitted form is recognised by
// the server as the SAME purchase and replayed, never charged twice.
const idempotencyKey = crypto.randomUUID();

function buy() {
    if (busy.value || !game.value.stock) return;
    busy.value = true;
    router.post(`/games/${game.value.slug}/checkout`, { idempotency_key: idempotencyKey }, { onFinish: () => (busy.value = false) });
}
const price = computed(() => (game.value.price_cents / 100).toLocaleString('en-IE', { style: 'currency', currency: game.value.currency }));
</script>
<template>
    <main class="min-h-screen bg-[#090b18] px-6 py-8 text-white">
        <div class="mx-auto max-w-5xl">
            <header class="mb-12 flex justify-between"><Link href="/games" class="text-xl font-black">GAME<span class="text-violet-400">VAULT</span></Link><Link href="/my-orders" class="text-slate-300">My orders →</Link></header>
            <Link href="/games" class="text-sm text-violet-300">← Back to catalog</Link>
            <div class="mt-10 grid overflow-hidden rounded-3xl border border-white/10 bg-[#141728] md:grid-cols-2">
                <div class="flex min-h-96 items-center justify-center bg-gradient-to-br from-violet-800 via-indigo-700 to-cyan-800"><span class="text-8xl font-black text-white/20">GV</span></div>
                <div class="p-9 md:p-12">
                    <span class="rounded-lg bg-violet-500/20 px-3 py-1 text-xs text-violet-200">{{ game.platform }}</span>
                    <h1 class="mt-6 text-4xl font-extrabold">{{ game.name }}</h1>
                    <p class="mt-5 text-slate-400">{{ game.description }}</p>
                    <div class="mt-12 text-4xl font-bold">{{ price }}</div>
                    <p class="mt-2 text-sm" :class="game.stock ? 'text-emerald-400' : 'text-rose-400'">{{ game.stock ? `${game.stock} keys available` : 'Out of stock' }}</p>
                    <p v-if="page.props.errors.checkout || page.props.errors.idempotency_key" role="alert" class="mt-4 rounded-lg bg-rose-500/15 p-3 text-sm text-rose-200">{{ page.props.errors.checkout || page.props.errors.idempotency_key }}</p>
                    <button :disabled="!game.stock || busy" @click="buy" class="mt-8 w-full rounded-xl bg-violet-500 py-4 font-semibold hover:bg-violet-400 disabled:cursor-not-allowed disabled:opacity-50">{{ busy ? 'Processing...' : 'Buy now · Demo checkout' }}</button>
                    <p class="mt-3 text-center text-xs text-slate-500">Simulated purchase. No real payment or real game keys.</p>
                    <p v-if="marketPrice" class="mt-7 border-t border-white/10 pt-5 text-sm text-slate-400">Market reference: <strong class="text-white">${{ marketPrice.lowest_usd.toFixed(2) }} USD</strong> via {{ marketPrice.source }} (different currency, not directly comparable)</p>
                </div>
            </div>
        </div>
    </main>
</template>
