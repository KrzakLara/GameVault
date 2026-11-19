<script setup lang="ts">
import StoreLayout from '@/layouts/StoreLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

// Account pages (dashboard, settings) share the storefront shell; breadcrumbs stay supported.
withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), { breadcrumbs: () => [] });
</script>

<template>
    <StoreLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <nav v-if="breadcrumbs.length" aria-label="Breadcrumb" class="mb-6">
                <ol class="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
                    <li v-for="(crumb, i) in breadcrumbs" :key="crumb.href" class="flex items-center gap-1.5">
                        <ChevronRight v-if="i > 0" class="size-3.5" aria-hidden="true" />
                        <Link v-if="i < breadcrumbs.length - 1" :href="crumb.href" class="hover:text-foreground">{{ crumb.title }}</Link>
                        <span v-else class="text-foreground" aria-current="page">{{ crumb.title }}</span>
                    </li>
                </ol>
            </nav>
            <slot />
        </div>
    </StoreLayout>
</template>
