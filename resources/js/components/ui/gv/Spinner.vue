<script setup lang="ts">
defineProps<{ label?: string }>();
</script>

<template>
    <span class="inline-flex items-center gap-2" role="status">
        <svg class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <span :class="label ? '' : 'sr-only'">{{ label ?? 'Loading' }}</span>
    </span>
</template>
