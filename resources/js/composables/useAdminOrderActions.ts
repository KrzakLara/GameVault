import type { AdminOrder } from '@/types';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Retry / cancel through the existing admin endpoints. The server decides: it re-checks the
 * admin gate, the order state and stock, and audits the action. The UI only asks for intent.
 */
export function useAdminOrderActions() {
    const busy = ref(false);
    const error = ref<string | null>(null);

    function submit(url: string, data: Record<string, string>, onDone: () => void) {
        busy.value = true;
        error.value = null;
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => onDone(),
            onError: (errors) => (error.value = errors.order ?? errors.reason ?? Object.values(errors)[0] ?? 'The action failed.'),
            onFinish: () => (busy.value = false),
        });
    }

    return {
        busy,
        error,
        retry: (order: AdminOrder, onDone: () => void) => submit(route('admin.orders.retry', order.public_id), {}, onDone),
        cancel: (order: AdminOrder, reason: string, onDone: () => void) => submit(route('admin.orders.cancel', order.public_id), { reason }, onDone),
        reset: () => (error.value = null),
    };
}
