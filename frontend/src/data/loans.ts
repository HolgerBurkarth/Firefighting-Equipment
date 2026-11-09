import type { Loan, LoanPlace, LoanState } from '../api/loans';
import type { DashboardTile } from './dashboard';

/** `2026-11-12` → `12.11.2026`. */
export function germanDate(iso: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
    return match === null ? iso : `${match[3] ?? ''}.${match[2] ?? ''}.${match[1] ?? ''}`;
}

/** „Rückgabe bis 12.11.2026“, überfällig „seit 3 Tagen überfällig (bis 09.11.2026)“, ohne Frist „keine Frist“. */
export function dueText(loan: Pick<Loan, 'dueBackOn' | 'overdue' | 'daysOverdue'>): string {
    if (loan.dueBackOn === null) {
        return 'keine Frist';
    }
    if (!loan.overdue) {
        return `Rückgabe bis ${germanDate(loan.dueBackOn)}`;
    }
    const days = loan.daysOverdue === 1 ? '1 Tag' : `${String(loan.daysOverdue)} Tagen`;
    return `seit ${days} überfällig (bis ${germanDate(loan.dueBackOn)})`;
}

export interface LoanGroup {
    readonly borrower: LoanPlace;
    readonly loans: readonly Loan[];
    readonly overdue: number;
}

/** Die Verleihe nach Leihnehmer (die Reihenfolge des Servers bleibt). */
export function groupLoans(loans: readonly Loan[]): LoanGroup[] {
    const groups: { borrower: LoanPlace; loans: Loan[] }[] = [];
    for (const loan of loans) {
        const group = groups.find((entry) => entry.borrower.id === loan.borrower.id);
        if (group === undefined) {
            groups.push({ borrower: loan.borrower, loans: [loan] });
        } else {
            group.loans.push(loan);
        }
    }
    return groups.map((group) => ({
        ...group,
        overdue: group.loans.filter((loan) => loan.overdue).length,
    }));
}

/** Zusammenfassung über der Liste: „7 verliehen, davon 2 überfällig“. */
export function loansSummary(state: Pick<LoanState, 'open' | 'overdue'>): string {
    if (state.open === 0) {
        return 'Zurzeit ist nichts verliehen.';
    }
    const base = `${String(state.open)} verliehen`;
    return state.overdue > 0 ? `${base}, davon ${String(state.overdue)} überfällig` : base;
}

/** Kachel „Verliehen“ des Dashboards. */
export function loansTile(counts: {
    readonly open: number;
    readonly overdue: number;
}): DashboardTile {
    return {
        value: String(counts.open),
        detail:
            counts.open === 0 ? 'Nichts verliehen' : `davon ${String(counts.overdue)} überfällig`,
    };
}

/** Eine Ausrüstung im Fenster „Verleihen“. */
export interface LendCandidate {
    readonly id: number;
    readonly version: number;
    readonly identifier: string;
    readonly typeName: string;
}

/** Problem der Eingabe im Fenster „Verleihen“ oder null. */
export function lendProblem(
    candidates: readonly LendCandidate[],
    borrowerId: string,
    contact: string,
): string | null {
    if (candidates.length === 0) {
        return 'Bitte mindestens eine Ausrüstung hinzufügen.';
    }
    if (borrowerId === '') {
        return 'Bitte wählen Sie den Leihnehmer.';
    }
    if (Array.from(contact.trim()).length > 100) {
        return 'Der Ansprechpartner darf höchstens 100 Zeichen lang sein.';
    }
    return null;
}

/**
 * Link im QR-Code des Aushangs der Rückgabe: `…/rueckgabe#schluessel=<Schlüssel>`; der Schlüssel steht nur
 * im Fragment und erreicht so nie die Server-Adresse (wie `#code=` bei der Registrierung).
 */
export function returnLink(key: string, baseUri: string = document.baseURI): string {
    return new URL(`rueckgabe#schluessel=${key}`, baseUri).href;
}

/** Adresse zum Abtippen auf dem Aushang, ohne Schema und Schlüssel. */
export function returnAddress(baseUri: string = document.baseURI): string {
    const url = new URL('rueckgabe', baseUri);
    return `${url.host}${url.pathname}`;
}

/** Zustand beim Zurückgeben, so heißen die Knöpfe auf der öffentlichen Seite. */
export const RETURN_CONDITIONS = [
    { value: 'ok', label: 'In Ordnung' },
    { value: 'used', label: 'Benutzt' },
    { value: 'defective', label: 'Defekt' },
] as const;

/** Problem der Eingabe auf der öffentlichen Rückgabeseite oder null. */
export function returnProblem(code: string, name: string): string | null {
    if (name.trim() === '') {
        return 'Bitte geben Sie Ihren Namen ein.';
    }
    if (Array.from(name.trim()).length > 100) {
        return 'Der Name darf höchstens 100 Zeichen lang sein.';
    }
    return code.trim() === '' ? 'Bitte geben Sie die Nummer der Ausrüstung ein.' : null;
}
