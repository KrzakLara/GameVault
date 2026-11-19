<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type Game = { id:number;slug:string;name:string;platform:string;price_cents:number;stock:number;cover_url:string|null;description:string|null };
const props = defineProps<{games:{data:Game[];prev_page_url:string|null;next_page_url:string|null};filters:{search:string;platform:string}}>();
const search = ref(props.filters.search);
const platform = ref(props.filters.platform);
function apply() { router.get('/games',{search:search.value,platform:platform.value},{preserveState:true,replace:true}); }
const euros = (cents:number) => new Intl.NumberFormat('en-IE',{style:'currency',currency:'EUR'}).format(cents/100);
</script>
<template>
  <main class="min-h-screen bg-[#090b18] text-white">
    <header class="mx-auto flex max-w-7xl items-center justify-between px-6 py-7">
      <Link href="/games" class="text-2xl font-black tracking-tight">GAME<span class="text-violet-400">VAULT</span><span class="ml-2 rounded bg-violet-500/15 px-2 py-1 text-[10px] uppercase tracking-widest text-violet-300">demo</span></Link>
      <nav class="flex gap-5 text-sm text-slate-300"><Link href="/games" class="text-white">Explore</Link><Link href="/my-orders" class="hover:text-white">My orders</Link><Link href="/login" class="hover:text-white">Sign in</Link></nav>
    </header>
    <section class="mx-auto max-w-7xl px-6 pb-12 pt-14">
      <p class="mb-4 text-xs font-semibold uppercase tracking-[.35em] text-violet-400">Your next adventure starts here</p>
      <h1 class="max-w-3xl text-5xl font-extrabold leading-tight tracking-tight md:text-7xl">Epic games.<br><span class="bg-gradient-to-r from-violet-400 to-cyan-300 bg-clip-text text-transparent">Instant access.</span></h1>
      <p class="mt-6 max-w-xl text-lg text-slate-400">Discover digital game keys across your favorite platforms. A secure, developer-built marketplace demo.</p>
      <div class="mt-10 flex max-w-3xl flex-col gap-3 sm:flex-row">
        <input v-model="search" @keyup.enter="apply" aria-label="Search games" placeholder="Search for your next game..." class="min-w-0 flex-1 rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-white outline-none placeholder:text-slate-500 focus:border-violet-400" />
        <select v-model="platform" aria-label="Platform" class="rounded-xl border border-white/10 bg-[#17182c] px-5 py-4"><option value="">All platforms</option><option>Steam</option><option>PlayStation</option><option>Xbox</option></select>
        <button @click="apply" class="rounded-xl bg-violet-500 px-8 py-4 font-bold transition hover:bg-violet-400">Explore games →</button>
      </div>
      <div class="mt-16 flex items-center justify-between border-b border-white/10 pb-5"><h2 class="text-2xl font-bold">Browse the vault</h2><span class="text-sm text-slate-400">Curated digital adventures</span></div>
      <div class="mt-7 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <Link v-for="(game,i) in games.data" :key="game.id" :href="`/games/${game.slug}`" class="group overflow-hidden rounded-2xl border border-white/10 bg-[#141728] transition hover:-translate-y-1 hover:border-violet-400/60">
          <div class="relative flex h-44 items-center justify-center overflow-hidden" :style="{ background: ['linear-gradient(135deg,#36218f,#0e8191)','linear-gradient(135deg,#6b1b70,#e05b63)','linear-gradient(135deg,#0f4266,#463b9f)','linear-gradient(135deg,#744116,#9145a8)'][i%4] }">
            <img v-if="game.cover_url" :src="game.cover_url" :alt="game.name" class="h-full w-full object-cover" />
            <span v-else aria-hidden="true" class="text-6xl font-black text-white/20">{{ game.name.split(' ').map(x=>x[0]).join('').slice(0,3) }}</span>
            <span class="absolute left-4 top-4 rounded-lg bg-black/50 px-3 py-1 text-xs backdrop-blur">{{ game.platform }}</span>
          </div>
          <div class="p-5"><h3 class="min-h-12 text-lg font-bold group-hover:text-violet-300">{{ game.name }}</h3><div class="mt-4 flex items-center justify-between"><strong class="text-xl">{{ euros(game.price_cents) }}</strong><span :class="game.stock ? 'text-emerald-400' : 'text-rose-400'" class="text-xs">{{ game.stock ? `${game.stock} in stock` : 'Sold out' }}</span></div></div>
        </Link>
      </div>
      <p v-if="!games.data.length" class="py-20 text-center text-slate-400">No games found. Try another search.</p>
      <div class="mt-10 flex justify-center gap-4"><Link v-if="games.prev_page_url" :href="games.prev_page_url" class="rounded-lg border border-white/20 px-5 py-2">← Previous</Link><Link v-if="games.next_page_url" :href="games.next_page_url" class="rounded-lg border border-white/20 px-5 py-2">Next →</Link></div>
    </section>
    <footer class="border-t border-white/10 px-6 py-8 text-center text-xs text-slate-500">GAMEVAULT · Portfolio demonstration · Fictional games · Simulated payments only</footer>
  </main>
</template>
