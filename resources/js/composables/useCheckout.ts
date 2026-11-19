import type { ApiError, Order } from '@/types';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

export type CheckoutState =
    | { kind: 'idle' }
    | { kind: 'submitting'; attempt: number }
    | { kind: 'redirecting'; replayed: boolean }
    | { kind: 'sold_out'; message: string }
    | { kind: 'rate_limited'; message: string }
    | { kind: 'busy'; message: string }
    | { kind: 'conflict'; message: string }
    | { kind: 'error'; message: string };

const MAX_ATTEMPTS = 3; // automatic retries only for 503 (lock contention), always with the same key

function storage(): Storage | null {
    try {
        return window.sessionStorage;
    } catch {
        return null; // privacy mode: fall back to an in-memory key for this page view
    }
}

/**
 * Simulated checkout against POST /games/{slug}/checkout (JSON API).
 *
 * Idempotency: one purchase INTENT = one key. The key is kept for this tab (sessionStorage) until
 * the purchase is accepted, so a double click, a 503 retry, a network failure or even a reload
 * re-sends the SAME key and the server replays the same order instead of creating a second one.
 * Only a successful purchase ends the intent; the next "Buy" starts a new one. The key is not a
 * secret (it is useless without the user's session). The price is never sent: the server
 * snapshots it.
 */
export function useCheckout(gameSlug: string, callbacks: { onSoldOut?: () => void } = {}) {
    const state = ref<CheckoutState>({ kind: 'idle' });
    const storageKey = `gv:checkout-intent:${gameSlug}`;
    let memoryKey: string | null = null;

    function intentKey(): string {
        const store = storage();
        let key = store?.getItem(storageKey) ?? memoryKey;
        if (!key) {
            key = crypto.randomUUID();
            store?.setItem(storageKey, key);
            memoryKey = key;
        }

        return key;
    }

    function endIntent(): void {
        storage()?.removeItem(storageKey);
        memoryKey = null;
    }

    const busy = computed(() => state.value.kind === 'submitting' || state.value.kind === 'redirecting');

    async function purchase(): Promise<void> {
        if (busy.value) {
            return; // a second click while a request is in flight is ignored
        }

        const key = intentKey();

        for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
            state.value = { kind: 'submitting', attempt };
            try {
                const response = await axios.post<Order>(
                    route('orders.store', gameSlug),
                    { idempotency_key: key },
                    { headers: { Accept: 'application/json' } },
                );
                // 201 = new order, 200 = replay of the same intent: both are the one order.
                endIntent();
                state.value = { kind: 'redirecting', replayed: response.status === 200 };
                router.visit(route('orders.show', response.data.id));

                return;
            } catch (error: unknown) {
                if (!axios.isAxiosError(error) || !error.response) {
                    state.value = {
                        kind: 'error',
                        message: 'Connection problem. Retrying is safe: it re-sends the same checkout and can never create a second order.',
                    };

                    return;
                }

                const { status, headers } = error.response;
                const body = (error.response.data ?? {}) as ApiError;

                if (status === 503 && attempt < MAX_ATTEMPTS) {
                    await new Promise((resolve) => setTimeout(resolve, 400 * attempt + Math.random() * 300));
                    continue;
                }

                switch (status) {
                    case 409:
                        state.value = { kind: 'sold_out', message: body.message || 'This game just sold out.' };
                        callbacks.onSoldOut?.();
                        return;
                    case 422:
                        if (body.error === 'idempotency_conflict') {
                            endIntent(); // the stored key belongs to another purchase; start over
                            state.value = { kind: 'conflict', message: 'Please try again: your previous checkout was for a different game.' };
                        } else {
                            state.value = { kind: 'error', message: Object.values(body.errors ?? {})[0]?.[0] ?? body.message };
                        }
                        return;
                    case 429: {
                        const seconds = Number(headers['retry-after']) || 60;
                        state.value = { kind: 'rate_limited', message: `Too many checkout attempts. Please wait ${seconds} seconds and try again.` };
                        return;
                    }
                    case 503:
                        state.value = { kind: 'busy', message: 'The store is very busy right now. Your checkout was not placed; please try again.' };
                        return;
                    case 401:
                        router.visit(route('login'));
                        return;
                    case 419:
                        state.value = { kind: 'error', message: 'Your session expired. Refresh the page and try again.' };
                        return;
                    default:
                        state.value = { kind: 'error', message: 'Something went wrong. Please try again.' };
                        return;
                }
            }
        }
    }

    return { state, busy, purchase };
}
