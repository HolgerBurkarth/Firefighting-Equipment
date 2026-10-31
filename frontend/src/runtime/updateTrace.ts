/**
 * Zeitleiste der Updates (KONZEPT.md „Updates der App“, Diagnose): Was die App beim Start und beim
 * Aktualisieren mit dem Service Worker erlebt, steht je Tab in sessionStorage und übersteht so ein
 * Neuladen. Lesbar in der Konsole mit `JSON.parse(sessionStorage['ff-update-trace'])`. Nur feste
 * Ereignisnamen und Build-Kennungen, keine Adressen (Token im Fragment) und keine persönlichen Daten.
 */

export const UPDATE_TRACE_KEY = 'ff-update-trace';
/** Höchstens so viele Einträge, die ältesten fallen weg. */
export const UPDATE_TRACE_MAX = 50;

export interface UpdateTraceEntry {
    /** Zeitpunkt (ISO 8601, UTC). */
    readonly at: string;
    readonly event: string;
    readonly detail?: string;
}

export class UpdateTrace {
    constructor(
        private readonly storage: Pick<Storage, 'getItem' | 'setItem'> | null,
        private readonly now: () => number,
    ) {}

    /** Hängt einen Eintrag an; Fehler des Speichers (gesperrt, voll) bleiben ohne Folgen. */
    record(event: string, detail?: string): void {
        if (this.storage === null) {
            return;
        }
        const entry: UpdateTraceEntry = {
            at: new Date(this.now()).toISOString(),
            event,
            ...(detail !== undefined && { detail }),
        };
        try {
            const entries = [...this.read(), entry].slice(-UPDATE_TRACE_MAX);
            this.storage.setItem(UPDATE_TRACE_KEY, JSON.stringify(entries));
        } catch {
            // Ohne Speicher keine Zeitleiste; das Update selbst läuft weiter.
        }
    }

    private read(): unknown[] {
        let parsed: unknown = null;
        try {
            parsed = JSON.parse(this.storage?.getItem(UPDATE_TRACE_KEY) ?? '[]');
        } catch {
            return [];
        }
        return Array.isArray(parsed) ? parsed : [];
    }
}
