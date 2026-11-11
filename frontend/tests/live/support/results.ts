/** Ergebnisliste des Live-Laufs: ☑ bestanden, ☒ gescheitert, ☐ übersprungen. */
export interface LiveResult {
    id: string;
    text: string;
    mark: '☑' | '☒' | '☐';
    detail?: string;
}

export class ResultList {
    readonly items: LiveResult[] = [];

    /** Führt einen Punkt aus; ein Fehler macht ihn zu ☒, der Lauf geht weiter. */
    async step(id: string, text: string, run: () => Promise<void>): Promise<boolean> {
        try {
            await run();
            this.items.push({ id, text, mark: '☑' });
            return true;
        } catch (error) {
            this.items.push({ id, text, mark: '☒', detail: firstLine(error) });
            return false;
        }
    }

    skip(id: string, text: string, reason: string): void {
        this.items.push({ id, text, mark: '☐', detail: reason });
    }

    get failed(): number {
        return this.items.filter((item) => item.mark === '☒').length;
    }

    render(): string {
        return this.items
            .map(
                (item) =>
                    `${item.mark} ${item.id} ${item.text}${item.detail ? ` – ${item.detail}` : ''}`,
            )
            .join('\n');
    }
}

/** Erste Zeile einer Fehlermeldung, ohne Escape-Folgen der Konsole und gekürzt. */
export function firstLine(error: unknown): string {
    const text = error instanceof Error ? error.message : String(error);
    const clean = text.replace(/\u001b\[[0-9;]*m/g, '');
    const line = clean.split('\n').find((candidate) => candidate.trim() !== '') ?? '';
    return line.length > 300 ? `${line.slice(0, 300)} …` : line;
}
