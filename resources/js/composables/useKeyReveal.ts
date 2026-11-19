import axios from 'axios';
import { ref } from 'vue';

/**
 * Reveals a purchased key via POST (CSRF-protected: axios sends the X-XSRF-TOKEN header from
 * Laravel's XSRF-TOKEN cookie). The key lives only in component memory, never in storage.
 */
export function useKeyReveal() {
    const revealed = ref<Record<string, string>>({});
    const errors = ref<Record<string, string>>({});
    const busy = ref<Record<string, boolean>>({});

    async function reveal(orderId: string) {
        busy.value[orderId] = true;
        errors.value[orderId] = '';
        try {
            const { data } = await axios.post<{ key: string }>(`/my-orders/${orderId}/reveal`, {}, { headers: { Accept: 'application/json' } });
            revealed.value[orderId] = data.key;
        } catch (e: unknown) {
            const status = axios.isAxiosError(e) ? e.response?.status : undefined;
            errors.value[orderId] = status === 429 ? 'Too many attempts. Please wait a minute.' : 'Unable to reveal the key. Refresh and try again.';
        } finally {
            busy.value[orderId] = false;
        }
    }

    function hide(orderId: string) {
        delete revealed.value[orderId];
    }

    return { revealed, errors, busy, reveal, hide };
}
