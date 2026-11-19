<script setup lang="ts">
import GvLogo from '@/components/GvLogo.vue';
import Toaster from '@/components/ui/gv/Toaster.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <div class="gv-aurora flex min-h-svh flex-col items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center gap-6 text-center">
                <Link :href="route('home')" class="rounded-lg" aria-label="GameVault home">
                    <GvLogo size="md" />
                </Link>
                <div class="space-y-2">
                    <h1 class="font-display text-2xl font-semibold">{{ title }}</h1>
                    <p class="text-sm text-muted-foreground">{{ description }}</p>
                </div>
            </div>
            <div class="gv-surface p-6 shadow-2xl shadow-black/30">
                <slot />
            </div>
            <p class="mt-6 text-center text-xs text-muted-foreground">Portfolio demo · fictional games · simulated checkout</p>
        </div>
        <Toaster />
    </div>
</template>
