import { useToast } from '@/composables/useToast';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';

/** Surfaces the session flash shared by HandleInertiaRequests as toasts, once per response. */
export function useFlashToasts(): void {
    const page = usePage();
    const toast = useToast();

    watch(
        () => page.props.flash,
        (flash) => {
            if (flash?.success) {
                toast.success(flash.success);
            }
            if (flash?.error) {
                toast.error(flash.error);
            }
        },
        { immediate: true },
    );
}
