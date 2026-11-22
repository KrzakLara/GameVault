<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import Badge from '@/components/ui/gv/Badge.vue';
import EmptyState from '@/components/ui/gv/EmptyState.vue';
import Pagination from '@/components/ui/gv/Pagination.vue';
import { useToast } from '@/composables/useToast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { ApiError, InventoryGame, Paginated } from '@/types';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { Boxes, Upload } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    games: Paginated<InventoryGame>;
    filters: { search: string; stock: '' | 'low' | 'out' };
    threshold: number;
    maxImport: number;
}>();

const toast = useToast();
const form = reactive({ ...props.filters });
function apply() {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get(route('admin.inventory.index'), query, { only: ['games', 'filters'], preserveState: true, replace: true });
}
const applySearch = useDebounceFn(apply, 350);

// Synthetic-key import (demo). Codes go straight to the server, which normalises, validates,
// de-duplicates by fingerprint and audits counts only; they are never kept in page state after.
const importing = ref<InventoryGame | null>(null);
const codesText = ref('');
const submitting = ref(false);
const errors = ref<string[]>([]);
const codes = computed(() =>
    codesText.value
        .split(/\r?\n/)
        .map((c) => c.trim())
        .filter(Boolean),
);

function openImport(game: InventoryGame) {
    importing.value = game;
    codesText.value = '';
    errors.value = [];
}

async function submitImport() {
    if (!importing.value || submitting.value) {
        return;
    }
    submitting.value = true;
    errors.value = [];
    try {
        const { data } = await axios.post<{ imported: number; duplicates: number }>(
            route('admin.games.keys.store', importing.value.slug),
            { codes: codes.value },
            { headers: { Accept: 'application/json' } },
        );
        toast.success(`Imported ${data.imported} key(s)`, data.duplicates ? `${data.duplicates} duplicate(s) skipped.` : undefined);
        importing.value = null;
        codesText.value = '';
        router.reload({ only: ['games'] });
    } catch (e: unknown) {
        const body = axios.isAxiosError(e) ? (e.response?.data as ApiError | undefined) : undefined;
        errors.value = body?.errors
            ? Object.entries(body.errors).map(([field, messages]) => {
                  const line = /^codes\.(\d+)$/.exec(field);
                  return line ? `Line ${Number(line[1]) + 1}: ${messages[0].replace(/codes\.\d+/, 'code')}` : messages[0];
              })
            : [body?.message ?? 'The import failed. Please try again.'];
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AdminLayout title="Inventory" :description="`Stock per game. Low stock means fewer than ${threshold} available keys.`">
        <form class="flex flex-col gap-3 sm:flex-row" role="search" aria-label="Filter inventory" @submit.prevent="apply">
            <label for="inv-search" class="sr-only">Search games</label>
            <input
                id="inv-search"
                v-model="form.search"
                type="search"
                placeholder="Search games…"
                class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm placeholder:text-muted-foreground sm:flex-1"
                @input="applySearch"
            />
            <label for="inv-stock" class="sr-only">Stock level</label>
            <select
                id="inv-stock"
                v-model="form.stock"
                class="h-10 rounded-lg border border-input bg-card px-3 text-sm [&>option]:bg-card"
                @change="apply"
            >
                <option value="">All stock levels</option>
                <option value="low">Low stock</option>
                <option value="out">Sold out</option>
            </select>
        </form>

        <div class="gv-surface overflow-hidden">
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm" data-testid="inventory-table">
                    <thead class="border-b border-border/80 bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Game</th>
                            <th scope="col" class="px-4 py-3 font-medium">Available</th>
                            <th scope="col" class="px-4 py-3 font-medium">Reserved</th>
                            <th scope="col" class="px-4 py-3 font-medium">Sold</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="game in games.data" :key="game.slug" :data-testid="`inventory-${game.slug}`">
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ game.name }}</span>
                                <span class="ml-2 text-xs text-muted-foreground">{{ game.platform }}</span>
                                <Badge v-if="!game.is_active" class="ml-2">inactive</Badge>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :tone="game.available === 0 ? 'danger' : game.available < threshold ? 'warning' : 'success'" dot>{{
                                    game.available
                                }}</Badge>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-muted-foreground">{{ game.reserved }}</td>
                            <td class="px-4 py-3 tabular-nums text-muted-foreground">{{ game.sold }}</td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-accent"
                                    @click="openImport(game)"
                                >
                                    <Upload class="size-3.5" aria-hidden="true" /> Import keys
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!games.data.length" :icon="Boxes" title="No games match" class="m-4" />
        </div>
        <Pagination :meta="games.meta" noun="games" preserve-scroll />

        <Dialog :open="importing !== null" @update:open="(v) => !v && (importing = null)">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle class="font-display">Import keys · {{ importing?.name }}</DialogTitle>
                    <DialogDescription>
                        Synthetic demo keys, one per line (e.g. DEMO-ABCDE-12345). Up to {{ maxImport }} per import. Duplicates already in stock are
                        skipped.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submitImport">
                    <label for="import-codes" class="sr-only">Keys</label>
                    <textarea
                        id="import-codes"
                        v-model="codesText"
                        rows="8"
                        spellcheck="false"
                        autocomplete="off"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-sm"
                        :aria-invalid="errors.length > 0"
                        aria-describedby="import-errors"
                    />
                    <p class="text-xs text-muted-foreground">{{ codes.length }} key(s) ready</p>
                    <ul
                        v-if="errors.length"
                        id="import-errors"
                        class="max-h-32 space-y-1 overflow-y-auto text-sm text-[hsl(356_90%_76%)]"
                        role="alert"
                    >
                        <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
                    </ul>
                    <DialogFooter>
                        <button type="button" class="rounded-lg px-4 py-2 text-sm hover:bg-accent" @click="importing = null">Cancel</button>
                        <button
                            type="submit"
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50"
                            :disabled="submitting || codes.length === 0 || codes.length > maxImport"
                        >
                            {{ submitting ? 'Importing…' : 'Import' }}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
