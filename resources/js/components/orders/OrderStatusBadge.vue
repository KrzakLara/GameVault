<script setup lang="ts">
import Badge, { type BadgeTone } from '@/components/ui/gv/Badge.vue';
import type { OrderStatus } from '@/types';

defineProps<{ status: OrderStatus }>();

const meta: Record<OrderStatus, { label: string; tone: BadgeTone }> = {
    pending: { label: 'Queued', tone: 'cyan' },
    processing: { label: 'Delivering', tone: 'violet' },
    delivered: { label: 'Delivered', tone: 'success' },
    failed: { label: 'Failed', tone: 'danger' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};
</script>

<template>
    <Badge :tone="meta[status].tone" dot :data-testid="`order-status-${status}`">{{ meta[status].label }}</Badge>
</template>
