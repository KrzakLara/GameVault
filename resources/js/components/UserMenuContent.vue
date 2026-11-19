<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import type { User } from '@/types';
import { Link } from '@inertiajs/vue3';
import { LayoutDashboard, LogOut, Package, Settings, ShieldCheck } from 'lucide-vue-next';

defineProps<{ user: User }>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('dashboard')"><LayoutDashboard class="mr-2 size-4" />My account</Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('orders.index')"><Package class="mr-2 size-4" />My orders</Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="user.is_admin" :as-child="true">
            <Link class="block w-full" :href="route('admin.dashboard')"><ShieldCheck class="mr-2 size-4" />Admin console</Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('profile.edit')"><Settings class="mr-2 size-4" />Settings</Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="block w-full" method="post" :href="route('logout')" as="button" data-testid="logout"><LogOut class="mr-2 size-4" />Log out</Link>
    </DropdownMenuItem>
</template>
