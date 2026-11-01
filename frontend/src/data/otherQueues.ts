import { shallowRef } from 'vue';
import { userLabel, type AuthState } from '../api/auth';
import type { LocalDb } from '../runtime/localDb';
import type { QueuedBooking } from './bookingQueue';

/**
 * Warteschlangen anderer Konten auf diesem Gerät (KONZEPT.md, "Offline-Modus – Entscheidungen",
 * 55): Ein Kontowechsel löscht die Warteschlange des bisherigen Kontos nicht (sie liegt je Konto in
 * IndexedDB); sie geht raus, sobald sich dieses Konto wieder anmeldet. Das angemeldete Konto sieht
 * einen Hinweis und kann sie nach Rückfrage verwerfen. Den Namen des Besitzers merkt sich die App je
 * Stadt in localStorage (`ff-queue-owners:<kürzel>`, Konto-ID → Name).
 */
export interface OtherQueue {
    readonly accountId: number;
    readonly name: string;
    /** Noch nicht beim Server angekommene Buchungen. */
    readonly count: number;
}

export function queueOwnersKey(area: string): string {
    return `ff-queue-owners:${area}`;
}

/** „Auf diesem Gerät liegen 3 nicht gesendete Buchungen von Anna. …“ */
export function otherQueueText(queue: OtherQueue): string {
    return queue.count === 1
        ? `Auf diesem Gerät liegt 1 nicht gesendete Buchung von ${queue.name}. Sie wird gesendet, sobald ${queue.name} sich wieder anmeldet.`
        : `Auf diesem Gerät liegen ${String(queue.count)} nicht gesendete Buchungen von ${queue.name}. Sie werden gesendet, sobald ${queue.name} sich wieder anmeldet.`;
}

export interface OtherQueuesOptions {
    /** Bereich (Kürzel der Stadt); als Funktion erst bei Bedarf gelesen. */
    readonly area: string | (() => string);
    readonly db: LocalDb;
    readonly storage?: Pick<Storage, 'getItem' | 'setItem'> | null;
}

export class OtherQueues {
    private readonly current = shallowRef<readonly OtherQueue[]>([]);
    private generation = 0;
    private currentId: number | null = null;

    constructor(private readonly options: OtherQueuesOptions) {}

    private get area(): string {
        const { area } = this.options;
        return typeof area === 'string' ? area : area();
    }

    get list(): readonly OtherQueue[] {
        return this.current.value;
    }

    private owners(): Record<string, string> {
        try {
            const stored = JSON.parse(
                this.options.storage?.getItem(queueOwnersKey(this.area)) ?? 'null',
            ) as unknown;
            if (typeof stored !== 'object' || stored === null || Array.isArray(stored)) {
                return {};
            }
            return Object.fromEntries(
                Object.entries(stored).filter(
                    (entry): entry is [string, string] =>
                        /^[1-9]\d*$/.test(entry[0]) && typeof entry[1] === 'string',
                ),
            );
        } catch {
            return {};
        }
    }

    private write(owners: Record<string, string>): void {
        try {
            this.options.storage?.setItem(queueOwnersKey(this.area), JSON.stringify(owners));
        } catch {
            // Ohne Speicher kein Name: der Hinweis fehlt dann, die Warteschlange bleibt.
        }
    }

    /** Konto als Besitzer seiner Warteschlange merken (Name für den Hinweis an andere Konten). */
    remember(account: AuthState): void {
        const owners = this.owners();
        const id = String(account.user.id);
        const name = userLabel(account.user);
        if (owners[id] !== name) {
            this.write({ ...owners, [id]: name });
        }
    }

    /** Abmelden: das Konto hat keine Warteschlange mehr. */
    forget(accountId: number): void {
        const owners = this.owners();
        const id = String(accountId);
        if (id in owners) {
            this.write(Object.fromEntries(Object.entries(owners).filter(([key]) => key !== id)));
        }
    }

    /** Warteschlangen der anderen gemerkten Konten neu lesen (null = niemand angemeldet: keine). */
    async refresh(currentId: number | null): Promise<void> {
        this.generation += 1;
        const generation = this.generation;
        this.currentId = currentId;
        if (currentId === null) {
            this.current.value = [];
            return;
        }
        const found: OtherQueue[] = [];
        for (const [id, name] of Object.entries(this.owners())) {
            if (Number(id) === currentId) {
                continue;
            }
            const stored = await this.options.db.get('queue', `${this.area}:${id}`);
            const count = Array.isArray(stored)
                ? (stored as QueuedBooking[]).filter((booking) => booking.state !== 'done').length
                : 0;
            if (count > 0) {
                found.push({ accountId: Number(id), name, count });
            }
        }
        if (generation === this.generation) {
            this.current.value = found;
        }
    }

    /** Nach Rückfrage: Warteschlange des anderen Kontos löschen (wird nie gesendet). */
    async discard(accountId: number): Promise<void> {
        await this.options.db.delete('queue', `${this.area}:${String(accountId)}`);
        this.forget(accountId);
        await this.refresh(this.currentId);
    }
}
