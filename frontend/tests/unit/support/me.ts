import type { UserRole } from '../../../src/api/auth';

/** Antwort von auth/me bzw. auth/login wie AuthJson::me in PHP. */
export function meBody(role: UserRole = 'admin'): Record<string, unknown> {
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
    };
}
