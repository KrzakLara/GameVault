<script setup lang="ts">
import Badge from '@/components/ui/gv/Badge.vue';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ stock: number; lowThreshold?: number; verbose?: boolean }>(), { lowThreshold: 3, verbose: false });

const state = computed(() => {
    if (props.stock <= 0) {
        return { tone: 'danger' as const, label: 'Sold out' };
    }
    if (props.stock < props.lowThreshold) {
        return { tone: 'warning' as const, label: props.verbose ? `Only ${props.stock} left` : `${props.stock} left` };
    }

    return { tone: 'success' as const, label: props.verbose ? `${props.stock} keys in stock` : 'In stock' };
});
</script>

<template>
    <Badge :tone="state.tone" dot data-testid="stock-indicator">{{ state.label }}</Badge>
</template>
