<script setup lang="ts">
import StoreLayout from '@/layouts/StoreLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ status: number; requestId?: string | null }>();

const copy: Record<number, { title: string; body: string }> = {
    403: { title: 'Access denied', body: 'You do not have permission to open this page.' },
    404: { title: 'Page not found', body: 'The page you are looking for does not exist or has moved.' },
    419: { title: 'Page expired', body: 'Your session timed out for security. Refresh the page and try again.' },
    429: { title: 'Slow down', body: 'Too many requests in a short time. Please wait a moment.' },
    500: { title: 'Something went wrong', body: 'An unexpected error occurred. Our team can trace it with the reference below.' },
    503: { title: 'Temporarily unavailable', body: 'The store is very busy right now. Please try again shortly.' },
};
const content = computed(() => copy[props.status] ?? copy[500]);
</script>

<template>
    <Head :title="content.title" />
    <StoreLayout>
        <div class="gv-aurora">
            <div class="mx-auto flex max-w-xl flex-col items-center px-4 py-28 text-center" data-testid="error-page">
                <p class="gv-gradient-text font-display text-7xl font-extrabold">{{ status }}</p>
                <h1 class="mt-6 text-3xl font-bold">{{ content.title }}</h1>
                <p class="mt-3 text-muted-foreground">{{ content.body }}</p>
                <p v-if="requestId" class="mt-4 text-xs text-muted-foreground">
                    Reference: <span class="font-mono">{{ requestId }}</span>
                </p>
                <div class="mt-8 flex gap-3">
                    <Link :href="route('home')" class="rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground"
                        >Go to the store</Link
                    >
                    <Link :href="route('games.index')" class="rounded-lg border border-border px-5 py-2.5 text-sm font-medium hover:bg-accent"
                        >Browse games</Link
                    >
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
