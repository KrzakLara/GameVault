import type { Order } from '@/types';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

const IN_FLIGHT = new Set(['pending', 'processing']);
const MAX_DURATION_MS = 120_000;
const DELAYS_MS = [1000, 1500, 2000, 3000, 5000, 8000];

/**
 * Follows an order while fulfillment runs, via its JSON endpoint (no-store).
 *
 * Bounded by design: backoff between requests, a 2-minute ceiling, paused while the tab is
 * hidden, stopped once the order is final or the component unmounts. Transient errors back off
 * and continue; auth/permission errors stop. Read-only GETs, so retrying is always safe.
 */
export function useOrderPolling(order: Ref<Order>) {
    const timedOut = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let startedAt = 0;
    let step = 0;
    let stopped = false;

    function schedule() {
        if (stopped || !IN_FLIGHT.has(order.value.status)) {
            return;
        }
        if (Date.now() - startedAt > MAX_DURATION_MS) {
            timedOut.value = true;

            return;
        }
        timer = setTimeout(poll, DELAYS_MS[Math.min(step++, DELAYS_MS.length - 1)]);
    }

    async function poll() {
        if (document.hidden) {
            return; // resumed by the visibilitychange handler
        }
        try {
            const { data } = await axios.get<Order>(route('orders.show', order.value.id), { headers: { Accept: 'application/json' } });
            order.value = data;
        } catch (error: unknown) {
            const status = axios.isAxiosError(error) ? error.response?.status : undefined;
            if (status === 401 || status === 403 || status === 404) {
                stopped = true;

                return;
            }
        }
        schedule();
    }

    function onVisibility() {
        if (!document.hidden && !stopped && IN_FLIGHT.has(order.value.status)) {
            clearTimeout(timer);
            void poll();
        }
    }

    function restart() {
        timedOut.value = false;
        startedAt = Date.now();
        step = 0;
        clearTimeout(timer);
        schedule();
    }

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);
        restart();
    });
    onBeforeUnmount(() => {
        stopped = true;
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { timedOut, restart };
}
