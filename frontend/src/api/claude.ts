import { apiRequest, isRecord, type ApiResult } from './client';
import type { ClaudeAccess } from './users';

/**
 * Claude-Zugang aus Sicht der App (docs/PLAN-MCP.md, Schritt M4): die Seite „Claude verbinden“
 * (POST api/v1/oauth/authorize/check und …/authorize, AuthorizeHandler) und die eigenen
 * Verbindungen im Profil (GET/DELETE api/v1/auth/claude-connections, ClaudeConnectionsHandler).
 */

/** Wohin der Zugang geht (RedirectUriRules::target). */
export interface ClaudeTarget {
    readonly host: string;
    /** Claude Code auf dem eigenen Rechner (Loopback) */
    readonly local: boolean;
}

/** Zugang über Claude: nur Lesen oder Ändern (ein Code bzw. eine Verbindung hat nie „aus“). */
export type GrantedAccess = Exclude<ClaudeAccess, 'off'>;

const GRANTED: readonly string[] = ['read', 'write'];

function isGranted(value: unknown): value is GrantedAccess {
    return typeof value === 'string' && GRANTED.includes(value);
}

function parseTarget(json: unknown): ClaudeTarget | null {
    if (!isRecord(json) || typeof json['host'] !== 'string' || typeof json['local'] !== 'boolean') {
        return null;
    }
    return { host: json['host'], local: json['local'] };
}

/** Ziel in Worten, z. B. „claude.ai“ bzw. „Claude Code auf diesem Rechner (localhost)“. */
export function targetText(target: ClaudeTarget): string {
    return target.local ? `Claude Code auf diesem Rechner (${target.host})` : target.host;
}

/** Was der Zugang für die Rolle heißt (Seite „Claude verbinden“). */
export const ACCESS_EXPLANATIONS: Readonly<Record<GrantedAccess, string>> = {
    read: 'Claude darf ansehen, was Ihre Rolle ansehen darf, aber nichts ändern.',
    write: 'Claude darf ansehen und ändern, was Ihre Rolle darf – ohne Benutzer, Einstellungen und E-Mail-Benachrichtigungen.',
};

// ---- „Claude verbinden“ ---------------------------------------------------------------------

/** Parameter der Adresse, unverändert an den Server (nur einfache Werte, jeder Name einmal). */
export type AuthorizeParams = Readonly<Record<string, string>>;

export function authorizeParams(search: string): AuthorizeParams {
    const params: Record<string, string> = {};
    for (const [name, value] of new URLSearchParams(search)) {
        if (!(name in params)) {
            params[name] = value;
        }
    }
    return params;
}

/** Antwort von authorize/check. */
export interface AuthorizeSummary {
    readonly tenantName: string;
    readonly clientName: string | null;
    readonly target: ClaudeTarget;
    readonly requestedAccess: GrantedAccess;
    readonly requestedAccessLabel: string;
}

export function parseAuthorizeSummary(json: unknown): AuthorizeSummary | null {
    if (!isRecord(json) || !isRecord(json['tenant']) || !isRecord(json['client'])) {
        return null;
    }
    const tenantName = json['tenant']['name'];
    const clientName = json['client']['name'];
    const target = parseTarget(json['target']);
    const access = json['requested_access'];
    const label = json['requested_access_label'];
    if (
        typeof tenantName !== 'string' ||
        (clientName !== null && typeof clientName !== 'string') ||
        target === null ||
        !isGranted(access) ||
        typeof label !== 'string'
    ) {
        return null;
    }
    return { tenantName, clientName, target, requestedAccess: access, requestedAccessLabel: label };
}

