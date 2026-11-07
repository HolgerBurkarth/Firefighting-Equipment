# Plan: Zugang über Claude (MCP-Konnektor)

Stand: 07.11.2026 (Runde 3; freigegeben von Holger am 07.11.2026 samt O2–O14 und der Aufteilung
in Runden; O1 gelöst: Claude findet die Metadaten, siehe Abschnitt 1).

Stand des Baus: M1 und M2 live (Runde 4), M3 und M4 live in Runde 5 (Abschnitte 9a, 9b), M5 in
Runde 6 (Abschnitt 9c).

Abweichungen beim Bau: Die Spalte der Registrierung heißt in `fe_oauth_codes` und
`fe_claude_connections` `oauth_client_id` (nicht `client_ref`, das in `CLAUDE.md` schon die Kennung
einer Buchung ist). Migration `0047` kommt mit ihrer Nutzung in M3 (statt M1). Bis T17 gibt es den
Zugang nur in der Test-Stadt (`ClaudeAvailability`); die Routen zum Setzen und Trennen lehnen in
anderen Städten mit 409 ab. Anforderungen: [`MCP-KONZEPT.md`](MCP-KONZEPT.md). Grundlage: Änderungskonzept E13, Plan
Phase 1 (Abschnitt 1, F1, F3, F5, F12), `CLAUDE.md`.

Mit ⚠ markiert: Schritte, die vor dem Bau eine Entscheidung brauchen oder bestehende Regeln aus
`CLAUDE.md` ausdrücklich ändern.

## Kurzfassung

- Die App wird **ihr eigener OAuth-2.1-Server** (Authorization Code + PKCE S256, Dynamic Client
  Registration) und **MCP-Server** (Streamable HTTP, nur JSON) je Stadt unter
  `/firefighting/<stadt>/`. Keine Bibliothek: JSON-RPC, OAuth und Tokens schlank selbst gebaut
  (Begründung Abschnitt 2).
- Eine **Claude-Verbindung** ist eine eigene Zeile (`fe_claude_connections`) mit rotierendem
  Refresh-Token und kurzlebigem Zugriffstoken, alles nur als SHA-256. Alle Wege, die heute die
  Sitzungen eines Kontos löschen, löschen auch dessen Verbindungen; jeder MCP-Aufruf prüft zusätzlich
  den aktuellen Stand.
- **Wirksame Rechte** = Rechte der Rolle ∩ Claude-Zugang, ohne `users`, `settings`, `mail.log`.
  Gebildet in `Application` an derselben Stelle wie für die App.
- **Werkzeuge sind Adapter auf die vorhandenen REST-Routen**: Ein Werkzeug baut eine interne Anfrage
  (`GET api/v1/devices?…`), der Router liefert Handler **und Erfordernis der Route**, geprüft wird mit
  denselben `Requirement`/`Permissions`, der Handler läuft unverändert. So gibt es keine zweite
  Geschäftslogik und keine zweite Rechte-Tabelle.
- Jede Änderung über Claude bekommt im Verlauf `channel = claude` („über Claude“), sichtbar in App,
  CSV und Log-Mails.
- Sieben Schritte M1–M7 (Abschnitt 9); Migrationen sind reine Erweiterungen.
- **Runde 3** hat geprüft, ob Claude die Metadaten unter `/firefighting/test/` findet (Abschnitt 1).

## 1. Vorklärung (Runde 3)

Frage: Findet Claude die OAuth-Metadaten, wenn der Issuer einen Pfad hat
(`https://www.prodad.de/firefighting/test`) und das Dokument nur unter
`<issuer>/.well-known/openid-configuration` liegt (die `.htaccess` des Webspace bleibt unberührt, also
gibt es nichts unter `https://www.prodad.de/.well-known/…`)?

Die MCP-Spezifikation 2025-11-25 schreibt für Issuer mit Pfad diese Reihenfolge vor:
1. `https://www.prodad.de/.well-known/oauth-authorization-server/firefighting/test` (Webspace, nicht
   unsere)
2. `https://www.prodad.de/.well-known/openid-configuration/firefighting/test` (Webspace, nicht unsere)
3. `https://www.prodad.de/firefighting/test/.well-known/openid-configuration` (**unsere**)

Ältere Clients (Spezifikation 2025-06-18) kennen nur RFC 8414; manche hängen dann
`/.well-known/oauth-authorization-server` an den Issuer an. Darum liefert die Vorklärung dasselbe
Dokument zusätzlich unter `<issuer>/.well-known/oauth-authorization-server` und protokolliert, welche
Adresse Claude abruft.

**Gebaut** (Commit „MCP-Vorklärung: OAuth-Metadaten und Platzhalter unter /firefighting/test/
(Runde 3)“; nur Test-Stadt, fest im Code, sonst 404; `McpProbeHandler`, `McpProbeTrace`):

| Adresse (unter `/firefighting/test/`) | Antwort |
| --- | --- |
| `POST mcp` | 401 `not_authenticated` mit `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource", scope="read write"` |
| `GET mcp` | 405 (`Allow: POST`) |
| `GET .well-known/oauth-protected-resource` | RFC 9728: `resource` = MCP-Adresse, ein Autorisierungsserver |
| `GET .well-known/openid-configuration` | Metadaten (Endpunkte, S256, Scopes `read`/`write`, Pflichtfelder der OpenID Discovery) |
| `GET .well-known/oauth-authorization-server` | dasselbe (nur zum Vergleich) |
| `GET .well-known/jwks.json` | `{"keys":[]}` (es gibt keine ID-Tokens) |
| `POST oauth/register` | 201 mit einer Kennung `probe-…`; nichts gespeichert |
| `GET claude-verbinden` | Platzhalter der Anmeldeseite: JSON mit „Claude hat die Anmeldeseite erreicht“ und den Prüfungen (PKCE S256, `resource`, Rücksprung erlaubt) |
| `GET api/v1/mcp-probe` | Protokoll der letzten 50 Abrufe (nur Admin der Test-Stadt) |

