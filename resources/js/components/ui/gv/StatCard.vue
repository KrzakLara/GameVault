<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{ label: string; value: string | number; hint?: string; icon?: Component; tone?: 'default' | 'success' | 'warning' | 'danger' | 'cyan' }>();
</script>

<template>
    <div class="gv-surface p-5">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm font-medium text-muted-foreground">{{ label }}</p>
            <span
                v-if="icon"
                :class="[
                    'flex size-9 items-center justify-center rounded-lg ring-1',
                    {
                        'bg-primary/10 text-primary ring-primary/25': !tone || tone === 'default',
                        'bg-success/10 text-success ring-success/25': tone === 'success',
                        'bg-warning/10 text-warning ring-warning/25': tone === 'warning',
                        'bg-destructive/10 text-destructive ring-destructive/25': tone === 'danger',
                        'bg-highlight/10 text-highlight ring-highlight/25': tone === 'cyan',
                    },
                ]"
            >
                <component :is="icon" class="size-4" aria-hidden="true" />
            </span>
        </div>
        <p class="mt-3 font-display text-3xl font-semibold tabular-nums tracking-tight">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
