import { describe, expect, it } from 'vitest';
import { REFRESH_MIN_INTERVAL_MS, Refresher } from '../../src/data/refresher';

/** Steuerbares Laden: jede Abfrage wartet, bis der Test sie mit finish() beendet. */
function controlledLoad() {
    const pending: ((ok: boolean) => void)[] = [];
    let calls = 0;
    return {
        get calls(): number {
            return calls;
        },
        load: (): Promise<boolean> => {
            calls += 1;
            return new Promise((resolve) => pending.push(resolve));
        },
        finish(ok = true): void {
            pending.shift()?.(ok);
        },
    };
}

function clock(start = 1_000_000) {
    let time = start;
    return {
        now: (): number => time,
        advance(ms: number): void {
            time += ms;
        },
    };
}

async function settle(): Promise<void> {
    for (let i = 0; i < 5; i += 1) {
        await Promise.resolve();
    }
}

describe('Refresher', () => {
    it('Mindestabstände wie beschlossen (visible 30 s)', () => {
        expect(REFRESH_MIN_INTERVAL_MS).toEqual({ open: 5_000, visible: 30_000, changed: 0 });
    });

    it('legt gleichzeitige Aufrufe zusammen', async () => {
        const source = controlledLoad();
        const refresher = new Refresher(source.load);
        const a = refresher.refresh('open');
        const b = refresher.refresh('open');
        expect(source.calls).toBe(1);
        source.finish();
        await Promise.all([a, b]);
        expect(source.calls).toBe(1);
    });

    it('„visible“ erst, nachdem die Daten einmal angefordert wurden', async () => {
        const source = controlledLoad();
        const refresher = new Refresher(source.load);
        await refresher.refresh('visible');
        expect(source.calls).toBe(0);
        const open = refresher.refresh('open');
        source.finish();
        await open;
        expect(source.calls).toBe(1);
    });

    it('„visible“ höchstens alle 30 Sekunden, „changed“ immer', async () => {
        const source = controlledLoad();
        const time = clock();
        const refresher = new Refresher(source.load, time.now);
        const first = refresher.refresh('open');
        source.finish();
        await first;

        time.advance(29_999);
        await refresher.refresh('visible');
        expect(source.calls).toBe(1);

        const changed = refresher.refresh('changed');
        expect(source.calls).toBe(2);
        source.finish();
        await changed;

        time.advance(30_000);
        const visible = refresher.refresh('visible');
        expect(source.calls).toBe(3);
        source.finish();
        await visible;
    });

    it('„open“ nur nach mehr als 5 Sekunden erneut', async () => {
        const source = controlledLoad();
        const time = clock();
        const refresher = new Refresher(source.load, time.now);
        const first = refresher.refresh('open');
        source.finish();
        await first;
        time.advance(4_999);
        await refresher.refresh('open');
        expect(source.calls).toBe(1);
        time.advance(1);
        const second = refresher.refresh('open');
        expect(source.calls).toBe(2);
        source.finish();
        await second;
    });

    it('nach einem Fehler gilt kein Mindestabstand', async () => {
        const source = controlledLoad();
        const refresher = new Refresher(source.load, clock().now);
        const first = refresher.refresh('open');
        source.finish(false);
        await first;
        const again = refresher.refresh('visible');
        expect(source.calls).toBe(2);
        source.finish();
        await again;
    });

    it('„changed“ während einer laufenden Abfrage lädt danach noch einmal', async () => {
        const source = controlledLoad();
        const refresher = new Refresher(source.load);
        const open = refresher.refresh('open');
        const changed = refresher.refresh('changed');
        // Weitere Aufrufe während der zweiten Runde schließen sich an.
        source.finish();
        await settle();
        expect(source.calls).toBe(2);
        let done = false;
        void changed.then(() => {
            done = true;
        });
        await settle();
        expect(done).toBe(false);
        source.finish();
        await Promise.all([open, changed]);
        expect(source.calls).toBe(2);
    });

    it('reset: laufende Abfrage zählt nicht, neue beginnt sofort', async () => {
        const source = controlledLoad();
        const refresher = new Refresher(source.load, clock().now);
        const stale = refresher.refresh('open');
        refresher.reset();
        source.finish();
        await stale;
        // Nach dem Zurücksetzen gilt „visible“ wieder erst nach einer Anforderung.
        await refresher.refresh('visible');
        expect(source.calls).toBe(1);
        // Der Erfolg der alten Abfrage setzt keinen Mindestabstand.
        const fresh = refresher.refresh('open');
        expect(source.calls).toBe(2);
        source.finish();
        await fresh;
    });

    it('reset während einer Abfrage: ein neuer Aufruf wartet nicht auf die alte', () => {
        const source = controlledLoad();
        const refresher = new Refresher(source.load);
        void refresher.refresh('open');
        refresher.reset();
        void refresher.refresh('open');
        expect(source.calls).toBe(2);
    });
});
