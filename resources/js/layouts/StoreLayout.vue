<script setup lang="ts">
import GvLogo from '@/components/GvLogo.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import Toaster from '@/components/ui/gv/Toaster.vue';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useFlashToasts } from '@/composables/useFlashToasts';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, Gamepad2, LayoutGrid, Menu, Package, ShieldCheck, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
useFlashToasts();

const nav = computed(() => [
    { label: 'Discover', href: route('home'), icon: Sparkles, active: page.url === '/' },
    { label: 'Catalogue', href: route('games.index'), icon: LayoutGrid, active: page.url.startsWith('/games') },
    ...(user.value ? [{ label: 'My orders', href: route('orders.index'), icon: Package, active: page.url.startsWith('/my-orders') }] : []),
]);
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <a
            href="#main"
            class="sr-only z-[70] rounded-md bg-primary px-4 py-2 text-primary-foreground focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
        >
            Skip to content
        </a>

        <header class="sticky top-0 z-40 border-b border-border/70 bg-background/80 backdrop-blur-xl supports-[backdrop-filter]:bg-background/60">
            <div class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">
                <Link :href="route('home')" class="shrink-0 rounded-lg" aria-label="GameVault home">
                    <GvLogo size="sm" />
                </Link>

                <nav class="hidden items-center gap-1 md:flex" aria-label="Main">
                    <Link
                        v-for="item in nav"
                        :key="item.label"
                        :href="item.href"
                        :aria-current="item.active ? 'page' : undefined"
                        :class="[
                            'rounded-lg px-3 py-2 text-sm font-medium transition',
                            item.active ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                        ]"
                    >
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <Link
                        v-if="user?.is_admin"
                        :href="route('admin.dashboard')"
                        class="hidden items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/10 px-3 py-1.5 text-sm font-medium text-[hsl(258_100%_84%)] transition hover:bg-primary/20 sm:inline-flex"
                    >
                        <ShieldCheck class="size-4" aria-hidden="true" /> Admin
                    </Link>

                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger
                            class="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition hover:bg-accent hover:text-foreground"
                            data-testid="user-menu"
                        >
                            <span
                                class="flex size-7 items-center justify-center rounded-full bg-gradient-to-br from-primary to-highlight text-xs font-bold text-white"
                                aria-hidden="true"
                            >
                                {{ user.name.slice(0, 1).toUpperCase() }}
                            </span>
                            <span class="hidden max-w-32 truncate sm:inline">{{ user.name }}</span>
                            <ChevronDown class="size-4" aria-hidden="true" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent class="w-56" align="end">
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition hover:text-foreground"
                        >
                            Sign in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="hidden rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-primary-foreground shadow-lg shadow-primary/25 transition hover:bg-primary/90 sm:inline-flex"
                        >
                            Create account
                        </Link>
                    </template>

                    <Sheet>
                        <SheetTrigger
                            class="rounded-lg p-2 text-muted-foreground hover:bg-accent hover:text-foreground md:hidden"
                            aria-label="Open menu"
                        >
                            <Menu class="size-5" aria-hidden="true" />
                        </SheetTrigger>
                        <SheetContent side="left" class="w-72 border-border bg-background">
                            <SheetTitle class="sr-only">Navigation</SheetTitle>
                            <GvLogo size="sm" />
                            <nav class="mt-8 flex flex-col gap-1" aria-label="Mobile">
                                <Link
                                    v-for="item in nav"
                                    :key="item.label"
                                    :href="item.href"
                                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                                >
                                    <component :is="item.icon" class="size-4" aria-hidden="true" /> {{ item.label }}
                                </Link>
                                <Link
                                    v-if="user?.is_admin"
                                    :href="route('admin.dashboard')"
                                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                                >
                                    <ShieldCheck class="size-4" aria-hidden="true" /> Admin
                                </Link>
                            </nav>
                        </SheetContent>
                    </Sheet>
                </div>
            </div>
        </header>

        <main id="main" class="flex-1">
            <slot />
        </main>

        <footer class="mt-24 border-t border-border/70">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-10 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
                <div class="space-y-2">
                    <GvLogo size="sm" />
                    <p class="max-w-md text-sm text-muted-foreground">
                        A portfolio demonstration. Every game is fictional, every key is synthetic and checkout is simulated: no payment is ever
                        taken.
                    </p>
                </div>
                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                    <Gamepad2 class="size-4 text-primary" aria-hidden="true" />
                    Built with Laravel, Vue and Inertia
                </div>
            </div>
        </footer>

        <Toaster />
    </div>
</template>