Dazu: Unbekannte Adressen unter `.well-known/` liefern 404 statt der App-Seite (`FrontendHandler`).

**Ergebnis**: siehe [`frontend/LIVETEST-MCP.md`](frontend/LIVETEST-MCP.md), Abschnitt „Vorklärung“.
Findet Claude die Variante nicht: kein Umweg über die Webroot, sondern „❓ RÜCKFRAGE“ (Optionen in
Abschnitt 10, O1). In Runde 4 (Schritt M3) wird die Vorklärung entfernt bzw. durch die echten
Endpunkte ersetzt; `api/v1/mcp-probe` und `var/mcp-probe/` entfallen.

## 2. Grundsatzentscheidungen (Vorschläge)

1. **MCP ohne SDK, schlank selbst gebaut.** Das PHP-SDK (`mcp/sdk`) bringt Symfony-Komponenten,
   PSR-7/17/18 und einen Sitzungsspeicher mit und setzt ein eigenes HTTP-Modell voraus; wir haben eine
   eigene `Request`/`Response`-Schicht, `RequestGuard` und Cache-Regeln. Gebraucht werden nur
   `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call` – zustandslos, ohne
   `Mcp-Session-Id`, ohne SSE. Das sind rund 300 Zeilen, vollständig getestet, ohne neue Abhängigkeit
   auf STRATO (wie beim SMTP-Client). Unterstützte Versionen: `2025-11-25`, `2025-06-18`, `2025-03-26`
   (Antwort mit der angefragten, sonst der neuesten; unbekannte im Header `MCP-Protocol-Version` →
   400).
2. **OAuth selbst** (`src/OAuth/`): Codes, Tokens und Registrierungen sind kleine Tabellen; alle
   Bausteine gibt es schon (Tokens wie `SessionToken`, Bremse wie `LoginThrottle`, Anmeldung über
   `Authenticator`, Uhr über `Clock`). Keine Bibliothek (league/oauth2-server bräuchte Schlüssel, JWT
   und eigene Repositorys, wir wollen opake Tokens).
3. **Opake Tokens** mit Präfix (`ffa_` Zugriff, `ffr_` Refresh + 43 Zeichen base64url aus 32
   Zufallsbytes): Präfix hilft Scannern für Geheimnisse (z. B. GitHub), gespeichert nur SHA-256.
   Keine JWTs (kein Widerruf ohne Liste, Schlüsselverwaltung ohne sodium).
4. **Verbindung als eigene Tabelle** statt `fe_sessions.channel`: Sitzungen der App werden per Cookie
   gefunden; stünde das Refresh-Token in `fe_sessions`, müsste jede Abfrage der Sitzungen nach Kanal
   filtern (sonst ließe sich ein Refresh-Token als Cookie verwenden). Stattdessen löschen die
   zentralen Stellen, die heute Sitzungen löschen (`UserRepository` Zeile „DELETE FROM fe_sessions
   WHERE user_id = ?“, `TenantRepository::setActive`), in derselben Transaktion auch die Verbindungen;
   je Weg ein Test. Abweichung vom Plan Phase 1, Abschnitt 1 (dort `fe_sessions.channel`): Der Kanal
   steht stattdessen im **Verlauf** (`fe_device_events.channel`), wie im Auftrag als Alternative
   genannt.
5. **Werkzeug = Adapter auf die REST-Route** (Abschnitt 6): gleiche Erfordernisse, Datenfilter,
   Feldregeln, Meldungen, Versionsprüfung und Log-Mails, ohne Kopie.

## 3. Datenmodell (Migrationen, nur Erweiterungen)

| Nr. | Inhalt |
| --- | --- |
| 0041 | `fe_users.claude_access` `VARCHAR(5) ascii_bin NOT NULL DEFAULT 'off'`, CHECK `chk_fe_users_claude_access`: Wert in `off`/`read`/`write`; `off` bei `role = 'superadmin'` und bei `status = 'deleted'`. Bestehende Konten bekommen den Standard `off` (keine Datenänderung im Sinne der Regel). |
| 0042 | `fe_oauth_clients`: `id`, `tenant_id`, `client_id` (`VARCHAR(43) ascii_bin`, zufällig, öffentlich, UNIQUE), `client_name` (bis 100, Steuerzeichen nein), `redirect_uris` (JSON, `JSON_VALID`, 1–5 Einträge), `created_at`, `last_used_at` (NULL = nie genutzt). |
| 0043 | `fe_oauth_codes`: `id`, `tenant_id`, `user_id`, `client_ref` → `fe_oauth_clients.id`, `code_hash` (32 Byte, UNIQUE), `redirect_uri`, `code_challenge` (`CHAR(43) ascii_bin`), `access` (`read`/`write`), `created_at`, `expires_at` (CHECK ≤ 10 Minuten). |
| 0044 | `fe_claude_connections`: `id`, `tenant_id`, `user_id` (Fremdschlüssel `(user_id, tenant_id)` → `fe_users`, kaskadiert nicht), `client_ref`, `access` (bei der Zustimmung gewährt; wirksam ist das Minimum mit dem aktuellen Zugang), `account_kind` (`admin`/`user` beim Verbinden), `refresh_hash` (UNIQUE), `previous_refresh_hash`, `rotated_at`, `access_hash` (UNIQUE, NULL), `access_expires_at`, `created_at`, `last_seen_at`, `expires_at` (CHECK ≤ 90 Tage nach `created_at`, wie `fe_sessions`). |
| 0045 | `fe_device_events.channel` `VARCHAR(6) ascii_bin NOT NULL DEFAULT 'app'`, CHECK `app`/`claude`. |
| 0046 | `fe_device_notice_actions.channel` ebenso (Hinweise erledigen/wieder öffnen). |
| 0047 | `fe_auth_attempts`: CHECK `chk_fe_auth_attempts_action` um `oauth_register` erweitern (DROP und ADD derselben Bedingung in EINER Anweisung, wie 0008–0010). |

Löschen von Registrierungen, Codes und Verbindungen ist ein echtes `DELETE` (keine Historie, wie das
Logo und die Sitzungen); `purge-device-data` lässt alles davon unberührt.

