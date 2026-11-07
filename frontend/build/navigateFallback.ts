/**
 * Adressen, die der Service Worker nie mit index.html beantwortet (navigateFallbackDenylist von
 * Workbox; geprüft wird Pfad samt Query-String): Sie kommen immer vom Server.
 *
 * - `api/` (REST-API) und `manifest.webmanifest` (liefert PHP je Stadt).
 * - Claude-Zugang (docs/PLAN-MCP.md): `mcp`, `oauth/…`, `.well-known/…` und `claude-verbinden`.
 *   Claude öffnet `claude-verbinden` als neues Fenster; mit installiertem Service Worker zeigte es
 *   sonst die App aus dem Cache („Seite nicht gefunden“) statt der Antwort des Servers (Live-Test
 *   07.11.2026).
 *
 * Pfade unter dem Scope `/<präfix>/<bereich>/`, verglichen nur mit dem ersten Segment danach.
 */
export const NAVIGATE_FALLBACK_DENYLIST: readonly RegExp[] = [
    /^\/[^/?]+\/[^/?]+\/api\//,
    /^\/[^/?]+\/[^/?]+\/manifest\.webmanifest(?:\?|$)/,
    /^\/[^/?]+\/[^/?]+\/(?:mcp|claude-verbinden|oauth|\.well-known)(?:[/?]|$)/,
];
