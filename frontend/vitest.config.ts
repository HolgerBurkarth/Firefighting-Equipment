import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    define: { __APP_BUILD_ID__: JSON.stringify('test') },
    test: {
        environment: 'happy-dom',
        // Basis-Adresse wie unter einer Stadt (apiUrl liest document.baseURI).
        environmentOptions: { happyDOM: { url: 'https://www.prodad.de/firefighting/test/' } },
        include: ['tests/unit/**/*.spec.ts'],
    },
});
