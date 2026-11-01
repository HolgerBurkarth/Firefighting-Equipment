<script setup lang="ts">
import { computed, ref } from 'vue';
import { otherQueues } from '../data';
import { otherQueueText, type OtherQueue } from '../data/otherQueues';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * Nicht gesendete Buchungen anderer Konten auf diesem Gerät (KONZEPT.md, "Offline-Modus –
 * Entscheidungen", 55), auf „Suchen“: Sie gehen raus, sobald sich das Konto wieder anmeldet;
 * „Verwerfen …“ löscht sie nach Rückfrage.
 */
const queues = computed(() => otherQueues.list);
const pending = ref<OtherQueue | null>(null);
const confirmOpen = ref(false);

const description = computed(() => {
    const queue = pending.value;
    if (queue === null) {
        return '';
    }
    return queue.count === 1
        ? `Die nicht gesendete Buchung von ${queue.name} wird von diesem Gerät gelöscht und nie gesendet.`
        : `Die ${String(queue.count)} nicht gesendeten Buchungen von ${queue.name} werden von diesem Gerät gelöscht und nie gesendet.`;
});

function ask(queue: OtherQueue): void {
    pending.value = queue;
    confirmOpen.value = true;
}

async function discard(): Promise<void> {
    const queue = pending.value;
    pending.value = null;
    if (queue !== null) {
        await otherQueues.discard(queue.accountId);
    }
}
</script>

<template>
    <div
        v-for="queue in queues"
        :key="queue.accountId"
        class="notice notice-info other-queue"
        role="status"
        data-testid="other-queue"
    >
        <p>{{ otherQueueText(queue) }}</p>
        <button class="btn other-queue-discard" type="button" @click="ask(queue)">
            Verwerfen …
        </button>
    </div>
    <ConfirmDialog
        v-model:open="confirmOpen"
        title="Buchungen verwerfen?"
        :description="description"
        confirm-label="Verwerfen"
        @confirm="discard"
    />
</template>

<style scoped>
.other-queue {
    align-items: flex-start;
}
</style>
