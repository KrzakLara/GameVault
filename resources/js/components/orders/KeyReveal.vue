<script setup lang="ts">
import Spinner from '@/components/ui/gv/Spinner.vue';
import { useToast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { Check, Copy, EyeOff, KeyRound } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';

/**
 * Reveals a purchased key on explicit request (POST: CSRF-protected, owner-only, rate limited,
 * audited server-side). The plaintext lives only in this component's memory: never in storage,
 * logs, toasts or analytics. It is wiped on "Hide", after two minutes, on any Inertia navigation
 * and when the component unmounts.
 */
const props = defineProps<{ orderId: string }>();

const AUTO_HIDE_MS = 120_000;
const toast = useToast();
const plaintext = ref<string | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const copied = ref(false);
const output = ref<HTMLElement | null>(null);
let hideTimer: ReturnType<typeof setTimeout> | undefined;

function hide() {
    plaintext.value = null;
    copied.value = false;
    clearTimeout(hideTimer);
}

async function reveal() {
    if (loading.value) {
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.post<{ key: string }>(route('orders.reveal', props.orderId), {}, { headers: { Accept: 'application/json' } });
        plaintext.value = data.key;
        hideTimer = setTimeout(hide, AUTO_HIDE_MS);
    } catch (e: unknown) {
        // Deliberately no console logging: error objects can carry response bodies.
        const response = axios.isAxiosError(e) ? e.response : undefined;
        if (response?.status === 429) {
            error.value = `Too many reveal attempts. Try again in ${Number(response.headers['retry-after']) || 60} seconds.`;
        } else if (response?.status === 409) {
            error.value = 'This order has not been delivered yet.';
        } else if (response?.status === 401 || response?.status === 419) {
            error.value = 'Your session expired. Refresh the page and sign in again.';
        } else {
            error.value = 'The key could not be revealed. Please try again.';
        }
    } finally {
        loading.value = false;
    }
}

async function copy() {
    if (!plaintext.value) {
        return;
    }
    try {
        await navigator.clipboard.writeText(plaintext.value);
        copied.value = true;
        toast.success('Key copied to clipboard');
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        // Clipboard blocked (permissions, insecure context): select the text for manual copying.
        if (output.value) {
            const range = document.createRange();
            range.selectNodeContents(output.value);
            window.getSelection()?.removeAllRanges();
            window.getSelection()?.addRange(range);
        }
        toast.warning('Copying was blocked by the browser', 'The key is selected: press Ctrl+C (⌘C) to copy it.');
    }
}

const stopListening = router.on('before', () => hide());
onBeforeUnmount(() => {
    hide();
    stopListening();
});
</script>

<template>
    <section class="rounded-xl border border-success/30 bg-success/5 p-5" aria-labelledby="key-heading" data-testid="key-reveal">
        <div class="flex items-center gap-2">
            <KeyRound class="size-4 text-success" aria-hidden="true" />
            <h2 id="key-heading" class="text-sm font-semibold">Your game key</h2>
        </div>

        <div v-if="plaintext" class="mt-4 space-y-3">
            <output
                ref="output"
                class="block select-all break-all rounded-lg border border-border bg-background px-4 py-3 font-mono text-lg tracking-wider text-foreground"
                aria-live="polite"
                data-testid="revealed-key"
                >{{ plaintext }}</output
            >
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:bg-primary/90"
                    data-testid="copy-key"
                    @click="copy"
                >
                    <component :is="copied ? Check : Copy" class="size-4" aria-hidden="true" /> {{ copied ? 'Copied' : 'Copy key' }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm text-muted-foreground transition hover:bg-accent hover:text-foreground"
                    @click="hide"
                >
                    <EyeOff class="size-4" aria-hidden="true" /> Hide
                </button>
            </div>
            <p class="text-xs text-muted-foreground">Hidden again automatically after two minutes or when you leave this page.</p>
        </div>

        <div v-else class="mt-4 space-y-3">
            <p class="font-mono text-lg tracking-[0.3em] text-muted-foreground/60" aria-hidden="true">•••••-•••••-•••••</p>
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-success px-4 py-2 text-sm font-semibold text-success-foreground transition hover:bg-success/90 disabled:opacity-60"
                :disabled="loading"
                data-testid="reveal-key"
                @click="reveal"
            >
                <Spinner v-if="loading" label="Revealing…" />
                <template v-else><KeyRound class="size-4" aria-hidden="true" /> Reveal key</template>
            </button>
            <p v-if="error" role="alert" class="text-sm text-[hsl(356_90%_76%)]" data-testid="reveal-error">{{ error }}</p>
            <p class="text-xs text-muted-foreground">Only you can reveal this key. Every reveal is recorded.</p>
        </div>
    </section>
</template>