## 4. Adressen und Routen

Unter `/firefighting/<stadt>/` (unter `service` gibt es keine; 404):

| Methode und Pfad | Zugriff | Zweck |
| --- | --- | --- |
| `POST mcp` | `Access::Claude` (neu, Bearer) | MCP (JSON-RPC), Body bis 3 MiB (CSV im Import-Werkzeug) |
| `GET mcp` | – | 405 (Router) |
| `GET .well-known/oauth-protected-resource` | öffentlich | RFC 9728 |
| `GET .well-known/openid-configuration` | öffentlich | Metadaten des Autorisierungsservers |
| `GET .well-known/oauth-authorization-server` | öffentlich | nur, wenn die Vorklärung zeigt, dass Claude sie braucht (O1) |
| `GET .well-known/jwks.json` | öffentlich | `{"keys":[]}` (Pflichtfeld der OpenID Discovery) |
| `POST oauth/register` | öffentlich, JSON | RFC 7591 |
| `POST oauth/token` | öffentlich, **Formular** | Code einlösen, Refresh |
| `POST oauth/revoke` | öffentlich, **Formular** | RFC 7009 (Claude trennt die Verbindung) |
| `GET claude-verbinden` | Frontend | Anmelde- und Zustimmungsseite (Vue) |
| `POST api/v1/oauth/authorize/check` | öffentlich, JSON | Parameter prüfen, Name des Clients und Ziel für die Seite |
| `POST api/v1/oauth/authorize` | öffentlich, JSON | E-Mail, Passwort, Zustimmung → `redirect_url` mit Code |
| `POST api/v1/users/{id}/claude-access {access}` | `users` Ändern **und** Rolle *Admin* (F12) | Zugang setzen (nur Konten ohne *Admin*) |
| `POST api/v1/users/{id}/claude-connections/end {}` | ebenso | alle Verbindungen trennen |

Unter `service` (Super-Admin, `SuperAdminRoutesAccessTest`): `POST
api/v1/tenants/{id}/users/{user}/claude-access` (nur Konten mit *Admin*) und `POST
api/v1/tenants/{id}/users/{user}/claude-connections/end` (alle Konten der Stadt). `UserJson` bekommt
`claude_access` und `claude_connections {count, last_used_at}` (optionale Felder, `X-Client-Api`
bleibt 2).

### Ausnahmen von Regeln in `CLAUDE.md` (⚠, je begründet)

1. **Formular statt JSON** nur für `oauth/token` und `oauth/revoke` (OAuth 2.1, Abschnitt 3.2: der
   Token-Endpunkt nimmt `application/x-www-form-urlencoded`). Umsetzung: Router-Option
   `formEncoded` (wie `maxBodyBytes`), `RequestGuard` liest dann genau diesen Content-Type (nur
   einfache Werte, 16 KiB) statt JSON. Unbedenklich, weil diese Endpunkte **kein Cookie** lesen
   (CSRF braucht ein Cookie) und nur mit Code + Verifier bzw. Refresh-Token wirken. Die
   `Origin`-Prüfung bleibt: ein fremder `Origin` → 403. `multipart/form-data` bleibt verboten.
2. **OAuth-Fehlerformat** `{"error":"invalid_grant","error_description":"…"}` an `oauth/token`,
   `oauth/revoke` und `oauth/register` (RFC 6749/7591; Claude wertet `error` aus, z. B. 401
   `invalid_client` = neu registrieren). Eigene Klasse `OAuthErrorResponse` mit denselben Standard-
   Headern wie `JsonResponse` (`no-store`, `nosniff`). Die Codes stehen dort, nicht in `HttpException`.
   Alle übrigen neuen Routen (`api/v1/oauth/…`, `mcp`) bleiben im Fehlerformat der App; an `mcp`
   zusätzlich `WWW-Authenticate` bei 401 (`error="invalid_token"`, wenn ein Token gesendet wurde).
3. **Routen außerhalb von `api/v1`**: `mcp`, `.well-known/…`, `oauth/…` (Adressen geben Spezifikation
   und Metadaten vor; bisher war `manifest.webmanifest` die einzige). Alle `no-store`; keine Ausnahme
   bei `CacheableResponse`.
4. **Neuer Fehlercode** `claude_access_missing` (403, „Kein Claude-Zugang – bitte wenden Sie sich an
   Ihren Admin.“) für `api/v1/oauth/authorize`.
5. **CSP**: keine Änderung. Die Seite `claude-verbinden` springt per `location.assign` zum geprüften
   Ziel; `form-action 'self'` bleibt (kein Formular-Submit an fremde Adressen).

## 5. OAuth im Einzelnen (`src/OAuth/`)

- **Metadaten** (`AuthorizationServerMetadata`, `ProtectedResourceMetadata`): wie in der Vorklärung,
  dazu `revocation_endpoint`, `authorization_response_iss_parameter_supported: true` (RFC 9207,
  Rücksprung mit `iss`) und `service_documentation` (README-Abschnitt). Adressen über `AppUrls`.
- **Registrierung** (`ClientRegistration`, `OAuthClientRepository`): `redirect_uris` Pflicht, 1–5,
  jede erlaubt (`RedirectUriRules`: genau `https://claude.ai/api/mcp/auth_callback`,
  `https://claude.com/api/mcp/auth_callback`, `http://localhost[:port]/callback`,
  `http://127.0.0.1[:port]/callback`; Port 1–65535 ohne führende Null; kein Query, kein Fragment),
  sonst 400 `invalid_redirect_uri`. Nur `token_endpoint_auth_method` `none`, Grants
  `authorization_code`/`refresh_token`, `response_types` `code`; andere → 400
  `invalid_client_metadata`. Bremse: `oauth_register` 20 je IP-Präfix und Stunde. Aufräumen bei jeder
  Registrierung (Stadt): nie genutzte älter als 24 Stunden, genutzte ohne Verbindung seit 90 Tagen.
  Höchstens 200 nie genutzte je Stadt (sonst 429).
