/**
 * Anlass einer Auffrischung (KONZEPT.md, "User am Handy – Entscheidungen", 14 und 23): Seite
 * geöffnet, App wieder sichtbar, eigene Änderung (z. B. Buchung).
 */
export type RefreshReason = 'open' | 'visible' | 'changed';

/**
 * Mindestabstand je Anlass seit dem letzten erfolgreichen Laden. `visible` höchstens alle
 * 30 Sekunden (Entscheidung 23); `open` legt nur schnelle Seitenwechsel zusammen (z. B. nach dem
 * Speichern zurück zu „Suchen“, das schon `changed` geladen hat); `changed` immer.
 */
export const REFRESH_MIN_INTERVAL_MS: Readonly<Record<RefreshReason, number>> = {
    open: 5_000,
    visible: 30_000,
    changed: 0,
};

/**
 * Steuert, wann eine Datenquelle neu lädt: höchstens eine Abfrage gleichzeitig (weitere Aufrufer
 * warten auf sie), Mindestabstände je Anlass, `visible` erst, nachdem die Daten einmal angefordert
 * wurden (z. B. nicht auf der Anmeldeseite). Kommt `changed` während einer laufenden Abfrage, lädt
 * sie danach noch einmal: Sie begann womöglich vor der Änderung.
 */
export class Refresher {
    private running: Promise<void> | null = null;
    private again = false;
    private used = false;
    private lastSuccess: number | null = null;
    private generation = 0;

    /** `load` liefert true bei Erfolg (nur dann zählt der Mindestabstand). */
    constructor(
        private readonly load: () => Promise<boolean>,
        private readonly now: () => number = Date.now,
    ) {}

    refresh(reason: RefreshReason): Promise<void> {
        if (reason === 'visible' && !this.used) {
            return Promise.resolve();
        }
        this.used = true;
        if (this.running !== null) {
            if (reason === 'changed') {
                this.again = true;
            }
            return this.running;
        }
        if (
            this.lastSuccess !== null &&
            this.now() - this.lastSuccess < REFRESH_MIN_INTERVAL_MS[reason]
        ) {
            return Promise.resolve();
        }
        const started = this.loop(this.generation).finally(() => {
            if (this.running === started) {
                this.running = null;
            }
        });
        this.running = started;
        return started;
    }

    /** Vergisst alles (z. B. beim Abmelden); eine laufende Abfrage zählt danach nicht mehr. */
    reset(): void {
        this.generation += 1;
        this.running = null;
        this.again = false;
        this.used = false;
        this.lastSuccess = null;
    }

    private async loop(generation: number): Promise<void> {
        do {
            this.again = false;
            const ok = await this.load();
            if (generation !== this.generation) {
                return;
            }
            if (ok) {
                this.lastSuccess = this.now();
            }
        } while (this.changedWhileLoading());
    }

    /** Eigene Methode: `again` ändert sich während des await (TypeScript sähe sonst immer false). */
    private changedWhileLoading(): boolean {
        return this.again;
    }
}
