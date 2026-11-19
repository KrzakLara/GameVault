<script setup lang="ts">
import { formatDateTime } from '@/lib/format';
import type { Order } from '@/types';
import { Check, Loader2, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ order: Order }>();

type StepState = 'done' | 'current' | 'upcoming' | 'failed';

const at = (status: string) => props.order.timeline?.find((e) => e.status === status)?.at ?? null;

const steps = computed<{ label: string; detail: string; state: StepState }[]>(() => {
    const s = props.order.status;
    const failed = s === 'failed' || s === 'cancelled';

    return [
        { label: 'Order placed', detail: formatDateTime(props.order.created_at), state: 'done' },
        { label: 'Key reserved', detail: 'Held for you at checkout', state: 'done' },
        {
            label: 'Delivering',
            detail: at('processing') ? formatDateTime(at('processing')) : 'Waiting for a fulfillment worker',
            state: s === 'pending' || s === 'processing' ? 'current' : failed && !at('processing') ? 'upcoming' : failed ? 'failed' : 'done',
        },
        {
            label: failed ? (s === 'cancelled' ? 'Cancelled' : 'Not delivered') : 'Delivered',
            detail: failed
                ? (props.order.failure_message ?? 'This order was closed.')
                : props.order.delivered_at
                  ? formatDateTime(props.order.delivered_at)
                  : 'Your key will appear here',
            state: s === 'delivered' ? 'done' : failed ? 'failed' : 'upcoming',
        },
    ];
});
</script>

<template>
    <ol class="space-y-0" aria-label="Fulfillment progress" data-testid="fulfillment-progress">
        <li v-for="(step, i) in steps" :key="step.label" class="relative flex gap-4 pb-6 last:pb-0">
            <span v-if="i < steps.length - 1" class="absolute left-[15px] top-8 h-[calc(100%-2rem)] w-px bg-border" aria-hidden="true" />
            <span
                :class="[
                    'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full ring-1',
                    {
                        'bg-success/15 text-success ring-success/40': step.state === 'done',
                        'bg-primary/15 text-primary ring-primary/50': step.state === 'current',
                        'bg-muted text-muted-foreground ring-border': step.state === 'upcoming',
                        'bg-destructive/15 text-destructive ring-destructive/40': step.state === 'failed',
                    },
                ]"
                aria-hidden="true"
            >
                <Check v-if="step.state === 'done'" class="size-4" />
                <Loader2 v-else-if="step.state === 'current'" class="size-4 animate-spin motion-reduce:animate-none" />
                <X v-else-if="step.state === 'failed'" class="size-4" />
                <span v-else class="size-2 rounded-full bg-current" />
            </span>
            <div class="pt-1">
                <p class="text-sm font-medium" :class="step.state === 'upcoming' ? 'text-muted-foreground' : 'text-foreground'">
                    {{ step.label }}
                    <span class="sr-only">({{ step.state }})</span>
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ step.detail }}</p>
            </div>
        </li>
    </ol>
</template>
