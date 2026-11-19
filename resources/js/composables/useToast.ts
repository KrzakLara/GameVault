import { readonly, ref } from 'vue';

export type ToastTone = 'success' | 'error' | 'info' | 'warning';

export interface Toast {
    id: number;
    tone: ToastTone;
    title: string;
    description?: string;
}

const toasts = ref<Toast[]>([]);
let nextId = 1;

/**
 * App-wide notifications, rendered by <Toaster /> inside an aria-live region.
 * Never pass secrets (e.g. a revealed key) into a toast: toasts are plain UI text.
 */
export function useToast() {
    function dismiss(id: number) {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    function show(tone: ToastTone, title: string, description?: string, durationMs = 5000): number {
        const id = nextId++;
        toasts.value = [...toasts.value.slice(-3), { id, tone, title, description }];
        if (durationMs > 0) {
            window.setTimeout(() => dismiss(id), durationMs);
        }

        return id;
    }

    return {
        toasts: readonly(toasts),
        dismiss,
        success: (title: string, description?: string) => show('success', title, description),
        error: (title: string, description?: string) => show('error', title, description, 8000),
        info: (title: string, description?: string) => show('info', title, description),
        warning: (title: string, description?: string) => show('warning', title, description, 7000),
    };
}