- **Prüfen der Anfrage** (`AuthorizationRequest::fromQuery`, für `authorize/check` und
  `authorize`): `response_type=code`, bekannter `client_id` der Stadt, `redirect_uri` exakt eine der
  registrierten (Loopback: Port darf abweichen, RFC 8252), `code_challenge` 43 Zeichen base64url,
  `code_challenge_method=S256` (fehlt oder `plain` → Fehler), `resource` = MCP-Adresse der Stadt
  (fehlt: gilt als diese), `scope` aus `read`/`write` (fehlt: `read write`), `state` beliebig (bis 500
  Zeichen). Falscher Client oder Rücksprung → Fehlerseite ohne Rücksprung; andere Fehler → Rücksprung
  mit `error=invalid_request` bzw. `invalid_scope` und `state`.
- **Zustimmung** (`POST api/v1/oauth/authorize {request, email, password, decision}`): Bremse und
  Anmeldung wie `auth/login` (`LoginThrottle`, `Authenticator`, gleiche Meldungen, gleiche Laufzeit
  für unbekannte E-Mail), aber **ohne Sitzung und ohne Cookie**. Danach: Konto der Stadt, aktiv, nicht
  gesperrt; `claude_access` = `off` → 403 `claude_access_missing`. Gewährt = Minimum aus Zugang und
  `scope`. `decision = deny` → `redirect_url` mit `error=access_denied`. Sonst Code (32 Zufallsbytes,
  5 Minuten, einmal) und `redirect_url` = Rücksprung mit `code`, `state`, `iss`.
- **Token** (`POST oauth/token`): `grant_type=authorization_code` mit `code`, `redirect_uri` (gleich
  wie beim Code), `client_id`, `code_verifier` (43–128 Zeichen, SHA-256 base64url = Challenge) und
  `resource`; Code einmal: ein zweites Einlösen → `invalid_grant` und die daraus entstandene
  Verbindung wird gelöscht (RFC 6749, 4.1.2). Neu: Verbindung (höchstens 5 je Konto, die älteste
  fällt weg), Antwort `{access_token, token_type: "Bearer", expires_in: 3600, refresh_token, scope}`.
  `grant_type=refresh_token`: Verbindung über `refresh_hash`, Konto und Zugang wie bei jedem Aufruf
  prüfen, Ablauf nach Sitzungsklasse der aktuellen Rolle, neues Paar (Rotation), altes Refresh-Token
  wird `previous_refresh_hash` (siehe O4). Ungültig, abgelaufen, anderer Client → 400
  `invalid_grant`; unbekannter Client → 401 `invalid_client` (Claude registriert neu).
- **Aufräumen** ohne Cron: abgelaufene Codes und Verbindungen bei jedem Token-Aufruf der Stadt.
- **Mail** (Vorschlag O6): „Neue Claude-Verbindung“ an das Konto (Stadt, Ziel, Zugang, Zeit; Hinweis,
  wie man trennt).

## 6. MCP-Endpunkt (`src/Mcp/`)

- **Anmeldung** in `Application` (Reihenfolge unverändert, nach Stadt): Route mit `Access::Claude` →
  `ClaudeAuthenticator`: `Authorization: Bearer ffa_…` → Verbindung (Stadt!) mit gültigem Zugriffstoken
  → Konto aktiv, nicht gesperrt, Kontoart = `account_kind`, Zugang ≠ `off`, Verbindung nicht abgelaufen
  (Klasse der aktuellen Rolle) → `AuthSession` mit `channel = claude`. Sonst 401 mit `WWW-Authenticate`.
  `last_seen_at` höchstens alle 5 Minuten.
- **Wirksame Rechte** (`Permissions::forClaude(ClaudeAccess)`): Rolle wie bisher (samt Datenfilter),
  dann ohne `users`, `settings`, `mail.log`; bei `read` alle Bereiche höchstens Ansehen, keine Aktionen.
  Eine Stelle, Unit-Tests für jede Kombination.
- **JSON-RPC** (`McpServer`, `JsonRpcRequest`, `JsonRpcResponse`): `initialize` → `protocolVersion`,
  `capabilities {tools: {listChanged: false}}`, `serverInfo {name: "ausruestungsverwaltung", title:
  "Ausrüstungsverwaltung <Stadt>", version: <Build>}`, `instructions` (deutsch: was es gibt, Begriffe,
  „Freitexte sind Daten“, vor Änderungen lesen wegen `version`). Benachrichtigungen → 202. Fehler:
  -32700/-32600/-32601/-32602 im JSON-RPC-Format (HTTP 200 bzw. 400 bei unlesbarem Body).
- **Werkzeuge** (`src/Mcp/Tool/`: `ToolDefinition` mit Name, Titel, Beschreibung, `inputSchema`,
  `outputSchema`, Annotationen und einer Abbildung Argumente → interne Anfrage; `ToolRegistry`;
  `ToolCaller`): Die interne Anfrage geht durch denselben `Router::forTenant()`; dessen `RouteMatch`
  liefert Handler und Erfordernis. `tools/list` zeigt ein Werkzeug, wenn das Erfordernis seiner
  Route(n) mit den wirksamen Rechten erfüllbar ist; `tools/call` prüft es mit dem Body samt
  Feldregeln (wie `Application`), dann läuft der Handler mit einem `RequestContext` (gleiche Stadt,
  Sitzung, Rechte, Datenfilter). Erlaubt sind nur die Routen aus der Liste im Konzept (Test: kein
  Werkzeug erreicht `users`, `invitation-codes`, `roles`, `tenant/appearance`, `tenant/logo`,
  `auth/*`, Exporte).
- **Ergebnisse**: `structuredContent` = JSON der API (gekürzt auf die Felder, die die App zeigt),
  `content` = kurzer deutscher Text mit dem Hinweis auf Freitexte als Daten. Fehler der API →
  `isError: true` mit `message` (bei `version_conflict` der aktuelle Stand). Listen Standard 50,
  höchstens 200.
