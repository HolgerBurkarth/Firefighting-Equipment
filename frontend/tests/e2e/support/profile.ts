import type { MockResponse } from '../fixtures';

/** Sitzung wie AuthJson::session; Nummer 3 ist die aktuelle aus meBody(). */
export function sessionJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id,
        device_label: 'Chrome auf Windows',
        created_at: '2026-10-29T07:05:00Z',
        last_seen_at: '2026-10-29T13:25:00Z',
        expires_at: '2027-01-27T07:05:00Z',
        current: id === 3,
        ...overrides,
    };
}

/** GET api/v1/auth/sessions mit nur dieser Sitzung (für Tests, die das Profil nur öffnen). */
export const CURRENT_SESSION_ONLY: Readonly<Record<string, MockResponse>> = {
    'GET api/v1/auth/sessions': { status: 200, body: { sessions: [sessionJson(3)] } },
};
