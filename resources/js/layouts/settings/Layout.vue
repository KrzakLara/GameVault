<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { KeyRound, UserRound } from 'lucide-vue-next';

const items: NavItem[] = [
    { title: 'Profile', href: '/settings/profile', icon: UserRound },
    { title: 'Password', href: '/settings/password', icon: KeyRound },
];

const page = usePage();
</script>

<template>
    <div>
        <Heading title="Settings" description="Manage your profile and account security" />

        <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
            <aside class="w-full lg:w-52">
                <nav class="flex gap-1 lg:flex-col" aria-label="Settings">
                    <Link
                        v-for="item in items"
                        :key="item.href"
                        :href="item.href"
                        :aria-current="page.url === item.href ? 'page' : undefined"
                        :class="[
                            'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition',
                            page.url === item.href ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                        ]"
                    >
                        <component :is="item.icon" class="size-4" aria-hidden="true" />
                        {{ item.title }}
                    </Link>
                </nav>
            </aside>

            <div class="gv-surface max-w-2xl flex-1 p-6">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
