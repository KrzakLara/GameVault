import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';

import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        // Generated shadcn-vue primitives stay excluded; GameVault's own components (ui/gv) are linted.
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'bootstrap/ssr',
            'tailwind.config.js',
            'playwright-report',
            'test-results',
            'resources/js/components/ui/*',
            '!resources/js/components/ui/gv',
        ],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
        },
    },
    prettier,
);
