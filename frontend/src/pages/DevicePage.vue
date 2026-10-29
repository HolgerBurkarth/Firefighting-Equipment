<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import FocusHeader from '../components/FocusHeader.vue';
import { rememberDevice } from '../data';

const route = useRoute();
const deviceId = computed(() => Number(route.params['id']));

// Geöffnet → „Zuletzt benutzt“ (Entscheidung 16).
onMounted(() => {
    rememberDevice(deviceId.value);
});
</script>

<!-- Platzhalter: Umbuchen/Status eines Geräts (Mockup UserUmbuchen). -->
<template>
    <FocusHeader :title="`Gerät #${deviceId}`" />
    <div class="focus-content">
        <p class="placeholder">Hier entsteht das Umbuchen.</p>
    </div>
</template>

<style scoped>
.focus-content {
    padding: 16px;
}

.placeholder {
    margin: 0;
    color: var(--muted);
}
</style>
