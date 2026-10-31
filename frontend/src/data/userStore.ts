import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import { fetchUsers, type AdminUser } from '../api/users';
import { Refresher, type RefreshReason } from './refresher';

export interface UserListState {
    /** Konten der Stadt ohne gelöschte, nach ID; null, solange noch nie geladen. */
    readonly users: readonly AdminUser[] | null;
    readonly loading: boolean;
    /** Fehler der letzten Auffrischung; die bisherige Liste bleibt dann stehen. */
    readonly error: ApiError | null;
}

const EMPTY: UserListState = { users: null, loading: false, error: null };

/** Konten der Stadt (GET users, ohne gelöschte) für die Benutzerverwaltung des Admins. */
export class UserStore {
    private readonly current = shallowRef<UserListState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly fetchList: () => Promise<ApiResult<readonly AdminUser[]>> = () =>
            fetchUsers(),
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): UserListState {
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
            ? { users: result.data, loading: false, error: null }
            : { ...this.current.value, loading: false, error: result.error };
        return result.ok;
    }
}
