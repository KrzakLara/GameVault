<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{ icon?: Component; title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-border bg-card/40 px-6 py-14 text-center" data-testid="empty-state">
        <div v-if="icon" class="mb-4 flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary ring-1 ring-primary/25">
            <component :is="icon" class="size-6" aria-hidden="true" />
        </div>
        <h3 class="font-display text-lg font-semibold text-foreground">{{ title }}</h3>
        <p v-if="description" class="mt-2 max-w-md text-sm text-muted-foreground">{{ description }}</p>
        <div v-if="$slots.default" class="mt-6"><slot /></div>
    </div>
</template>
