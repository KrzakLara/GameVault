<script setup lang="ts">
import { computed } from 'vue';

/**
 * Original, procedurally generated cover art. Deterministic per slug (same game, same cover),
 * a few hundred bytes of inline SVG, crisp at any size. A real `coverUrl` wins when present.
 */
const props = withDefaults(defineProps<{ slug: string; name: string; platform: string; coverUrl?: string | null; variant?: 'card' | 'hero' }>(), {
    coverUrl: null,
    variant: 'card',
});

function hash(input: string): number {
    let h = 2166136261;
    for (let i = 0; i < input.length; i++) {
        h ^= input.charCodeAt(i);
        h = Math.imul(h, 16777619);
    }

    return h >>> 0;
}

// Curated pairs that stay inside the brand's violet/cyan/magenta family.
const palettes: [string, string, string][] = [
    ['#4c1d95', '#0e7490', '#c4b5fd'],
    ['#581c87', '#be185d', '#f5d0fe'],
    ['#1e3a8a', '#6d28d9', '#bfdbfe'],
    ['#134e4a', '#4338ca', '#99f6e4'],
    ['#3b0764', '#1d4ed8', '#e9d5ff'],
    ['#7c2d12', '#6b21a8', '#fed7aa'],
    ['#0f172a', '#7e22ce', '#c7d2fe'],
    ['#164e63', '#9333ea', '#a5f3fc'],
];

const seed = computed(() => hash(props.slug));
const palette = computed(() => palettes[seed.value % palettes.length]);
const pattern = computed(() => (seed.value >>> 3) % 4);
const angle = computed(() => (seed.value >>> 5) % 360);
const id = computed(() => `cover-${seed.value.toString(36)}`);
const initials = computed(() =>
    props.name
        .split(/\s+/)
        .filter((w) => /[A-Za-z0-9]/.test(w))
        .map((w) => w[0])
        .join('')
        .slice(0, 3)
        .toUpperCase(),
);
const rings = computed(() => Array.from({ length: 6 }, (_, i) => 30 + i * 22 + ((seed.value >>> (i + 7)) % 9)));
</script>

<template>
    <img v-if="coverUrl" :src="coverUrl" :alt="`${name} cover art`" loading="lazy" decoding="async" class="size-full object-cover" />
    <svg v-else viewBox="0 0 320 400" preserveAspectRatio="xMidYMid slice" class="size-full" role="img" :aria-label="`${name} cover art`">
        <defs>
            <linearGradient :id="`${id}-bg`" :gradientTransform="`rotate(${angle} 0.5 0.5)`">
                <stop offset="0" :stop-color="palette[0]" />
                <stop offset="1" :stop-color="palette[1]" />
            </linearGradient>
            <radialGradient :id="`${id}-glow`" cx="0.75" cy="0.2" r="0.7">
                <stop offset="0" stop-color="#ffffff" stop-opacity="0.28" />
                <stop offset="1" stop-color="#ffffff" stop-opacity="0" />
            </radialGradient>
            <linearGradient :id="`${id}-fade`" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0.45" stop-color="#0c0e14" stop-opacity="0" />
                <stop offset="1" stop-color="#0c0e14" stop-opacity="0.85" />
            </linearGradient>
        </defs>
        <rect width="320" height="400" :fill="`url(#${id}-bg)`" />
        <g :stroke="palette[2]" stroke-opacity="0.22" fill="none" stroke-width="1.5">
            <template v-if="pattern === 0">
                <circle v-for="r in rings" :key="r" cx="250" cy="90" :r="r" />
            </template>
            <template v-else-if="pattern === 1">
                <path v-for="i in 14" :key="i" :d="`M${i * 32 - 160} 400 L${i * 32 + 80} 0`" />
            </template>
            <template v-else-if="pattern === 2">
                <path v-for="i in 9" :key="i" :d="`M0 ${i * 46} H320 M${i * 40} 0 V400`" />
            </template>
            <template v-else>
                <polygon v-for="r in rings.slice(0, 4)" :key="r" :points="`160,${200 - r} ${160 + r},${200} 160,${200 + r} ${160 - r},${200}`" />
            </template>
        </g>
        <rect width="320" height="400" :fill="`url(#${id}-glow)`" />
        <rect width="320" height="400" :fill="`url(#${id}-fade)`" />
        <text
            x="24"
            :y="variant === 'hero' ? 340 : 352"
            fill="#ffffff"
            fill-opacity="0.9"
            font-family="Sora, sans-serif"
            font-weight="800"
            :font-size="variant === 'hero' ? 76 : 64"
            letter-spacing="-2"
        >
            {{ initials }}
        </text>
        <text x="26" y="376" fill="#ffffff" fill-opacity="0.6" font-family="Instrument Sans, sans-serif" font-size="15" letter-spacing="3">
            {{ platform.toUpperCase() }}
        </text>
    </svg>
</template>
