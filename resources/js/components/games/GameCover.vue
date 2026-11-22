<script setup lang="ts">
import SvgNodes from '@/components/games/SvgNodes';
import { coverArt } from '@/lib/cover-art';
import { computed, useId, type SVGAttributes } from 'vue';

/**
 * Original cover art for the fictional catalogue (see lib/cover-art.ts): an illustrated scene per
 * genre, deterministic per title, rendered as inline SVG. A real `coverUrl` wins when one is set.
 */
const props = withDefaults(
    defineProps<{
        slug: string;
        name: string;
        genre?: string | null;
        coverUrl?: string | null;
        format?: 'portrait' | 'landscape';
        /** Paint the title onto the art (portrait only); off where the name is set in text right next to it. */
        showTitle?: boolean;
        /** Hidden from assistive technology where the game's name is already announced (cards, lists). */
        decorative?: boolean;
    }>(),
    { genre: null, coverUrl: null, format: 'portrait', showTitle: true, decorative: false },
);

// Gradient ids must be unique per instance: the same game can be on a page twice, and a gradient
// defined inside a hidden copy (display: none) would not paint in the visible one.
const idPrefix = `gv${(useId() ?? '').replace(/[^A-Za-z0-9]/g, '')}`;

const art = computed(() =>
    coverArt({ slug: props.slug, name: props.name, genre: props.genre, format: props.format, idPrefix, title: props.showTitle }),
);
const a11y = computed<SVGAttributes>(() =>
    props.decorative ? { 'aria-hidden': true, focusable: 'false' } : { role: 'img', 'aria-label': `${props.name} cover art` },
);
</script>

<template>
    <img
        v-if="coverUrl"
        :src="coverUrl"
        :alt="decorative ? '' : `${name} cover art`"
        loading="lazy"
        decoding="async"
        class="size-full object-cover"
    />
    <svg v-else :viewBox="`0 0 ${art.width} ${art.height}`" preserveAspectRatio="xMidYMid slice" class="block size-full" v-bind="a11y">
        <defs><SvgNodes :nodes="art.defs" /></defs>
        <SvgNodes :nodes="art.layers" />
    </svg>
</template>
