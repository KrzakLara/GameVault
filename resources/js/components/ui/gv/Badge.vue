<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

export type BadgeTone = 'neutral' | 'violet' | 'cyan' | 'success' | 'warning' | 'danger';

const props = withDefaults(defineProps<{ tone?: BadgeTone; dot?: boolean; class?: HTMLAttributes['class'] }>(), { tone: 'neutral', dot: false });

const tones: Record<BadgeTone, string> = {
    neutral: 'border-border bg-muted text-muted-foreground',
    violet: 'border-primary/30 bg-primary/10 text-[hsl(258_100%_82%)]',
    cyan: 'border-highlight/30 bg-highlight/10 text-highlight',
    success: 'border-success/30 bg-success/10 text-success',
    warning: 'border-warning/30 bg-warning/10 text-warning',
    danger: 'border-destructive/35 bg-destructive/10 text-[hsl(356_90%_72%)]',
};
const dots: Record<BadgeTone, string> = {
    neutral: 'bg-muted-foreground',
    violet: 'bg-primary',
    cyan: 'bg-highlight',
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-destructive',
};
</script>

<template>
    <span :class="cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium', tones[props.tone], props.class)">
        <span v-if="dot" :class="['size-1.5 rounded-full', dots[props.tone]]" aria-hidden="true" />
        <slot />
    </span>
</template>
