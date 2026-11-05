import type { UserRole } from '../../../src/api/auth';

/** `permissions` wie AuthJson::permissions in PHP (Bereich → Stufe, Aktionen). */
export function permissionsJson(
    areas: Readonly<Record<string, 'read' | 'write'>>,
    actions: readonly string[] = [],
    filtered = false,
): Record<string, unknown> {
    return { areas, actions, restrictions: [], filtered, version: `v-${JSON.stringify(areas)}` };
}

/**
 * Antwort von auth/me bzw. auth/login wie AuthJson::me in PHP. Ohne `permissions` wie eine Antwort
 * vor Schritt 1.3b (die App nimmt dann die Rechte der Kontoart).
 */
export function meBody(
    role: UserRole = 'admin',
    permissions?: Record<string, unknown>,
): Record<string, unknown> {
    return {
        user: {
            id: 7,
            email: 'anna@example.org',
            name: 'Anna Beispiel',
            display_name: 'Anna',
            role,
            protected: false,
            notifications: { device_log: false },
        },
        tenant: role === 'superadmin' ? null : { slug: 'test', name: 'Feuerwehr Test' },
        session: { id: 3, device_label: 'Firefox auf Windows', current: true },
        ...(permissions !== undefined && { permissions }),
    };
}
