import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import skipFormatting from '@vue/eslint-config-prettier/skip-formatting';
import playwright from 'eslint-plugin-playwright';
import pluginVue from 'eslint-plugin-vue';
import { globalIgnores } from 'eslint/config';

// Formatierung prüft Prettier (npm run lint), ESLint nur den Code.
export default defineConfigWithVueTs(
    { name: 'app/files', files: ['**/*.{ts,mts,vue}'] },
    globalIgnores([
        'dist/**',
        'dev-dist/**',
        'playwright-report/**',
        'test-results/**',
        'blob-report/**',
        'eslint.config.js',
    ]),
    pluginVue.configs['flat/recommended'],
    vueTsConfigs.strictTypeChecked,
    vueTsConfigs.stylisticTypeChecked,
    {
        name: 'app/rules',
        rules: {
            'no-console': 'error',
            'vue/block-lang': ['error', { script: { lang: 'ts' } }],
            'vue/component-api-style': ['error', ['script-setup']],
            'vue/no-v-html': 'error',
            '@typescript-eslint/restrict-template-expressions': ['error', { allowNumber: true }],
        },
    },
    {
        ...playwright.configs['flat/recommended'],
        files: ['tests/e2e/**/*.ts'],
        rules: {
            ...playwright.configs['flat/recommended'].rules,
            // Erlaubt nur begründete, bedingte Ausnahmen je Browser.
            'playwright/no-skipped-test': ['error', { allowConditional: true }],
        },
    },
    skipFormatting,
);
