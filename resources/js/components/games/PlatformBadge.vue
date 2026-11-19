<script setup lang="ts">
import type { Platform } from '@/types';
import { Gamepad2, Joystick, Monitor } from 'lucide-vue-next';
import type { Component } from 'vue';

defineProps<{ platform: Platform }>();

const icons: Record<Platform, Component> = { Steam: Monitor, PlayStation: Gamepad2, Xbox: Joystick };
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-md bg-black/55 px-2 py-1 text-xs font-medium text-white backdrop-blur-sm">
        <component :is="icons[platform]" class="size-3.5" aria-hidden="true" />
        {{ platform === 'Steam' ? 'Steam · PC' : platform }}
    </span>
</template>