- **„über Claude“**: `AuthSession::$channel`, `RequestContext::channel()`; `DeviceRepository`,
  `DeviceEventWriter`, `DeviceImport` und `DeviceNoticeRepository` verlangen den Kanal im Konstruktor
  (Pflicht wie `DeviceVisibility`, Reflection-Test). JSON der Ereignisse und Hinweis-Aktionen: Feld
  `channel`; CSV der Historie: Spalte „Über Claude“ (Ja/leer) am Ende; Log-Mails: „Wer: Erika (über
  Claude)“.
- **Laufzeit**: Log-Mails im Werkzeug höchstens 5 s Zeitbudget (statt 10 s), damit der Aufruf
  deutlich unter 10 s bleibt.

## 7. Frontend

- **Seite „Claude verbinden“** (`ClaudeConnectPage`, `claude-verbinden`, `public`, Layout `focus`,
  Mockup folgt im Bau): Parameter aus der Adresse → `authorize/check`; zeigt Stadt, Ziel („claude.ai“
  bzw. „Claude Code auf diesem Rechner“), Zugang nach der Anmeldung; Formular E-Mail und Passwort
  (`PasswordField`, Countdown bei 429), dann „Verbinden“/„Abbrechen“, `claude_access_missing` als
  Hinweis ohne Weiter. Danach `location.assign(redirect_url)`. Unter `service` gibt es die Seite nicht.
- **Benutzerverwaltung** (`UsersPage`): Chip „Claude: Lesen/Ändern“, Zeile „verbunden · zuletzt …“; im
  Fenster „Bearbeiten“ Radiogruppe „Claude-Zugang“ (gesperrt bei Admins und am eigenen Konto, mit
  Hinweis), abseits „Claude-Verbindungen trennen …“ (`ConfirmDialog`). Nur, wenn die Sitzung die Rolle
  *Admin* hat. Über `src/data/index.ts` (`setClaudeAccess`, `endClaudeConnections`).
- **Super-Admin** (Stadtdetail, Reiter „Admins und Benutzer“): je Konto mit *Admin* „Claude-Zugang …“
  (`FormDialog`), je Konto „Claude-Verbindungen trennen …“.
- **Verlauf**: „· über Claude“ in `eventLines`/`DeviceHistoryList`, „Letzte Aktivitäten“, Hinweise.
- Tests: `claudeConnect.spec.ts`, `users.spec.ts`, `superAdmin.spec.ts`, `deviceDetails.spec.ts`,
  Unit `claudeAccess.spec.ts`, `eventDisplay`.

## 8. Tests (Backend)

- **Rechte-Matrix** (`McpPermissionMatrixTest`): Rollen *Admin*, *Benutzer*, Gerätewart-artig
  (Konsole ohne `users`), nur ansehen, mit Datenfilter (Standort, Typ) × Zugang `off`/`read`/`write` ×
  jedes Werkzeug: `tools/list` genau die erwarteten, `tools/call` gleich dem Verhalten der REST-Route
  mit den wirksamen Rechten (Datenfilter: Geräte außerhalb wie nicht vorhanden, „anderer Standort“).
- **Sofortiger Entzug**: Zugang aus, Konto sperren, löschen, Passwort ändern/zurücksetzen, Stadt
  sperren, Wechsel zu/von *Admin*, „trennen“ → nächster MCP-Aufruf 401 und Refresh `invalid_grant`;
  anderer Rollenwechsel und „ändern“ → „lesen“ wirken beim nächsten Aufruf. Je Weg ein Test, dass die
  Zeilen gelöscht sind.
- **OAuth**: PKCE (falscher Verifier, `plain`, fehlend), Code einmal (zweites Einlösen löscht die
  Verbindung), Ablaufzeiten (Code, Zugriffstoken, Verbindung nach Klasse, mit `FixedClock`),
  Refresh-Rotation und Wiederverwendung (O4), `redirect_uri`-Regeln (Tabelle wie in der Vorklärung),
  `resource` falsch, Stadt fremd, Bremsen, Aufräumen, Fehlerformat, Formular-Content-Type,
  Origin.
- **MCP**: Versionen, Benachrichtigung 202, GET 405, unbekannte Methode, kaputtes JSON, großer Body,
  Annotationen und Schemas jedes Werkzeugs (Snapshot), „Freitext als Daten“.
- **„über Claude“**: Ereignis, Hinweis-Aktion, CSV-Spalte, Log-Mail; App unverändert `app`.
- Bestehende Tabellen-Tests erweitern: `TenantRoutePermissionsTest` (neue Routen, `Access::Claude`),
  `SuperAdminRoutesAccessTest`, `AdminRoutesAccessTest`, `FrontendDeliveryTest`.

## 9. Schritte

| Schritt | Inhalt | Größe | Live/CI vor dem nächsten |
| --- | --- | --- | --- |
| M1 | Migrationen 0041–0044, 0047; `ClaudeAccess`, Regeln F12 in `UserAdministration`/`TenantAdministration`, Löschen der Verbindungen auf allen Wegen, Routen zum Setzen und Trennen, `UserJson`. Noch keine Verbindung möglich. | mittel | Deploy mit Migration, live (Zugang setzen per API in `test`) |
| M2 | Frontend: Claude-Zugang in Benutzerverwaltung und Stadtdetail | mittel | live in `test` |
| M3 | OAuth-Server (Metadaten, Registrierung, `authorize/check`, `authorize`, Token, Widerruf, Aufräumen, Bremse, Formular-Routen, OAuth-Fehlerformat); `mcp` mit `Access::Claude`, `initialize`, `ping`, leeres `tools/list`. Vorklärung entfernt. | groß | CI |
| M4 | Frontend: Seite „Claude verbinden“ | mittel | **Holger verbindet claude.ai und Claude Code** in `test` |
| M5 | Lesewerkzeuge, wirksame Rechte, Matrix-Test (Lesen) | groß | live mit Claude (Holger) |
| M6 | Schreibwerkzeuge, Migrationen 0045/0046, „über Claude“ in Verlauf, CSV, Mails, App | groß | live mit Claude (Holger) |
| M7 | Vollständiger Live-Test (`LIVETEST-MCP.md`), Doku, Entscheidung zu echten Städten erst nach Datenschutz (T17) | klein | – |

