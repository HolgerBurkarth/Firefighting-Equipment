import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useCountdown } from '../../src/runtime/countdown';

describe('useCountdown', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('zählt nach der Uhr herunter und endet bei 0', () => {
        const countdown = useCountdown();
        countdown.start(3);
        expect(countdown.seconds.value).toBe(3);
        vi.advanceTimersByTime(1100);
        expect(countdown.seconds.value).toBe(2);
        vi.advanceTimersByTime(2000);
        expect(countdown.seconds.value).toBe(0);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('neuer Start ersetzt den alten, stop gibt sofort frei', () => {
        const countdown = useCountdown();
        countdown.start(60);
        countdown.start(5);
        expect(countdown.seconds.value).toBe(5);
        expect(vi.getTimerCount()).toBe(1);
        countdown.stop();
        expect(countdown.seconds.value).toBe(0);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('0 oder weniger sperrt nicht', () => {
        const countdown = useCountdown();
        countdown.start(0);
        expect(countdown.seconds.value).toBe(0);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('endet mit der Komponente (Scope)', () => {
        const scope = effectScope();
        const countdown = scope.run(() => useCountdown());
        countdown?.start(30);
        expect(vi.getTimerCount()).toBe(1);
        scope.stop();
        expect(vi.getTimerCount()).toBe(0);
    });
});
