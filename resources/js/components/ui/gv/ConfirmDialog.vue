<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { computed, ref, watch } from 'vue';

/**
 * Accessible confirmation (radix Dialog: focus trap, Escape, labelled title/description).
 * With `reasonLabel`, the user must type a reason, which is passed to `confirm` (e.g. audited
 * cancellations). The dialog never performs the action itself: the parent does, server-side.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description: string;
        confirmLabel?: string;
        destructive?: boolean;
        busy?: boolean;
        reasonLabel?: string;
        reasonMinLength?: number;
        error?: string | null;
    }>(),
    { confirmLabel: 'Confirm', destructive: false, busy: false, reasonMinLength: 5, error: null },
);
const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [reason: string] }>();

const reason = ref('');
watch(
    () => props.open,
    (open) => {
        if (open) {
            reason.value = '';
        }
    },
);
const canConfirm = computed(() => !props.busy && (!props.reasonLabel || reason.value.trim().length >= props.reasonMinLength));
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="font-display">{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="canConfirm && emit('confirm', reason.trim())">
                <div v-if="reasonLabel" class="space-y-2">
                    <label for="confirm-reason" class="text-sm font-medium">{{ reasonLabel }}</label>
                    <textarea
                        id="confirm-reason"
                        v-model="reason"
                        rows="3"
                        required
                        :minlength="reasonMinLength"
                        maxlength="500"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground"
                        :aria-invalid="!!error"
                        aria-describedby="confirm-reason-help"
                    />
                    <p id="confirm-reason-help" class="text-xs text-muted-foreground">At least {{ reasonMinLength }} characters. Stored in the audit log.</p>
                </div>
                <p v-if="error" class="text-sm text-[hsl(356_90%_72%)]" role="alert">{{ error }}</p>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :variant="destructive ? 'destructive' : 'default'" :disabled="!canConfirm" data-testid="confirm-action">
                        {{ busy ? 'Working…' : confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