Vorschlag Runden: 4 = M1 + M2, 5 = M3 + M4, 6 = M5, 7 = M6 + M7.

**Risiken**

- Eine vergessene Stelle, die Sitzungen löscht, aber keine Verbindungen (Tests je Weg; zusätzlich
  Prüfung des aktuellen Stands bei jedem Aufruf).
- Rechteausweitung über ein Werkzeug (Adapter nur über den Router mit dessen Erfordernis; Test, dass
  jede Route eines Werkzeugs in der erlaubten Liste steht).
- Prompt Injection über Freitexte (strukturierte Antworten, Hinweis in `instructions` und jeder
  Antwort; ändernde Werkzeuge verlangen `version` und Bestätigung durch Claude/Nutzer).
- Claude ändert sein Verhalten (Callback-Adresse, Spezifikation, CIMD statt DCR): Metadaten und
  Regeln an einer Stelle, Live-Test je Runde.
- Antwortzeiten auf STRATO (Argon2id beim Verbinden, Log-Mails): Zeitbudget 5 s, Messung im Live-Test.

## 9a. Bau Runde 5 (M3, M4): Festlegungen

Auftrag Holger vom 07.11.2026 (Runde 5). Wo der Auftrag strenger ist als die Abschnitte oben, gilt
der Auftrag:

- **Rücksprung-Adressen exakt** (auch Loopback): Die `redirect_uri` einer Anfrage muss Byte für Byte
  einer registrierten gleichen, keine Präfixe und – anders als Abschnitt 5 – auch kein anderer Port
  bei Loopback. Nimmt Claude Code einen anderen Port als bei der Registrierung, registriert es sich
  neu (sonst Fehlerseite ohne Rücksprung, Live-Test).
- **Nie ein offener Redirect**: Zurückgesprungen wird nur zu einer exakt registrierten Adresse eines
  bekannten Clients der Stadt; falscher Client oder falsche Adresse → Fehler auf der Seite, kein
  Rücksprung (auch nicht mit Fehler). `state` geht unverändert zurück (bis 500 Zeichen, sonst
  Fehler ohne Rücksprung).
- **Code**: 32 Zufallsbytes mit Präfix `ffc_`, 5 Minuten (CHECK höchstens 10), nur einmal: das
  Einlösen löscht die Zeile in jedem Fall (auch bei falschem Verifier). Gebunden an Registrierung,
  `redirect_uri`, Konto, Zugang (Scope) und `code_challenge` (S256). Damit ein **zweites Einlösen**
  die daraus entstandene Verbindung findet, merkt sich die Verbindung den Hash ihres Codes
  (Migration `0046`, `fe_claude_connections.code_hash`, UNIQUE): unbekannter Code mit Verbindung →
  Verbindung gelöscht, `invalid_grant`.
- **Migrationen** (Nummern verschoben, lückenlos): `0045` Bremse `oauth_register` (statt 0047),
  `0046` `code_hash` an der Verbindung; „über Claude“ (Abschnitt 3: 0045/0046) bekommt in M6 die
  nächsten freien Nummern.
- **Refresh (O4)**: Das vorige Refresh-Token gilt nach einer Rotation noch 60 s einmal (neues Paar;
  das zuletzt ausgegebene Paar und das vorige gelten danach nicht mehr, `previous_refresh_hash` wird
  leer). Später oder ein zweites Mal → Verbindung gelöscht, `invalid_grant`.
- **„Claude verbinden“ in zwei Schritten** (O7): `POST api/v1/oauth/authorize {params, decision,
  email, password}` mit `decision` = `preview` (Anmeldung prüfen, gewährten Zugang zeigen, kein Code),
  `allow` (Anmeldung erneut prüfen, Code, `redirect_url`), `deny` (ohne Anmeldung, `redirect_url` mit
  `error=access_denied`, `state`, `iss`). Ohne Zugang 403 `claude_access_missing` und kein Rücksprung.
  `POST api/v1/oauth/authorize/check {params}`: prüft nur, liefert Stadt, Name des Clients, Hostname des
  Ziels und den angefragten Zugang; Fehler mit Rücksprung als 400 `invalid_request` mit `details
  {redirect_url}` (die Seite bietet „Zurück zu Claude“ an).
- **Profil (O5)**: `GET api/v1/auth/claude-connections` und `DELETE
  api/v1/auth/claude-connections/{id} {}` (eigene Verbindungen; `Access::Authenticated`, nur unter
  Städten). Die App zeigt sie in „Angemeldete Geräte“ (Profil und Einstellungen) mit „Trennen“.
- **Mail (O6)** „Neue Claude-Verbindung“ nach dem Einlösen des Codes an das Konto (Stadt, Ziel,
  Zugang, Zeit, Hinweis auf „Trennen“ unter „Angemeldete Geräte“; nie Token oder Code).
- **`mcp`**: `Access::Claude` (neu); in Städten ohne Claude-Zugang 404 vor der Anmeldung. Jeder Aufruf
  liest Verbindung, Konto (aktiv, nicht gesperrt, Stadt, Kontoart wie beim Verbinden), Zugang (nicht
  „aus“) und Rechte der Rolle neu, ohne Zwischenspeicher; Inaktivität und Höchstdauer nach der
  Sitzungsklasse der aktuellen Rolle. 401 mit `WWW-Authenticate` (`error="invalid_token"`, wenn ein
  Token gesendet wurde). Body-Grenze vorerst 16 KiB (3 MiB erst mit dem Import-Werkzeug, M6).
  Benachrichtigungen und Antworten des Clients → 202 ohne Body (`EmptyResponse`). `tools/list` leer,
  `tools/call` → JSON-RPC-Fehler -32602.
- **Metadaten**: `.well-known/oauth-authorization-server` bleibt als gleiches Dokument (Clients nach
  RFC 8414, z. B. ältere SDKs); `api/v1/mcp-probe`, `var/mcp-probe/` und der Platzhalter
  `claude-verbinden` entfallen (ab M4 die Seite der App).
