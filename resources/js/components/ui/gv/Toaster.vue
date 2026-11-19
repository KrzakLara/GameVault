<script setup lang="ts">
import { useToast, type ToastTone } from '@/composables/useToast';
import { CheckCircle2, Info, TriangleAlert, X, XCircle } from 'lucide-vue-next';
import type { Component } from 'vue';

const { toasts, dismiss } = useToast();

const icon: Record<ToastTone, Component> = { success: CheckCircle2, error: XCircle, info: Info, warning: TriangleAlert };
const tone: Record<ToastTone, string> = {
    success: 'border-success/40 [&_.toast-icon]:text-success',
    error: 'border-destructive/50 [&_.toast-icon]:text-destructive',
    info: 'border-primary/40 [&_.toast-icon]:text-primary',
    warning: 'border-warning/40 [&_.toast-icon]:text-warning',
};
</script>

<template>
    <!-- polite: announced without interrupting; errors are also shown inline where they happen. -->
    <div aria-live="polite" aria-atomic="false" class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                role="status"
                :data-testid="`toast-${toast.tone}`"
                :class="[
                    'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border bg-popover/95 p-4 shadow-2xl shadow-black/40 backdrop-blur',
                    tone[toast.tone],
                ]"
            >
                <component :is="icon[toast.tone]" class="toast-icon mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-foreground">{{ toast.title }}</p>
                    <p v-if="toast.description" class="mt-1 text-sm text-muted-foreground">{{ toast.description }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-md p-1 text-muted-foreground transition hover:bg-accent hover:text-foreground"
                    aria-label="Dismiss notification"
                    @click="dismiss(toast.id)"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
