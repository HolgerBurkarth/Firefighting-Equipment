<script setup lang="ts">
import { computed } from 'vue';
import type { ApiError } from '../api/client';
import { errorMessage, type ErrorContext } from '../auth/errorMessages';

const props = defineProps<{
    error: ApiError;
    context?: ErrorContext;
    isService?: boolean;
}>();

const text = computed(() =>
    errorMessage(props.error, {
        ...(props.context !== undefined && { context: props.context }),
        // Boolesche Props sind ohne Angabe false.
        isService: props.isService,
    }),
);
</script>

<!-- Fehler in Alltagssprache; der Code steht nur klein dabei (für den Support). -->
<template>
    <div class="notice notice-error" role="alert">
        <p class="notice-text">{{ text }}</p>
        <small class="notice-code" data-testid="error-code">Code: {{ error.code }}</small>
        <slot />
    </div>
</template>
