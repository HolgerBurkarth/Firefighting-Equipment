import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import { fetchInvitationCodes, type InvitationCode } from '../api/invitationCodes';
import { Refresher, type RefreshReason } from './refresher';

export interface InvitationCodeListState {
    /** Codes der Stadt, neueste zuerst; null, solange noch nie geladen. */
    readonly codes: readonly InvitationCode[] | null;
    readonly loading: boolean;
    /** Fehler der letzten Auffrischung; die bisherige Liste bleibt dann stehen. */
    readonly error: ApiError | null;
}

const EMPTY: InvitationCodeListState = { codes: null, loading: false, error: null };

/** Einladungscodes der Stadt (GET invitation-codes) für die Benutzerverwaltung des Admins. */
export class InvitationCodeStore {
    private readonly current = shallowRef<InvitationCodeListState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly fetchList: () => Promise<ApiResult<readonly InvitationCode[]>> = () =>
            fetchInvitationCodes(),
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): InvitationCodeListState {
        return this.current.value;
    }

    refresh(reason: RefreshReason): Promise<void> {
        return this.refresher.refresh(reason);
    }

    clear(): void {
        this.generation += 1;
        this.refresher.reset();
        this.current.value = EMPTY;
    }

    private async load(): Promise<boolean> {
        const generation = this.generation;
        this.current.value = { ...this.current.value, loading: true };
        const result = await this.fetchList();
        if (generation !== this.generation) {
            return false;
        }
        this.current.value = result.ok
            ? { codes: result.data, loading: false, error: null }
            : { ...this.current.value, loading: false, error: result.error };
        return result.ok;
    }
}