- **Fehler des Token-Endpunkts** im OAuth-Format (`OAuthError`, `JsonResponse` mit `error` und
  `error_description`, ohne Token, Code oder Verifier). Bremse `oauth_register`: 20 je IP-Präfix und
  Stunde (429 im Fehlerformat der App mit `Retry-After`).

## 9b. Vermittler im cgi-bin (Runde 5, Fortsetzung)

Live-Test 07.11.2026: Apache gibt den Header `Authorization` an PHP (FastCGI auf STRATO) nie weiter,
auch nicht mit `SetEnvIf`, `RewriteRule [E=…]` oder `CGIPassAuth` in `public/.htaccess`. Holger hat
gefunden: Ein Perl-Skript im `cgi-bin` von www.prodad.de (klassisches CGI über mod_cgi) bekommt ihn,
wenn dort eine `.htaccess` mit `CGIPassAuth On` liegt (Prüfskript `ff-auth-probe.pl`, nur Namen).
Auftrag Holger vom 07.11.2026:

- **Vermittler** `scripts/cgi-bin/ff-mcp.pl` (Quelle im Repository, dazu `scripts/cgi-bin/.htaccess`
  mit `CGIPassAuth On`). Holger lädt beide von Hand nach `cgi-bin` hoch (liegt außerhalb des
  Projektordners, das Deploy fasst ihn nicht an). Agenten ändern ihn nur im Repository.
  - MCP-Adresse für Claude: `https://www.prodad.de/cgi-bin/ff-mcp.pl/<stadt>`. Die Stadt kommt aus
    `PATH_INFO`, nur ein Segment nach den Regeln von `TenantSlug` (Kleinbuchstaben, Ziffern,
    Bindestrich nicht am Rand und nicht doppelt, höchstens 50 Zeichen), sonst 404 ohne Anfrage an die
    App.
  - Ziel fest: `https://www.prodad.de/firefighting/<stadt>/mcp`, kein anderes Ziel, keine
    Weiterleitungen (`--max-redirs 0`, nur `https`). Methoden `POST`, `GET` und `DELETE` gehen weiter
    (die App antwortet auf `GET`/`DELETE` mit 405), andere beantwortet der Vermittler selbst mit 405
    (`Allow: POST` wie die App).
  - Weiter gehen nur: Body (höchstens 1 MiB, sonst 400 ohne Lesen), `Content-Type`, `Accept`,
    `Mcp-Session-Id`, `MCP-Protocol-Version` und `Authorization` als `X-FF-Authorization`. Werte nur
    aus druckbaren ASCII-Zeichen und höchstens 4096 Zeichen, sonst fällt der Header weg (die App
    antwortet dann z. B. mit 401). Andere Header (Cookie, Origin, ein selbst gesendetes
    `X-FF-Authorization`) gehen nie weiter.
  - Zurück: Status, `Content-Type`, `WWW-Authenticate`, `Allow` (bei 405) und Body (höchstens
    4 MiB), dazu fest `Cache-Control: no-store` und `X-Content-Type-Options: nosniff`.
  - HTTP über das `curl` des Servers (Perl hat für HTTPS kein Modul in den Bordmitteln; `HTTP::Tiny`
    braucht `IO::Socket::SSL`, das nicht sicher vorhanden ist). Aufruf ohne Shell (`fork` und
    `exec` mit Liste). **Token und Body nie in der Befehlszeile** (die Prozessliste ist auf Shared
    Hosting für andere sichtbar): Die Header stehen in einer curl-Konfiguration, die der Vermittler
    über eine eigene Pipe schreibt (`--config /dev/fd/3`), der Body geht über die Standardeingabe
    (`--data-binary @-`, unverändert, auch NUL-Bytes). Nicht den Body in der Konfiguration: curl
    7.81 liest deren Zeilen nur bis rund 100 KiB (gemessen 07.11.2026, darüber fiel `data-raw` still
    weg und curl sendete GET). Keine temporären Dateien. `--disable` (keine `.curlrc`),
    `--http1.1`, `--proto =https`, `--noproxy '*'`, Zeitgrenzen 5 s Verbindung und 25 s insgesamt
    (dazu `alarm` als Rückfall), `--stderr /dev/null`, curl mit leerer Umgebung (nur `PATH`). curl
    unter `/usr/bin/curl`, `/usr/local/bin/curl` oder `/bin/curl` (erstes vorhandenes); ohne curl
    oder ohne `/dev/fd` 502.
  - Fehler des Vermittlers im Fehlerformat der App (`{"error","message"}`): 404 `not_found`,
    405 `method_not_allowed`, 400 `invalid_request`, 502 `bad_gateway` (curl fehlt oder scheitert,
    Antwort unlesbar oder zu groß), 504 `gateway_timeout`.
  - Keine Protokolle: weder Token noch Inhalt (curl schreibt nichts nach stderr, Perl meldet nichts).
  - Tests (`tests/Unit/Scripts/McpRelayScriptTest.php`): `perl -c`; nachgebaute CGI-Umgebung mit
    einem Ersatz für curl (Befehlszeile ohne Token, Konfiguration genau, Antwort, Fehlerfälle, curl nie
    aufgerufen bei 404/405/400); unter Linux zusätzlich mit dem echten curl gegen einen lokalen
    PHP-Server, der Header und Body zurückgibt (Konfiguration und Body von 1 MiB mit Sonderzeichen).
    Übersprungen ohne Perl. Die Testumgebung setzt `FF_MCP_CURL_BIN` (nur für Tests; Apache setzt
    keine solche Variable aus einer Anfrage).
