<script setup lang="ts">
withDefaults(defineProps<{ wordmark?: boolean; size?: 'sm' | 'md' | 'lg' }>(), { wordmark: true, size: 'md' });
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <svg
            :class="{ 'size-7': size === 'sm', 'size-9': size === 'md', 'size-12': size === 'lg' }"
            viewBox="0 0 64 64"
            role="img"
            aria-label="GameVault"
        >
            <defs>
                <linearGradient id="gv-logo-gradient" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#8b5cf6" />
                    <stop offset="1" stop-color="#22d3ee" />
                </linearGradient>
            </defs>
            <rect width="64" height="64" rx="16" fill="hsl(228 19% 11%)" />
            <path d="M32 9 52 20.5v23L32 55 12 43.5v-23z" fill="none" stroke="url(#gv-logo-gradient)" stroke-width="4" stroke-linejoin="round" />
            <path d="M40 27.5A9 9 0 1 0 41 37h-9" fill="none" stroke="#f1f3f9" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span v-if="wordmark" class="font-display text-lg font-bold tracking-tight text-foreground">
            Game<span class="gv-gradient-text">Vault</span>
        </span>
    </span>
</template>
