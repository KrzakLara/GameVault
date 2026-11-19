<script setup lang="ts">
import GvLogo from '@/components/GvLogo.vue';
import Toaster from '@/components/ui/gv/Toaster.vue';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { useFlashToasts } from '@/composables/useFlashToasts';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Activity, ArrowLeft, Boxes, ExternalLink, Gauge, Menu, Package, Send } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Operator console shell. Visibility of this layout is cosmetic: every admin route is behind
 * auth + can:admin and re-authorised in its Form Request.
 */
const props = defineProps<{ title: string; description?: string }>();

const page = usePage();
useFlashToasts();

const items = computed(() => [
    { label: 'Overview', href: route('admin.dashboard'), icon: Gauge, active: page.url === '/admin' },
    { label: 'Orders', href: route('admin.orders.index'), icon: Package, active: page.url.startsWith('/admin/orders') },
    { label: 'Inventory', href: route('admin.inventory.index'), icon: Boxes, active: page.url.startsWith('/admin/inventory') },
    { label: 'Outbox', href: route('admin.outbox.index'), icon: Send, active: page.url.startsWith('/admin/outbox') },
]);
</script>

<template>
    <Head :title="`${props.title} · Admin`" />
    <div class="flex min-h-screen">
        <a
            href="#admin-main"
            class="sr-only z-[70] rounded-md bg-primary px-4 py-2 text-primary-foreground focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
        >
            Skip to content
        </a>

        <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-border/70 bg-sidebar lg:flex">
            <div class="flex h-16 items-center gap-2 border-b border-border/70 px-5">
                <GvLogo size="sm" />
                <span class="rounded-md bg-primary/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-[hsl(258_100%_84%)]"
                    >Ops</span
                >
            </div>
            <nav class="flex-1 space-y-1 p-3" aria-label="Admin">
                <Link
                    v-for="item in items"
                    :key="item.label"
                    :href="item.href"
                    :aria-current="item.active ? 'page' : undefined"
                    :class="[
                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                        item.active
                            ? 'bg-primary/15 text-foreground ring-1 ring-primary/25'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    ]"
                >
                    <component :is="item.icon" :class="['size-4', item.active ? 'text-primary' : '']" aria-hidden="true" />
                    {{ item.label }}
                </Link>
                <a
                    href="/horizon"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition hover:bg-accent hover:text-foreground"
                >
                    <Activity class="size-4" aria-hidden="true" /> Horizon <ExternalLink class="ml-auto size-3.5" aria-hidden="true" />
                </a>
            </nav>
            <div class="border-t border-border/70 p-3">
                <Link
                    :href="route('home')"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" /> Back to store
                </Link>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-border/70 bg-background/80 px-4 backdrop-blur-xl sm:px-6">
                <Sheet>
                    <SheetTrigger class="rounded-lg p-2 text-muted-foreground hover:bg-accent lg:hidden" aria-label="Open admin menu">
                        <Menu class="size-5" aria-hidden="true" />
                    </SheetTrigger>
                    <SheetContent side="left" class="w-72 bg-sidebar">
                        <SheetTitle class="sr-only">Admin navigation</SheetTitle>
                        <GvLogo size="sm" />
                        <nav class="mt-8 space-y-1" aria-label="Admin mobile">
                            <Link
                                v-for="item in items"
                                :key="item.label"
                                :href="item.href"
                                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                            >
                                <component :is="item.icon" class="size-4" aria-hidden="true" /> {{ item.label }}
                            </Link>
                            <Link
                                :href="route('home')"
                                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-accent"
                            >
                                <ArrowLeft class="size-4" aria-hidden="true" /> Back to store
                            </Link>
                        </nav>
                    </SheetContent>
                </Sheet>
                <div class="min-w-0">
                    <h1 class="truncate font-display text-lg font-semibold">{{ title }}</h1>
                    <p v-if="description" class="hidden truncate text-xs text-muted-foreground sm:block">{{ description }}</p>
                </div>
                <div class="ml-auto"><slot name="actions" /></div>
            </header>
            <main id="admin-main" class="flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>
        <Toaster />
    </div>
</template>