export function checkAuthorization(
    params: AuthorizeParams,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AuthorizeSummary>> {
    return apiRequest('oauth/authorize/check', parseAuthorizeSummary, {
        method: 'POST',
        body: { params },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

export type AuthorizeDecision = 'preview' | 'allow' | 'deny';

/** Antwort von authorize: Zugang nach der Anmeldung (preview, allow) und Rücksprung (allow, deny). */
export interface AuthorizeResult {
    readonly access: GrantedAccess | null;
    readonly accessLabel: string | null;
    readonly displayName: string | null;
    readonly redirectUrl: string | null;
}

export function parseAuthorizeResult(json: unknown): AuthorizeResult | null {
    if (!isRecord(json)) {
        return null;
    }
    const access = json['access'] ?? null;
    const label = json['access_label'] ?? null;
    const redirectUrl = json['redirect_url'] ?? null;
    const user = json['user'];
    const displayName =
        isRecord(user) && typeof user['display_name'] === 'string' ? user['display_name'] : null;
    if (
        (access !== null && !isGranted(access)) ||
        (label !== null && typeof label !== 'string') ||
        (redirectUrl !== null && typeof redirectUrl !== 'string') ||
        (access === null && redirectUrl === null)
    ) {
        return null;
    }
    return { access, accessLabel: label, displayName, redirectUrl };
}

/**
 * Ablehnen geht ohne Anmeldung; Vorschau und Verbinden senden E-Mail und Passwort (unverändert)
 * jedes Mal neu (es entsteht nie eine Sitzung).
 */
export function authorizeClaude(
    params: AuthorizeParams,
    decision: AuthorizeDecision,
    credentials: { readonly email: string; readonly password: string } | null,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AuthorizeResult>> {
    return apiRequest('oauth/authorize', parseAuthorizeResult, {
        method: 'POST',
        body: { params, decision, ...(credentials ?? {}) },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

const CLAUDE_CALLBACKS: readonly string[] = [
    'https://claude.ai/api/mcp/auth_callback',
    'https://claude.com/api/mcp/auth_callback',
];

const LOOPBACK_CALLBACK = /^http:\/\/(localhost|127\.0\.0\.1)(:[1-9][0-9]{0,4})?\/callback$/;

/**
 * Zusätzlich zum Server (der nur zu exakt registrierten Adressen zurückspringt): Die App folgt nur
 * einem Rücksprung auf genau die `redirect_uri` der Anfrage, und nur, wenn diese eine der erlaubten
 * Adressen ist (RedirectUriRules). Sonst kein Rücksprung.
 */
export function safeRedirect(redirectUrl: string, params: AuthorizeParams): string | null {
    const redirectUri = params['redirect_uri'];
    if (
        redirectUri === undefined ||
        !(CLAUDE_CALLBACKS.includes(redirectUri) || LOOPBACK_CALLBACK.test(redirectUri))
    ) {
        return null;
    }
    return redirectUrl.startsWith(`${redirectUri}?`) ? redirectUrl : null;
}

// ---- Eigene Verbindungen ----------------------------------------------------------------------

/** Eine eigene Claude-Verbindung (Zeiten in UTC, `…Z`). */
export interface ClaudeConnection {
    readonly id: number;
    readonly clientName: string | null;
    readonly target: ClaudeTarget;
    readonly access: GrantedAccess;
    readonly accessLabel: string;
    readonly createdAt: string;
    readonly lastUsedAt: string;
}

const UTC_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

function parseConnection(json: unknown): ClaudeConnection | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, client_name: clientName, access, access_label: label } = json;
    const createdAt = json['created_at'];
    const lastUsedAt = json['last_used_at'];
    const target = parseTarget(json['target']);
    if (
        typeof id !== 'number' ||
        !Number.isInteger(id) ||
        (clientName !== null && typeof clientName !== 'string') ||
        target === null ||
        !isGranted(access) ||
        typeof label !== 'string' ||
        typeof createdAt !== 'string' ||
        !UTC_TIME.test(createdAt) ||
        typeof lastUsedAt !== 'string' ||
        !UTC_TIME.test(lastUsedAt)
    ) {
        return null;
    }
    return { id, clientName, target, access, accessLabel: label, createdAt, lastUsedAt };
}

export function parseClaudeConnections(json: unknown): readonly ClaudeConnection[] | null {
    if (!isRecord(json) || !Array.isArray(json['connections'])) {
        return null;
    }
    const connections = json['connections'].map(parseConnection);
    return connections.every((connection) => connection !== null) ? connections : null;
}

/** GET auth/claude-connections: eigene Verbindungen, zuletzt genutzte zuerst (nur unter Städten). */
export function fetchClaudeConnections(
    fetchFn?: typeof fetch,
): Promise<ApiResult<readonly ClaudeConnection[]>> {
    return apiRequest('auth/claude-connections', parseClaudeConnections, {
        ...(fetchFn && { fetchFn }),
    });
}

/** DELETE auth/claude-connections/{id}: eine eigene Verbindung trennen (schon getrennt: 404). */
export function endClaudeConnection(
    id: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly deleted: true }>> {
    return apiRequest(
        `auth/claude-connections/${String(id)}`,
        (json) => (isRecord(json) && json['deleted'] === true ? { deleted: true as const } : null),
        { method: 'DELETE', ...(fetchFn && { fetchFn }) },
    );
}

/** Bezeichnung in „Angemeldete Geräte“, z. B. „Claude (claude.ai)“ bzw. „Claude Code (localhost)“. */
export function connectionLabel(connection: ClaudeConnection): string {
    return connection.target.local
        ? `Claude Code (${connection.target.host})`
        : `Claude (${connection.target.host})`;
}
