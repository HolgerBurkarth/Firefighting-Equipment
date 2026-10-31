import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import { fetchNoticePage, type Notice, type NoticeFilter, type NoticePage } from '../api/notices';
import { Refresher, type RefreshReason } from './refresher';

/** Hinweise je Seite der Liste („Weitere laden“ holt die nächsten). */
export const NOTICE_PAGE_SIZE = 50;
/** Höchstens so viele beim Auffrischen (limit des Servers). */
const MAX_REFRESH = 200;

export interface NoticeListState {
    /** Filter der angezeigten Liste; null, solange noch keiner gesetzt ist. */
    readonly filter: NoticeFilter | null;
    /** Neueste zuerst wie vom Server. */
    readonly notices: readonly Notice[];
    /** Anzahl für den Filter; null, solange für ihn noch nichts geladen ist. */
    readonly total: number | null;
    /** Offene Hinweise insgesamt (Reiter, Filter „Offen“). */
    readonly open: number | null;
    readonly loading: boolean;
    readonly loadingMore: boolean;
    /** Fehler der letzten Abfrage; die bisherige Liste bleibt dann stehen. */
    readonly error: ApiError | null;
}

const EMPTY: NoticeListState = {
    filter: null,
    notices: [],
    total: null,
    open: null,
    loading: false,
    loadingMore: false,
    error: null,
};

type FetchPage = (query: {
    readonly status: NoticeFilter;
    readonly limit: number;
    readonly offset: number;
}) => Promise<ApiResult<NoticePage>>;

/**
 * Hinweise des Protokolls (GET notices, Admin) seitenweise wie die Geräteliste der Konsole: neuer
 * Filter → erste Seite neu (Antworten zu einem älteren Filter werden verworfen), gleicher Filter →
 * auffrischen wie beim Öffnen einer Seite.
 */
export class NoticeList {
    private readonly current = shallowRef<NoticeListState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly fetchPage: FetchPage = (query) => fetchNoticePage(query),
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): NoticeListState {
        return this.current.value;
    }

    show(filter: NoticeFilter): Promise<void> {
        if (this.current.value.filter === filter) {
            return this.refresher.refresh('open');
        }
        this.generation += 1;
        this.current.value = { ...EMPTY, filter };
        return this.refresher.refresh('changed');
    }

    refresh(reason: RefreshReason): Promise<void> {
        return this.current.value.filter === null
            ? Promise.resolve()
            : this.refresher.refresh(reason);
    }

    /** Hinweis aus der Liste (z. B. sofort beim Öffnen des Details); sonst null. */
    find(id: number): Notice | null {
        return this.current.value.notices.find((notice) => notice.id === id) ?? null;
    }

    /** Nächste Seite anhängen (doppelte IDs entfernt, falls sich die Liste inzwischen verschob). */
    async more(): Promise<void> {
        const state = this.current.value;
        if (state.filter === null || state.loading || state.loadingMore) {
            return;
        }
        const generation = this.generation;
        this.current.value = { ...state, loadingMore: true };
        const result = await this.fetchPage({
            status: state.filter,
            limit: NOTICE_PAGE_SIZE,
            offset: state.notices.length,
        });
        if (generation !== this.generation) {
            return;
        }
        const now = this.current.value;
        if (!result.ok) {
            this.current.value = { ...now, loadingMore: false, error: result.error };
            return;
        }
        const known = new Set(now.notices.map((notice) => notice.id));
        this.current.value = {
            ...now,
            notices: [...now.notices, ...result.data.notices.filter((n) => !known.has(n.id))],
            total: result.data.total,
            open: result.data.open,
            loadingMore: false,
            error: null,
        };
    }

    clear(): void {
        this.generation += 1;
        this.refresher.reset();
        this.current.value = EMPTY;
    }

    /** Erste Seite neu laden; beim Auffrischen so viele wie schon geladen (höchstens 200). */
    private async load(): Promise<boolean> {
        const generation = this.generation;
        const state = this.current.value;
        if (state.filter === null) {
            return false;
        }
        this.current.value = { ...state, loading: true };
        const result = await this.fetchPage({
            status: state.filter,
            limit: Math.min(MAX_REFRESH, Math.max(NOTICE_PAGE_SIZE, state.notices.length)),
            offset: 0,
        });
        if (generation !== this.generation) {
            return false;
        }
        this.current.value = result.ok
            ? {
                  ...this.current.value,
                  notices: result.data.notices,
                  total: result.data.total,
                  open: result.data.open,
                  loading: false,
                  loadingMore: false,
                  error: null,
              }
            : { ...this.current.value, loading: false, error: result.error };
        return result.ok;
    }
}