- **App**: `mcp` liest das Token aus `Authorization` oder `X-FF-Authorization` (gleichwertig, das
  Token ist der Nachweis; `Authorization` hat Vorrang, wenn beide da sind). `resource` in
  `.well-known/oauth-protected-resource` und die Prüfung des Parameters `resource` (Autorisierung,
  Token-Endpunkt) verwenden die neue MCP-Adresse (`<base_url>/cgi-bin/ff-mcp.pl/<kürzel>`).
  `resource_metadata` im `WWW-Authenticate` bleibt die Adresse des Dokuments in der App
  (`…/firefighting/<kürzel>/.well-known/oauth-protected-resource`): Der Vermittler leitet nur an
  `mcp` weiter, das Dokument nennt aber die neue Adresse als `resource` (RFC 9728, 3.3: gleich der
  aufgerufenen Adresse). Der Issuer bleibt `…/firefighting/<kürzel>`.
- **Aufräumen**: `public/.htaccess` wieder wie vor Runde 5 (nur die Freigabe; `PublicHtaccessTest`
  prüft genau das), Prüf-Route `api/v1/auth-probe` entfernt, ebenso das Lesen von
  `REDIRECT_HTTP_AUTHORIZATION` (gehörte zur Rewrite-Regel).
- **Ablauf**: je Teil ein Commit (Plan, Vermittler, App, Aufräumen), ein Push, CI, Deploy; dann lädt
  Holger `ff-mcp.pl` und `.htaccess` hoch (Rechte 755 bzw. 644), danach prüft der Agent live: 401 mit
  `WWW-Authenticate` über den Vermittler, mit falschem Token `error="invalid_token"`.

## 9c. Bau Runde 6 (M5, M6): Festlegungen

Auftrag Holger vom 07.11.2026 (Runde 6). Wo der Auftrag strenger ist als die Abschnitte oben, gilt
der Auftrag:

- **M5 Lesewerkzeuge**: `tools/list` nur, was das Konto wirksam darf (Rolle ∩ Zugang, Datenfilter);
  jeder Aufruf über die vorhandene REST-Route mit deren Erfordernis (`ToolApi`), Test, dass jedes
  Werkzeug nur erlaubte Routen nutzt (`ToolRoutesTest`). Matrix: Admin, Benutzer, Rolle mit
  Datenfilter je Lesen/Ändern, dazu Zugang „Aus“ und gesperrtes Konto (`McpToolMatrixTest`).
- **Antworten** kompakt und strukturiert (`structuredContent` und Text), Begriffe wie in der App,
  Listen höchstens 50 je Aufruf (Abweichung von Abschnitt 6: dort Standard 50, höchstens 200) mit
  Hinweis auf weitere und auf Filter; Freitexte (Notiz, Kommentar, Namen) in `free_text_fields`
  genannt und im Text als Daten gekennzeichnet. Kein `outputSchema` (optional in der Spezifikation;
  ein falsches Schema ließe Clients die Antwort verwerfen).
- `list_city_history` ohne Zeitraum: `GET device-events` kennt keinen; älter über `offset` (der
  Export mit Zeitraum bleibt Werkzeugen verschlossen).
- `get_equipment` findet per `number` nur die genaue Nummer (ohne Groß-/Kleinschreibung, wie
  `identifier_key`, nur nicht ausgesonderte) und per `scan_code` genau; sonst `not_found`.

## 10. Offene Fragen (mit Vorschlag)

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| O1 ⚠ | Was, wenn Claude `…/firefighting/test/.well-known/openid-configuration` nicht findet? | Ergebnis der Vorklärung abwarten. Dann Optionen ohne Webroot: (a) Issuer ohne Pfad geht nicht (Webroot); (b) eigener Hostname (z. B. `ff.prodad.de`, eigener Webspace-Ordner, eigene `.htaccess`); (c) Anthropic bitten bzw. auf die Variante warten. Keine Änderung der Webroot ohne Holger. |
| O2 | Neben `https://claude.ai/api/mcp/auth_callback` auch `https://claude.com/api/mcp/auth_callback` erlauben? | Ja (Anthropic empfiehlt beide; in der Vorklärung schon so). |
| O3 | Beendet eine Passwortänderung die Claude-Verbindungen? | Ja, wie alle Sitzungen (Plan Phase 1, Abschnitt 1). |
| O4 | Wiederverwendung eines alten Refresh-Tokens (z. B. Antwort ging verloren)? | Innerhalb von 60 s nach der Rotation einmal erlaubt (neues Paar, das vorige wird ungültig); später → Verbindung löschen (vermutlich gestohlen). |
| O5 | Eigene Claude-Verbindungen im Profil zeigen und trennen (nicht den Zugang ändern)? | Ja, in „Angemeldete Geräte“ als „Claude (claude.ai)“ mit „Trennen“. |
| O6 | Mail an das Konto bei jeder neuen Verbindung? | Ja („Neue Claude-Verbindung“, mit Hinweis auf „Trennen“). |
| O7 | Auf „Claude verbinden“ immer E-Mail und Passwort, auch mit App-Sitzung im selben Browser? | Ja (Zustimmung mit frischer Anmeldung; keine Sitzung entsteht). |
| O8 | Grenze für Aufrufe je Verbindung? | Zunächst keine; Zeitbudget der Mails 5 s. Nach dem Live-Test neu bewerten. |
| O9 | Mehrere Verbindungen je Konto (claude.ai und Claude Code)? | Ja, höchstens 5; die älteste fällt weg. |
| O10 | Darf eine Rolle mit „Benutzer: Ändern“ ohne *Admin* (z. B. „Personal“) den Zugang setzen? | Nein, nur *Admin* (Wortlaut F12). |
| O11 | Lesende Abrufe über Claude protokollieren? | Nein; nur „zuletzt genutzt“ an der Verbindung. |
| O12 | Client ID Metadata Documents (CIMD) zusätzlich anbieten? | Später, wenn Claude es nutzt (spart die Registrierung). |
| O13 | Werkzeug `get_overview` auch ohne `dashboard` (nur `due`)? | Ja, dann nur Termine und Zahlen wie `device-summary` erlaubt. |
| O14 | Zugriffstoken 1 Stunde – auch bei „kurzer“ Sitzungsklasse? | Ja; Verbindung danach höchstens 7 Tage ohne Nutzung, 30 Tage insgesamt. |
