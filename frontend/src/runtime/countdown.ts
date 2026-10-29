import { getCurrentScope, onScopeDispose, readonly, ref, type Ref } from 'vue';

/** Wie oft die Restzeit neu berechnet wird (sie folgt der Uhr, nicht der Zahl der Takte). */
const TICK_MS = 250;

export interface Countdown {
    /** Verbleibende Sekunden (aufgerundet); 0 = nicht gesperrt. */
    readonly seconds: Readonly<Ref<number>>;
    start(seconds: number): void;
    stop(): void;
}

/**
 * Sperre nach 429 (`Retry-After`): Der Knopf bleibt gesperrt, bis die Wartezeit abgelaufen ist.
 * In einer Komponente endet der Takt mit ihr.
 */
export function useCountdown(): Countdown {
    const seconds = ref(0);
    let timer: ReturnType<typeof setInterval> | null = null;

    function stop(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
        seconds.value = 0;
    }

    function start(total: number): void {
        stop();
        if (total <= 0) {
            return;
        }
        const until = Date.now() + total * 1000;
        seconds.value = Math.ceil(total);
        timer = setInterval(() => {
            seconds.value = Math.max(0, Math.ceil((until - Date.now()) / 1000));
            if (seconds.value === 0) {
                stop();
            }
        }, TICK_MS);
    }

    if (getCurrentScope() !== undefined) {
        onScopeDispose(stop);
    }
    return { seconds: readonly(seconds), start, stop };
}
