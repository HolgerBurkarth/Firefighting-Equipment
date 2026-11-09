import type { StockEntry, StockRule, StockRuleInput } from '../api/stock';

/** Grenzen wie `StockRepository` im Backend. */
export const STOCK_MAX_COUNT = 99;
export const STOCK_MAX_RULES = 20;

/** „Atemschutzmaske“, „Atemschutzmaske oder Funkgerät“, „A, B oder C“. */
export function typesText(names: readonly string[]): string {
    if (names.length <= 1) {
        return names[0] ?? '';
    }
    return `${names.slice(0, -1).join(', ')} oder ${names[names.length - 1] ?? ''}`;
}

/** „mindestens 3 Atemschutzmaske oder Funkgerät: 2 vorhanden, 1 fehlt“ (Soll, Ist, Fehlendes). */
export function ruleText(rule: Pick<StockRule, 'minCount' | 'types' | 'have' | 'missing'>): string {
    const types = typesText(rule.types.map((type) => type.name));
    const status =
        rule.missing > 0
            ? `${String(rule.have)} vorhanden, ${String(rule.missing)} ${rule.missing === 1 ? 'fehlt' : 'fehlen'}`
            : `${String(rule.have)} vorhanden`;
    return `mindestens ${String(rule.minCount)} ${types}: ${status}`;
}

/** Zusammenfassung über der Liste: „2 Standorte mit Sollbestand, 1 außer Betrieb“. */
export function stockSummary(entries: readonly StockEntry[]): string {
    if (entries.length === 0) {
        return 'Noch kein Sollbestand festgelegt.';
    }
    const out = entries.filter((entry) => entry.outOfService).length;
    const base = `${String(entries.length)} ${entries.length === 1 ? 'Standort' : 'Standorte'} mit Sollbestand`;
    return out === 0 ? `${base}, alle vollständig` : `${base}, ${String(out)} außer Betrieb`;
}

/** Ein Zeile im Fenster: Soll-Anzahl als Text (Eingabefeld) und die gewählten Typen. */
export interface RuleDraft {
    readonly count: string;
    readonly typeIds: readonly number[];
}

export function draftsOf(entry: StockEntry | null): RuleDraft[] {
    return (entry?.rules ?? []).map((rule) => ({
        count: String(rule.minCount),
        typeIds: rule.types.map((type) => type.id),
    }));
}

/** Problem der Eingabe oder null; `rules` sind danach sendbar (`stockInputs`). */
export function stockProblem(drafts: readonly RuleDraft[]): string | null {
    if (drafts.length > STOCK_MAX_RULES) {
        return `Höchstens ${String(STOCK_MAX_RULES)} Regeln je Standort.`;
    }
    for (const [index, draft] of drafts.entries()) {
        const count = Number(draft.count);
        if (!/^\d{1,2}$/.test(draft.count.trim()) || count < 1 || count > STOCK_MAX_COUNT) {
            return `Regel ${String(index + 1)}: Die Soll-Anzahl muss eine Zahl von 1 bis ${String(STOCK_MAX_COUNT)} sein.`;
        }
        if (draft.typeIds.length === 0) {
            return `Regel ${String(index + 1)}: Bitte mindestens einen Ausrüstungstyp wählen.`;
        }
    }
    return null;
}

export function stockInputs(drafts: readonly RuleDraft[]): StockRuleInput[] {
    return drafts.map((draft) => ({
        minCount: Number(draft.count.trim()),
        deviceTypeIds: [...draft.typeIds],
    }));
}

/** Unterschied zum gespeicherten Stand (für „ungespeichert“). */
export function draftsChanged(entry: StockEntry | null, drafts: readonly RuleDraft[]): boolean {
    return JSON.stringify(draftsOf(entry)) !== JSON.stringify(drafts);
}
