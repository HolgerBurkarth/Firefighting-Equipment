# Übergabe an den Backend-Chat: Änderungen an der API

Jede Änderung an der REST-API (auch neue Felder) wird hier zusätzlich eingetragen, neueste oben,
mit Datum und Betreff des Commits. Verbindlich bleibt `CLAUDE.md`; diese Datei sagt dem
Backend-Chat, was sich seit seinem letzten Stand geändert hat.

Eintrag: Datum, Commit-Betreff (der Agent trägt den Betreff seines Commits ein; seit 31.10.2026
committet er selbst, pushen tut nur der Nutzer), was sich geändert hat, warum, Tests, Stelle in
`CLAUDE.md`.

## 2026-11-08 – „ClientApi::MIN 3 (Phase 2, P2.1, G13)“

- `ClientApi::MIN` von 2 auf 3 (Freigabe Holger 08.11.2026): Apps mit `X-Client-Api` 2 bekommen 409
  `client_outdated` (`details {min_client_api: 3}`) und aktualisieren sich. Tests: `ClientApiTest`,
  `ApplicationTest`. `CLAUDE.md`: Versionierung der API.

## 2026-11-08 – „Status „benutzt“ (Phase 2, Runde 8, P2.1)“

- Neuer Status `used` („benutzt“) in `DeviceStatus`: gültig überall, wo Status vorkommen (Geräte,
  Filter `status=used`, Sortierung nach Status hinter `defective`, `POST devices/{id}/state` und
  `bulk/state`, Hinweise, Verlauf, CSV-Export, CSV-Import „benutzt“ erlaubt, „Gültige Werte“,
  Claude-Werkzeuge). `GET device-summary`: `by_status` hat jetzt vier Einträge (`used` zuletzt).
  Migrationen 0049–0051 erweitern nur CHECK-Bedingungen.
- `X-Client-Api` / `X-Api-Version` jetzt 3 (neuer Statuswert: Apps vor dieser Version kennen ihn nicht).
  `ClientApi::MIN` bleibt 2; Anhebung auf 3 als eigener Schritt nach dem Deploy, außerhalb eines Dienstabends.
- Tests: `DeviceStateTest::testStatusUsedCanBeBookedFilteredAndCounted`, `DeviceSummaryTest`,
  `DeviceStatusTest`, `ClientApiTest`; Frontend `adminOverview.spec.ts`, `tokens.spec.ts`.
  `CLAUDE.md`: Abschnitte HTTP und REST-API (Versionierung), Standorte und Geräte (Status).

## 2026-11-08 – „Claude-Zugang: destructiveHint nur bei aussondern, Import, Standort sperren (Runde 7)“

- Nur Annotationen der Werkzeuge in `tools/list`: `destructiveHint` true nur noch bei
  `retire_equipment`, `apply_import`, `lock_location`; `set_maintenance_date`,
  `bulk_set_maintenance_date`, `update_equipment`, `assign_code`, `deactivate_equipment_type` jetzt
  false. Grund: Festlegung Holger (wie `MCP-KONZEPT.md`); Claude fragt nur dort vorher nach.
- Tests: `ToolRoutesTest::testWriteToolsHaveTheirHints` (Liste `DESTRUCTIVE`). `CLAUDE.md`: Schritt M6.

## 2026-11-08 – „Claude-Zugang: Fehlerdetails der Werkzeuge in ihren Begriffen (Runde 7)“

- Nur Werkzeuge an `POST mcp`, REST-Routen unverändert: `details` eines Fehlerergebnisses
  (`isError`) nennt den Stand jetzt wie `get_equipment` (`details.equipment` mit `number`,
  `equipment_type`, `maintenance_due`, `version` …) statt im Format der REST-API (`details.device`
  mit `identifier`, `next_inspection_due`); beim Sperren eines Standorts `equipment_count` und
  `sublocation_count` statt `devices`/`sublocations`. Andere Angaben bleiben (z. B. `summary` bei
  `import_outdated`).
- Tests: `McpWriteToolsTest::testErrorDetailsUseTheTermsOfTheTools`. `CLAUDE.md`: Schritte M5, M6.

## 2026-11-08 – „Claude-Zugang: Verlauf der Werkzeuge mit Vermerk „über Claude“ (Runde 7)“

- Nur Werkzeuge an `POST mcp`, REST-Routen unverändert: `get_equipment_history`,
  `list_city_history` und der Verlauf in `get_notice` tragen je Eintrag über Claude `via_claude:
  true` (sonst fehlt das Feld), die Zusammenfassung „N Einträge davon über Claude.“. Vorher fehlte
  der Vermerk in den Werkzeugen (Eintrag 257 an M90, Live-Test Holger).
- Tests: `McpWriteToolsTest::testHistoryToolsShowOverClaude`. `CLAUDE.md`: Schritt M6.

## 2026-11-07 – „Claude-Zugang: Schreibwerkzeuge und Vermerk „über Claude“ (Runde 6, M6)“

- Neu im Verlauf: Feld `channel` (`app` | `claude`) in jedem Eintrag von `GET devices/{id}/events`
  und `GET device-events` (Migration `0047`, bisherige Einträge `app`) und in jedem Eintrag von
  `history` eines Hinweises (`GET notices`, `GET notices/{id}`; Migration `0048`). CSV-Export des
  Verlaufs mit neuer letzter Spalte „Über Claude“ (`Ja` bzw. leer). Log-Mails „Wer: Erika (über
  Claude)“. Alle Routen und Antworten sonst unverändert; die App schreibt weiter `app`.
- `POST mcp`: Body bis 3 MiB (CSV-Import); 21 Schreibwerkzeuge (umbuchen, Sammelaktionen,
  Wartungsdatum, anlegen, Stammdaten, Code, aussondern, wieder aufnehmen, Import prüfen/übernehmen,
  Standorte, Ausrüstungstypen, Hinweise) nur mit Zugang „Ändern“ und den Rechten der Rolle;
  Log-Mails über Claude mit Zeitbudget 5 s.
- Grund: Schritt M6 (`docs/PLAN-MCP.md`, Abschnitt 9c).
- Tests: `McpWriteToolsTest`, `McpToolMatrixTest`, `ToolRoutesTest`, `DeviceHistoryExportTest`.
  `CLAUDE.md`: "Claude-Zugang (MCP, im Aufbau)", Schritt M6; "Standorte und Geräte"; "CSV-Export
  (API)"; "Benachrichtigung „Log“".

- `POST mcp`: `tools/list` liefert jetzt die Lesewerkzeuge, die das Konto wirksam darf (Rolle ∩
  Claude-Zugang, ohne `users`, `settings`, `mail.log`; `Permissions::forClaude`):
  `get_overview`, `list_due`, `search_equipment`, `get_equipment`, `get_equipment_history`,
  `list_locations`, `list_equipment_types`, `list_city_history`, `list_notices`, `get_notice`.
  `tools/call` ruft über `ToolApi` die vorhandenen GET-Routen auf (derselbe Router, deren
  `Requirement`, derselbe Datenfilter); Antwort `{content: [{type: text, text}], structuredContent,
  isError}`, Fehler der Routen als `isError` mit `{error, message, details?}`. REST-Routen selbst
  unverändert; neu nur `JsonResponse::data()` und `AuthSession::$claude`/`viaClaude()` (intern).
- Grund: Schritt M5 (`docs/PLAN-MCP.md`).
- Tests: `McpToolMatrixTest` (Admin, Benutzer, Datenfilter × Lesen/Ändern, Zugang aus, gesperrt),
  `McpReadToolsTest`, `McpEndpointTest`, Unit `ToolRoutesTest`, `PermissionsForClaudeTest`.
  `CLAUDE.md`: "Claude-Zugang (MCP, im Aufbau)", Schritt M5.

## 2026-11-07 – „Aufräumen: public/.htaccess wie vor Runde 5, auth-probe entfernt (Runde 5)“

- Entfernt: die vorübergehende Prüf-Route `GET api/v1/auth-probe` (jetzt 404 `not_found`) und das Lesen von `REDIRECT_HTTP_AUTHORIZATION` (gehörte zur Rewrite-Regel).
  `public/.htaccess` enthält wieder nur die Freigabe. Grund: Das Token kommt über den Vermittler im
  cgi-bin als `X-FF-Authorization` (Eintrag darunter).
- Tests: `PublicHtaccessTest` (nur die Freigabe), `TenantRoutePermissionsTest`, `RequestTest`.
  `CLAUDE.md`: "Server und .htaccess", "Claude-Zugang (MCP, im Aufbau)", Schritt M3.

## 2026-11-07 – „mcp liest X-FF-Authorization, Ressource ist die Adresse des Vermittlers (Runde 5)“

- `POST mcp` nimmt das Zugriffstoken aus `Authorization` oder gleichwertig aus
  `X-FF-Authorization` (ein nicht leerer `Authorization` hat Vorrang). Grund: Apache gibt
  `Authorization` an PHP auf STRATO nie weiter; der neue Vermittler `cgi-bin/ff-mcp.pl` (Quelle
  `scripts/cgi-bin/ff-mcp.pl`) bekommt ihn und reicht ihn so an `mcp` weiter.
- `resource` in `GET .well-known/oauth-protected-resource` und die Prüfung des Parameters `resource`
  (`api/v1/oauth/authorize…`, `oauth/token`) verwenden jetzt die MCP-Adresse des Vermittlers
  `https://www.prodad.de/cgi-bin/ff-mcp.pl/<kürzel>` statt `…/firefighting/<kürzel>/mcp` (die
  frühere wird mit `invalid_target` abgelehnt). `resource_metadata` im `WWW-Authenticate` und der
  Issuer bleiben unverändert.
- Tests: `McpEndpointTest` (Header des Vermittlers), `OAuthAuthorizeTest`, `OAuthTokenTest`
  (frühere Adresse), `McpRelayScriptTest` (Konstanten gleich dem Skript). `CLAUDE.md`:
  "Vermittler für Claude (cgi-bin)", "Claude-Zugang (MCP, im Aufbau)", Schritt M3.

## 2026-11-07 – „Vorübergehende Prüf-Route auth-probe für Authorization (Runde 5, Live-Test)“

- Neu, VORÜBERGEHEND: `GET api/v1/auth-probe` (öffentlich, nur Test-Stadt, sonst 404) → `{sapi,
  gateway_interface, server: {NAME: bool}, env: {NAME: bool}}`: Namen der Variablen mit „AUTH“ aus
  `$_SERVER` und `getenv()` und ob der Wert nicht leer ist, nie Werte. Grund: `Authorization` kommt
  auf STRATO trotz `SetEnvIf` und `RewriteRule [E=…]` nicht als `HTTP_AUTHORIZATION` an; die Route
  zeigt, ob und unter welchem Namen. Wird nach dem Live-Test wieder entfernt.
- Tests: `AuthProbeTest`, `AuthProbeEndpointTest`, `TenantRoutePermissionsTest`. `CLAUDE.md`:
  "Claude-Zugang (MCP, im Aufbau)", Schritt M3.

## 2026-11-07 – „Authorization per RewriteRule statt SetEnvIf an PHP geben (Runde 5, Live-Test)“

- Live wirkte `SetEnvIf` nicht (vermutlich `mod_setenvif` nicht geladen). `public/.htaccess` setzt
  `HTTP_AUTHORIZATION` jetzt in `<IfModule mod_rewrite.c>` per `RewriteCond %{HTTP:Authorization} .`
  und `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` (Freigabe Holger 07.11.2026);
  `SetEnvIf` ist entfernt. Keine Änderung an Routen oder Antworten.
- Tests: `PublicHtaccessTest`. `CLAUDE.md`: "Server und .htaccess".

## 2026-11-07 – „Authorization per SetEnvIf in public/.htaccess an PHP geben (Runde 5, Live-Test)“

- `POST mcp`: Auch `REDIRECT_HTTP_AUTHORIZATION` setzt STRATO nicht. `public/.htaccess` gibt den
  Header jetzt per `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1` (in `<IfModule
  mod_setenvif.c>`, Freigabe Holger 07.11.2026) als Variable an PHP; `Request::fromServer` liest sie
  wie bisher. Keine Änderung an Routen oder Antworten.
- Tests: `PublicHtaccessTest` (Freigabe unverändert, genau diese Regel). `CLAUDE.md`: "Server und
  .htaccess".

## 2026-11-07 – „Authorization auch aus REDIRECT_HTTP_AUTHORIZATION lesen (Runde 5, Live-Test)“

- `POST mcp`: Live auf STRATO kam der Header `Authorization` nicht bei PHP an (PHP als CGI; auch
  ein Token mit gültigem Format ergab 401 ohne `error="invalid_token"`). `Request::fromServer` liest
  ihn jetzt zusätzlich aus `REDIRECT_HTTP_AUTHORIZATION`, wenn `HTTP_AUTHORIZATION` fehlt (Name nach
  der internen Weiterleitung per `RewriteRule`). Sonst keine Änderung an Routen oder Antworten.
- Tests: `RequestTest::testAuthorizationAfterInternalRedirectIsRead`. `CLAUDE.md`: "Claude-Zugang
  (MCP, im Aufbau)", Schritt M3, `POST mcp`.

## 2026-11-07 – „OAuth-Server und mcp für den Claude-Zugang, Vorklärung entfernt (Runde 5, M3)“

`docs/PLAN-MCP.md`, Schritt M3 und Abschnitt 9a (Festlegungen dieser Runde). Nur in der Test-Stadt;
in anderen Städten gibt es die Routen nicht (404 `not_found`), unter `service` ebenso.

- Entfernt: die Vorklärung (`McpProbeHandler`, `McpProbeTrace`, `GET api/v1/mcp-probe`, der
  JSON-Platzhalter `GET claude-verbinden`; die Adresse liefert jetzt die App).
- Migrationen `0045` (Bremse `oauth_register`) und `0046` (`fe_claude_connections.code_hash`), nur
  Erweiterungen.
- Metadaten: `GET .well-known/oauth-protected-resource`, `GET .well-known/openid-configuration`,
  `GET .well-known/oauth-authorization-server` (gleiches Dokument, jetzt mit `revocation_endpoint` und
  `authorization_response_iss_parameter_supported`), `GET .well-known/jwks.json`.
- `POST oauth/register` (RFC 7591): speichert die Registrierung; nur erlaubte Rücksprung-Adressen,
  nur öffentliche Clients; Fehler im OAuth-Format; Bremse 20 je IP-Präfix und Stunde (429).
- Neu, für die Seite „Claude verbinden“ (M4): `POST api/v1/oauth/authorize/check {params}` und
  `POST api/v1/oauth/authorize {params, decision: preview|allow|deny, email?, password?}`; Antworten
  und Fehler siehe `CLAUDE.md`. Neuer Fehlercode `claude_access_missing` (403). Kein Rücksprung, wenn
  Client oder Adresse nicht exakt registriert sind; sonst 400 mit `details {redirect_url}`.
- Neu: `POST oauth/token` (Formular statt JSON; Code einlösen mit PKCE S256, Refresh mit Rotation und
  60 s Ausnahme für das vorige Token) und `POST oauth/revoke` (RFC 7009). Fehler im OAuth-Format
  (`{"error", "error_description"}`).
- `POST mcp` jetzt mit Anmeldung (`Access::Claude`, `Authorization: Bearer`): `initialize`, `ping`,
  leeres `tools/list`; Benachrichtigungen 202 ohne Body. Ohne bzw. mit ungültigem Token 401 mit
  `WWW-Authenticate` wie bisher (bei gesendetem Token zusätzlich `error="invalid_token"`).
- Neu (Profil, O5): `GET auth/claude-connections` und `DELETE auth/claude-connections/{id} {}`
  (`Access::Authenticated`, nur unter Städten).
- Mail „Neue Claude-Verbindung“ an das Konto nach dem Einlösen eines Codes.

Warum: Claude verbindet sich als die Person mit ihrer Rolle und höchstens ihrem Claude-Zugang
(`docs/MCP-KONZEPT.md`, Abschnitte 4–7); Werkzeuge folgen mit M5.

Tests: `OAuthAuthorizeTest`, `OAuthTokenTest`, `McpEndpointTest` (neu, `tests/Integration/Claude/`),
`OAuthRulesTest`, `RequestGuardFormTest` (neu), `RouterTest`, `TenantRoutePermissionsTest`;
`McpProbeTest` entfällt. `CLAUDE.md` "HTTP und REST-API" und "Claude-Zugang (MCP, im Aufbau)",
Schritt M3.

## 2026-11-07 – „Claude-Zugang im Backend: Zugang setzen, Verbindungen trennen (Runde 4, M1)“

`docs/PLAN-MCP.md`, Schritt M1 (`docs/MCP-KONZEPT.md`, Abschnitt 2, F12). Noch keine Verbindung möglich
(OAuth und MCP folgen mit M3); nur in der Test-Stadt (`ClaudeAvailability`, fest im Code).

- Migrationen `0041`–`0044` (nur Erweiterungen): `fe_users.claude_access` (`off`/`read`/`write`,
  Standard `off`, CHECK: nie bei Super-Admins und gelöschten Konten), Tabellen `fe_oauth_clients`,
  `fe_oauth_codes`, `fe_claude_connections`.
- Neue Routen unter Städten (`users` Ändern, zusätzlich F12 im Code): `POST users/{id}/claude-access
  {access}` und `POST users/{id}/claude-connections/end {}`; Antwort `{"user": …}`. Nur die Rolle
  *Admin*, nur Konten ohne *Admin*, nie das eigene Konto, Lesen/Ändern nur für aktive Konten, nur in
  der Test-Stadt; sonst 409 `rule_violation`. `access` ungültig → 400 (vor 404).
- Neue Routen unter `service` (`Access::SuperAdmin`): `POST tenants/{id}/users/{user}/claude-access
  {access}` (nur Konten mit *Admin*) und `POST tenants/{id}/users/{user}/claude-connections/end {}`
  (jedes Konto der Stadt); Antwort `{"user": …}`, Konto einer anderen Stadt 404.
- `UserJson` (Benutzerverwaltung, Konten im Stadtdetail): zusätzlich `claude_access`,
  `claude_access_label` (Aus/Lesen/Ändern) und `claude_connections {count, last_used_at}`.
- `GET tenant` (auch die Antworten der Routen zum Erscheinungsbild) und `TenantJson` des Super-Admins:
  zusätzlich `claude_available` (bisher nur in der Test-Stadt `true`).
- Verbindungen und offene Codes enden auf allen Wegen, die die Sitzungen eines Kontos beenden
  (`UserRepository::endSessions`: sperren, Passwort setzen und zurücksetzen, anonymisieren, Wechsel zu
  oder von *Admin*) sowie beim Sperren der Stadt; ein Wechsel zu oder von *Admin* und das Anonymisieren
  setzen den Zugang auf `off`. Andere Rollenwechsel und „Ändern“ → „Lesen“ lassen die Verbindungen
  bestehen.

Tests: `ClaudeAccessTest` (neu), `TenantRoutePermissionsTest`, `AdminRoutesAccessTest`,
`SuperAdminRoutesAccessTest` (neue Routen), `UserAdministrationTest`, `TenantAdministrationTest`,
`AppearanceApiTest`, `ApplicationTest` (neue Felder). `CLAUDE.md` "Claude-Zugang (MCP, im Aufbau)".

## 2026-11-07 – „MCP-Vorklärung: OAuth-Metadaten und Platzhalter unter /firefighting/test/ (Runde 3)“

Vorklärung zum Claude-Zugang (`docs/PLAN-MCP.md`, Abschnitt 1). Nur in der Test-Stadt, in allen anderen
Städten 404 `not_found`; wird mit Schritt M3 entfernt bzw. durch die echten Endpunkte ersetzt.

- Neue öffentliche Routen unter `/firefighting/test/` (außerhalb von `api/v1`): `POST mcp` (401
  `not_authenticated` mit `WWW-Authenticate: Bearer resource_metadata="…", scope="read write"`; `GET mcp`
  405), `GET .well-known/oauth-protected-resource`, `GET .well-known/openid-configuration`, `GET
  .well-known/oauth-authorization-server`, `GET .well-known/jwks.json`, `POST oauth/register` (201,
  speichert nichts), `GET claude-verbinden` (JSON-Platzhalter). Dazu `GET api/v1/mcp-probe` (`settings`
  Ändern): Protokoll der letzten 50 Abrufe dieser Routen.
- Unbekannte Adressen unter `.well-known/` liefern 404 `not_found` statt der App-Seite.
- Grund: Prüfen, ob Claude die OAuth-Metadaten unter `/firefighting/<stadt>/` findet, ohne die
  `.htaccess` des Webspace zu ändern.

Tests: `McpProbeTest`, `TenantRoutePermissionsTest` (neue Routen), `FrontendDeliveryTest`
(`.well-known`). `CLAUDE.md` "Aufbau und URLs", "HTTP und REST-API" (Reihenfolge), "Frontend
ausliefern", "Claude-Zugang (MCP, in Planung)".

## 2026-11-06 – „Konto wechseln: Liste der Konten in mehreren Städten (Runde 1, Aufgabe 2, Schritt 1.9)“

Plan Phase 1, Schritt 1.9 (Konzept E14).

- `GET auth/me` (auch die Antworten von `auth/login` und `auth/password`): im Objekt `user`
  zusätzlich `role_id` und `role_name` (Rolle der Stadt des Kontos; unter `service` beide `null`).
  Reine Erweiterung; `role` bleibt die Kontoart. Grund: Die Liste „Konto wechseln“ zeigt je Stadt den
  Rollennamen. Der Name kommt aus derselben Abfrage wie die Rechte (`PermissionRepository`,
  `Permissions::roleName`), keine zusätzliche Abfrage.

Tests: `LoginTest::testTenantUserLogsIn` (Felder), `testMeNamesTheRoleOfTheTenant` (Admin, eigene Rolle,
Super-Admin). `CLAUDE.md` "Rollen und Rechte" (`GET auth/me`), "Frontend" (Konto wechseln).

## 2026-11-06 – „Begriffe: Ausrüstung, Termine, Wartung und Nummer in App, Mails, Meldungen und CSV (Runde 1, Aufgabe 1)“

Plan Phase 1, Abschnitt 8 (F14), Festlegungen Holger vom 06.11.2026.

- Texte, die Nutzer sehen: Meldungen der API (`message`, auch in `details`, Vorschau des Imports,
  `result.message`), Bezeichnungen (`type_label` „Wartungsdatum geändert“, `not_applied_label`
  „Ausrüstung ausgesondert“, `kind_label`, Feldnamen `field.label` „Nummer“, „Ausrüstungstyp“) und
  Mails sagen jetzt Ausrüstung statt Gerät, Ausrüstungstyp, Wartungsdatum bzw. Nächste Wartung statt
  Prüfdatum bzw. Nächste Prüfung, Nummer statt Kennung. Name der App „Ausrüstungsverwaltung“:
  Manifest (`name` „<Stadt> – Ausrüstungsverwaltung“, `short_name` „FF-Ausrüstung“) und Absender
  `"Feuerwehr Ausrüstungsverwaltung" <feuerwehr-app@prodad.de>`.
- CSV: Export schreibt „Nummer“, „Ausrüstungstyp“, „Nächste Wartung“; Verlauf „Ausrüstungs-ID“ (bisher
  „Geräte-Nr.“), „Nummer“, „Ausrüstungstyp“, „Wartungsdatum alt/neu“. Der Import nimmt alte und neue
  Überschriften an (`ImportColumn::formerLabel`), `columns` der Vorschau nennt die neuen. Dateinamen
  `ausruestung-<kürzel>-<datum>.csv` und `ausruestung-vorlage-<kürzel>.csv`.
- Unverändert: Routen, Felder, Codes und Werte der API, Adressen, Kommandozeile.

Tests: Erwartungen in den betroffenen PHP-Tests angepasst; neu `ImportColumnTest::testNewAndFormerHeadersAreAccepted`,
`testExportWritesTheNewTerms`, `DeviceImportFileTest::testNewAndFormerHeadersCanBeMixed`,
`testNewAndFormerHeaderOfTheSameColumnAreDuplicate`; die Import-Tests verwenden weiter die alten
Überschriften. `CLAUDE.md` "CSV-Export (API)", "CSV-Import (API)", "E-Mail-Versand", "Fachliche
Entscheidungen".

## 2026-11-06 – „Rollen: ClientApi::MIN = 2, Apps vor den Rechten aktualisieren sofort (Phase 1, Schritt 1.8a)“

Plan Phase 1, Schritt 1.8a (Holger, 06.11.2026: als eigener Schritt vor den Rollen für Immendingen).

- `ClientApi::MIN` = 2 (vorher 1): Jede Anfrage mit `X-Client-Api: 1` (oder 0) bekommt vor Stadt,
  Datenbank und Handler 409 `client_outdated` mit `details {"min_client_api": 2}`. Grund: Apps mit 1
  (04./05.11.2026, vor Schritt 1.7c) richten Menü und Knöpfe nach `role` statt nach den Rechten; sie
  sollen sich sofort aktualisieren, bevor Städte eigene Rollen bekommen.
- Unverändert: Anfragen ohne `X-Client-Api` (Apps vor Phase 0, curl, Deploy-Skript) und mit einer
  Nummer ab 2 gehen durch. `ClientApi::CURRENT` bleibt 2, keine Änderung an Routen, Feldern oder
  anderen Fehlern.

Tests: `ClientApiTest::testAppsBeforeRightsAreOutdated`, `ApplicationTest` (Fall „Nummer 1 unter einer
Stadt“, `details.min_client_api` 2). `CLAUDE.md` "Versionierung der API für das Frontend", "Rollen und
Rechte".

## 2026-11-05 – „Rollen: Rechte ändern sich während der Sitzung, X-Client-Api 2 (Phase 1, Schritt 1.7c)“

Plan Phase 1, Schritt 1.7c, `X-Client-Api` Variante B (Holger, 05.11.2026).

- `ClientApi::CURRENT` = 2 (`resources/client-api.json` ebenso): Jede JSON- und CSV-Antwort trägt
  `X-Api-Version: 2`, die App sendet `X-Client-Api: 2`. Grund: Menü, Leiste und Knöpfe der App folgen
  seit 1.7b den Rechten (`permissions`), nicht mehr `role`; eine App mit 1 zeigte bei eigenen Rollen
  Falsches. Sie sieht `X-Api-Version: 2`, prüft sofort auf ein Update und lädt die neue Version beim
  nächsten Seitenwechsel.
- `ClientApi::MIN` bleibt 1: Anfragen mit `X-Client-Api: 1` (oder ohne) gehen weiter durch, keine
  409 `client_outdated`. Über MIN = 2 wird in Schritt 1.8 entschieden.
- Sonst keine Änderung an Routen, Feldern oder Fehlern. Die App fragt `auth/me` jetzt zusätzlich beim
  Zurückkehren in die App (höchstens alle 30 s) und nach 403 `forbidden` (höchstens alle 5 s).

Tests: `ClientApiTest` (Datei = `CURRENT`), `JsonResponseTest`, `CsvResponseTest`, `DeviceExportTest`
(Header `2`); Frontend `clientApi.spec.ts`, `client.spec.ts`, Browser-Tests prüfen jede Anfrage auf
`X-Client-Api` laut Datei. `CLAUDE.md` "Versionierung der API für das Frontend", "Rollen und Rechte"
(App nach Rechten).

## 2026-11-05 – „Rollen: Aktion csv.export für die CSV-Exporte, Benutzer 403 (Phase 1, Schritt 1.7a)“

Plan Phase 1, Entscheidung F2 (Holger, 05.11.2026), Schritt 1.7a. **Gewollte Verhaltensänderung für
die Standard-Rolle *Benutzer*:** Sie erhält bei beiden Exporten jetzt 403 `forbidden` (bisher 200);
die App bot *Benutzer* den Export nie an.

- Neue Aktion `csv.export` („CSV-Export“, braucht `devices` Ansehen, auch mit Datenfilter erlaubt,
  Gruppe „Mehrere Ausrüstungen“) im Katalog (`GET permission-catalog`, `GET roles` → `catalog`,
  `permissions.actions` in `auth/me`). *Admin* hat sie automatisch, *Benutzer* nicht; eigene Rollen
  können sie bekommen.
- `GET devices/export`: bisher `search` oder `devices` Ansehen, jetzt `csv.export`.
- `GET device-events/export`: bisher `search`, `devices` oder `log` Ansehen, jetzt `csv.export` und
  `log` Ansehen.
- Vorlage und gültige Werte des Imports (`GET devices/import/template`, `…/values`) bleiben bei
  `csv.import`.
- Sitzungsdauer: Eine eigene Rolle mit wirksamem `csv.export` hat die kurze Dauer (7 / 30 Tage), da
  es eine Aktion außer `book.*` und `mail.log` ist. Für die Standard-Rollen unverändert.
- `permissions.version` ändert sich dadurch nicht (gespeicherte Rechte der Rollen sind dieselben).

Tests: `TenantRoutePermissionsTest` (Tabelle, `CHANGED_FOR_USER`, neuer Test
`testStandardRoleUserGetsForbiddenOnExports`), `TenantReadRoutesAccessTest` (Exporte nur Admin,
Benutzer 403), `RoleApiTest` (Katalog), `PermissionCatalogFixtureTest` (`permission-catalog.json`
neu geschrieben), `SessionClassTest`, Export-Tests als Admin. `CLAUDE.md` "Rollen und Rechte",
"CSV-Export (API)", "Standorte und Geräte" (Rechte).

## 2026-11-05 – „Rollen: Rollen zuweisen, Freischalten und Einladungscodes mit Rolle im Backend (Phase 1, Schritt 1.6a)“

Plan Phase 1, Abschnitt 5 „Zuweisung durch den Admin“ und „Einladungscodes mit Rolle“, Vorgaben Holger
(05.11.2026: Schutzregeln F5, F1, F6). Nur Erweiterungen; alte Apps senden weiter `role` und lesen
`role`.

- `GET roles` (neu, `users` Ansehen) → `{roles, catalog}`: je Rolle der Stadt die Felder wie beim
  Super-Admin (`RoleJson::role` mit `usage`) und `assignable`, `assign_blocker` (Meldung, warum das
  Konto der Sitzung sie nicht vergeben darf, sonst `null`); `catalog` wie `GET permission-catalog`.
- `POST users/{id}/role {role_id}` und `POST users/{id}/approve {display_name, role_id}`: `role_id` =
  Rolle der Stadt (unbekannt/fremd 400 „Die Rolle #N gibt es nicht. (Feld "role_id")“); `role`
  (`user`/`admin`) bleibt erlaubt, beides zusammen 400, keines 400 „Das Feld "role_id" fehlt.“
  (bisher „Das Feld "role" fehlt …“).
- `POST users/bulk/role {user_ids, role_id}` (neu, `users` Ändern): 1–50 Konten, je Konto eigene
  Transaktion, Antwort `{results: [{id, outcome, user, error}], summary {total, applied, unchanged,
  errors}}` wie die Sammelaktionen der Geräte.
- `UserJson` (alle Antworten mit Konten, auch beim Super-Admin): neu `role_id`, `role_name`,
  `invitation_code {id, label}`.
- `POST invitation-codes`: neu optional `role_id` (Rolle nach der Freischaltung; *Admin* 400 mit dem
  Text aus dem Mockup, nicht vergebbar 409); `GET invitation-codes` und die Antwort: neu `role {id,
  name}` (ohne Rolle am Code *Benutzer*). `POST auth/register` gibt dem Konto die Rolle des Codes.
- Verhalten: Ein Rollenwechsel beendet die Sitzungen nur, wenn *Admin* dazukommt oder wegfällt (F1;
  bisher immer, damals gab es nur diese beiden). Ohne `mail.log` in der neuen Rolle geht die
  Benachrichtigung „Log“ aus. Eigene Rolle ändern: neue Meldung „Sie können Ihre eigene Rolle nicht
  ändern.“ (bisher „… die Admin-Rolle nicht entziehen.“).
- Schutzregeln F5 (409 `rule_violation`): *Admin* vergeben/entziehen und Konten mit *Admin* ändern,
  sperren, entsperren, löschen nur *Admin*; Rolle zuweisen, freischalten, Code mit Rolle nur mit allen
  Rechten der Rolle (Datenfilter innerhalb des eigenen); Konto ändern nur mit allen Rechten seiner
  bisherigen Rolle. Für *Admin* ändert sich nichts.

Tests: `UserRoleAssignmentApiTest` (neu), `PermissionsTest` (`covers`, `includes`),
`UserAdministrationTest`, `UserAdministrationRepositoryTest`, `InvitationCodeEndpointsTest`,
`TenantRoutePermissionsTest` und `AdminRoutesAccessTest` (neue Routen). `CLAUDE.md` "Benutzerverwaltung
durch den Admin", "Einladung, Registrierung und E-Mail-Bestätigung", "Benutzerverwaltung im Code",
"Benutzer und Anmeldung" (Sitzungsdauer).

## 2026-11-05 – „Rollen: Name der Kopie „(Kopie 2)“, höchstens 50 Zeichen“

`POST tenants/{id}/roles {copy_of}` ohne `name` (nur unter `service`): Die Kopie heißt „<Name> (Kopie)“,
ist der Name vergeben, „<Name> (Kopie 2)“, „(Kopie 3)“ … (bisher „<Name> (Kopie) (2)“). Der Name hat
höchstens 50 Zeichen; gekürzt wird der Name der Vorlage, nie der Zusatz (bisher konnte „(Kopie)“
abgeschnitten werden). Übernahme aus einer anderen Stadt unverändert („<Name>“, „<Name> (2)“).
Warum: Festlegung Holger nach dem Live-Test von Schritt 1.5 (05.11.2026). Tests:
`RoleApiTest::testCopyKeepsRightsAndFilters`, `testCopyNameIsShortenedToFiftyCharacters`.
`CLAUDE.md` "Rollen und Rechte", Verwaltung der Rollen.

## 2026-11-05 – „Rollen: Verwaltung durch den Super-Admin im Backend (Phase 1, Schritt 1.5a)“

Neue Routen nur unter `service` (alle `Access::SuperAdmin`, in `SuperAdminRoutesAccessTest`); unter
Städten ändert sich nichts. Vorgaben Holger (05.11.2026) und Plan Phase 1, Abschnitt 5:

- `GET permission-catalog`: `{areas: [{key, label, hint, levels, levels_with_filter}], levels:
  [{key, label}], action_groups: [{label, actions: [{key, label, requires {area, level}|null,
  with_filter}]}]}` (F3: `levels_with_filter` bzw. `with_filter` = was es mit „Sieht nur …“ gibt).
- `GET tenants/{id}/roles` → `{roles}` (Admin, Benutzer, dann nach Name); je Rolle `{id, name,
  description, system_key, all_rights, editable, deletable, areas {bereich: read|write}, actions,
  locations (null = alle), device_types (null = alle), version, created_at, updated_at, usage
  {accounts, invitation_codes}}`. *Admin* mit allen Rechten des Katalogs (`all_rights`). `usage`:
  Konten ohne gelöschte, offene Einladungscodes (ohne Rolle zählen sie zu *Benutzer*).
- `POST tenants/{id}/roles {name, description?, areas, actions, locations, device_types}` → 201
  `{role}`; Kopie `{copy_of, name?}` (dieselbe Stadt, Rechte und Datenfilter, Name ohne Angabe „<Name>
  (Kopie)“, sonst „… (2)“; Kopie von *Admin* = gewöhnliche Rolle mit allen Rechten des Katalogs).
  `POST tenants/{id}/roles/import {source_role_id, name?}` → 201 (andere Stadt, nur Rechte, F10).
- `GET`/`POST`/`DELETE tenants/{id}/roles/{role}`: Speichern mit `version` und ganzem Stand (ohne
  Unterschied keine neue Version), Löschen `{}` → `{deleted_role_id}`.
- `GET tenants/{id}/role-filter-options` → `{locations: [{id, name, kind, kind_label, active,
  children}], device_types: [{id, name, active}]}` (auch gesperrte/deaktivierte, ohne Gerätezahlen).
- Fehler: ungültiger Wert, unbekannter Bereich, Stufe oder Aktion, leerer Filter („Bitte mindestens
  einen Standort wählen oder „Alle Standorte“.“), Standort oder Typ einer anderen Stadt, unbekannte
  Vorlage → 400; Name vergeben (ohne Groß-/Kleinschreibung) → 409 `name_taken`; veraltete `version` →
  409 `version_conflict` mit `details {role}`; *Admin* ändern oder löschen, *Benutzer* löschen, Rolle
  mit Konten oder offenen Codes löschen (`details {accounts, invitation_codes}`), F3 (Datenfilter mit
  `users`, `locations`/`device_types` Ändern oder `csv.import`, `details {areas, actions}`, in beide
  Richtungen) → 409 `rule_violation`.
- Löschen: Gelöschte (anonymisierte) Konten der Rolle bekommen die Rolle *Benutzer*, nicht mehr
  nutzbare Codes keine Rolle (= *Benutzer*); dann wird die Rolle gelöscht.
- Jede Änderung erhöht `fe_roles.version` und damit `permissions.version` in `auth/me`; Sitzungen
  bleiben (F1), die Rechte gelten ab der nächsten Anfrage.
- Ergänzungen zu 1.4: `Permissions::none()` sieht nichts (`DeviceVisibility::none()`); feste Liste
  der Stellen mit `DeviceVisibility::all()` per Test.
- `bin/tenant.php purge-device-data` entfernt vorher die Standortfilter der Rollen der Stadt; die
  Rollen behalten den Filter (sehen nichts), ihre `version` steigt, die Ausgabe nennt sie.
- Tests: `RoleApiTest`, `SuperAdminRoutesAccessTest`, `TenantPurgeCommandTest`, `DeviceVisibilityTest`.
- Dokumentiert in `CLAUDE.md`, "Rollen und Rechte" (Verwaltung der Rollen), "Gerätedaten zurücksetzen".

## 2026-11-05 – „Rechte: Datenfilter „Sieht nur …“ in allen Abfragen und Mails (Phase 1, Schritt 1.4)“

Für *Admin* und *Benutzer* (ohne Datenfilter) bleibt alles gleich, außer dem neuen Feld
`selectable` der Standorte. Rollen mit Datenfilter gibt es erst mit dem Editor (Schritt 1.5); bis
dahin nur in Tests (Plan Phase 1, Abschnitt 4 mit den Festlegungen vom 05.11.2026):

- `DeviceVisibility` (Standorte, Ebene 1 mit ihren Fächern, und Gerätetypen; UND; eingeschaltet
  ohne Auswahl = nichts) aus der Rolle, in allen Repositorys der Geräte, des Verlaufs, der Hinweise,
  Standorte und Gerätetypen (Pflicht im Konstruktor).
- Gerät außerhalb → 404 wie fremd (Einzelansicht, Verlauf, jede Änderung, `version_conflict` ohne
  `details`, Wiederholung einer gespeicherten `client_ref`, in Sammelaktionen `error` `not_found` mit
  `device: null`); Ziel-Standort oder Gerätetyp außerhalb → 400 wie unbekannt.
- `GET devices` (auch `q`, `scan_code`), `devices/export`, `inspection-due`, `device-summary` (auch
  `notices.open`), `device-events`, `device-events/export` (Grenze zählt nur Sichtbare), `notices`:
  nur sichtbare Geräte (Verlauf der Stadt und Hinweise nach dem jetzigen Stand des Geräts).
- Standorte außerhalb erscheinen als `{"id": null, "name": "anderer Standort", "path": null}` in
  `devices/{id}/events`, `device-events`, Hinweisen (`booking.location`, `other.location`), im
  CSV des Verlaufs und in Mails als Text „anderer Standort“.
- `GET locations`, `GET locations/{id}`: neues Feld `selectable` (immer true ohne Standortfilter);
  mit Standortfilter nur sichtbare Standorte, ein Standort der Ebene 1, von dem nur Fächer sichtbar
  sind, mit `selectable: false` und `device_count: null`. `device_count`/`device_count_total` und
  `GET device-types` zählen nur sichtbare Geräte; mit Typfilter nur sichtbare Typen.
- F3 zur Laufzeit: Mit Datenfilter wirken `users`, `locations`/`device_types` Ändern und
  `csv.import` nicht (403), auch wenn die Rolle sie hätte; `auth/me` zeigt das so.
- `permissions.version` in `auth/me` enthält jetzt auch die IDs des Filters.
- Mails „Log“ und Hinweis-Mails je Empfänger mit dessen Filter (vorher oder nachher sichtbar;
  Sammelmail/Import nur seine Zeilen, sonst keine Mail).
- Tests: `DeviceVisibilityMatrixTest` (Admin, nur Standort, nur Typ, beide; leerer Filter, Fach,
  neues Fach, Wiederholung, Versionskonflikt, F3), `DeviceVisibilityTest` (Unit, Reflection der
  Repositorys), `DeviceLogNotificationTest`, `DeviceNoticeNotificationTest`, `LocationEndpointsTest`;
  Frontend `bookingQueue.spec.ts` (404 → `failed`), Parser in `deviceHistory.spec.ts`,
  `notices.spec.ts`, `locationsApi.spec.ts`.
- Dokumentiert in `CLAUDE.md`, "Rollen und Rechte" (Datenfilter), "Gerätetypen und Standorte (API)",
  "Benachrichtigung „Log“".

## 2026-11-05 – „Rechte: auth/me mit permissions, Sitzungsdauer und Log-Mails aus der Rolle (Phase 1, Schritt 1.3b)“

Für die Standard-Rollen *Admin* und *Benutzer* bleibt das Verhalten gleich (per Test); neu ist ein
Feld in `auth/me` und, dass Sitzungsdauer und Log-Mails aus den Rechten der Rolle folgen (Plan
Phase 1, Abschnitt 5, F9, F11):

- `GET auth/me` und `POST auth/login`/`auth/password` (gleiche Antwort `AuthJson::me`): neues Feld
  `permissions` = `{areas: {<bereich>: "read"|"write"}, actions: [...], restrictions: [], filtered:
  bool, version: "<64 Hex>"}`, unter `service` `null`. Nur wirksame Rechte (Aktionen nur mit ihrem
  Bereich), Reihenfolge des Katalogs; `areas` immer ein Objekt (`{}` ohne Bereiche); `restrictions`
  bis Phase 2 leer; `filtered` = Rolle mit Datenfilter (wirkt ab 1.4); `version` = SHA-256 über
  Rolle, `fe_roles.version` und die Rechte (ändert sich auch, wenn der Katalog wächst). Die App
  liest das Feld noch nicht (ab Schritt 1.7). *Admin*: alle Bereiche und Aktionen; *Benutzer*:
  `search`/`due` `read`, `book.move`, `book.status`.
- Sitzungsdauer aus den Rechten (`SessionClass`, `SessionLifetime` je Klasse): kurz 7 / 30 Tage
  (Rolle *Admin*, `users` oder `settings` mindestens Ansehen, ein Bereich auf Ändern oder eine
  wirksame Aktion außer `book.*` und `mail.log`), lang 30 / 90 Tage (sonst), Super-Admin unverändert
  1 / 12 Stunden. `resume` prüft zusätzlich `created_at` + Höchstdauer der aktuellen Klasse (eine
  strengere Rolle gilt sofort). Cookie-`Max-Age` und `expires_at` wie bisher aus der Klasse beim
  Anmelden. Die Rechte lädt jetzt `SessionManager::resume` einmal je Anfrage
  (`AuthSession::$permissions`), `Application` nimmt sie von dort.
- Log-Mails: Empfänger unter einer Stadt sind Konten, deren Rolle `mail.log` hat
  (`PermissionRepository::grantsActionSql`, wie `Permissions::can`), statt Kontoart `admin`; dazu
  wie bisher alle Super-Admins, je aktiv, nicht gesperrt, Option an. Einschalten
  (`auth/notifications`, `UserRepository::setDeviceLogNotification`) nur mit `mail.log` bzw. als
  Super-Admin, Ausschalten immer. Für die Standard-Rollen dieselben Empfänger wie vorher (Abgleich
  mit der alten Abfrage im Test).
- Migrationen 0039 (alte CHECK `chk_fe_users_notify_device_log` entfernen) und 0040 (neue CHECK:
  0/1, nie bei `deleted`). Reine Lockerung, ändert keine Daten; jede Zeile der alten CHECK erfüllt
  die neue (per Test, auch über alle Kombinationen von Kontoart, Status und Wert).
- Tests: `SessionClassTest`, `SessionLifetimeTest`, `SessionManagerTest` (Standard-Rollen 7/30 und
  30/90 Tage, eigene Rolle, strengere Rolle), `LoginTest`, `SessionEndpointsTest`
  (`permissions`), `PermissionRepositoryTest` (Rolle, Filter, SQL gleich `can`),
  `UserRepositoryTest` (Empfänger wie vorher, eigene Rolle mit `mail.log`), `UserSchemaTest`,
  `NotifyDeviceLogCheckMigrationTest`.
- Dokumentiert in `CLAUDE.md`, "Benutzer und Anmeldung" (Sitzungsdauer, Benachrichtigung „Log“),
  "Anmeldung und Sitzungen im Code", "Rollen und Rechte", "Benachrichtigung „Log“".

## 2026-11-05 – „Rechte: Routen unter Städten prüfen die Rolle statt fe_users.role (Phase 1, Schritt 1.3a)“

Keine sichtbare Änderung der API (Routen, Felder, Antworten, Fehlercodes unverändert); geändert hat
sich, WIE `Application` den Zugriff unter einer Stadt prüft (Plan Phase 1, Abschnitte 2 und 3):

- Statt `Access::Admin`/`Access::Authenticated` deklariert jede Route unter einer Stadt ein
  `Permission\Requirement` (Bereiche mit Stufe, Aktionen, Feldregeln); `Access::Admin` gibt es nicht
  mehr. `Access::Authenticated` bleibt nur für `auth/me`, `auth/sessions…`, `auth/password` und
  `client-config`. Geprüft nach der Sitzung, vor dem Handler; abgelehnt wie bisher 403 `forbidden`.
- Rechte aus der Rolle des Kontos (`fe_users.role_id`, `PermissionRepository`); die Rolle *Admin*
  hat alle Rechte, *Benutzer* `search`/`due` Ansehen, `book.move`, `book.status`. Damit antwortet
  jede Route für beide Standard-Rollen genau wie vorher (per Test je Route und Fall).
- `GET device-summary`: `notices` jetzt bei `log` Ansehen statt bei Rolle `admin` (für die
  Standard-Rollen gleich).
- Exporte (`devices/export`, `device-events/export`) bewusst wie bisher für jedes Konto, das Geräte
  sieht (F2 „Benutzer ohne CSV-Export“ kommt mit Schritt 1.7, Aktion `csv.export`).
- Neue Routen unter Städten in `TenantRoutePermissionsTest` eintragen (Tabelle Route → bisherige Stufe
  → Erfordernis), sonst scheitert der Test.
- Tests: `TenantRoutePermissionsTest`, `AccessControlTest`, `RouterTest`, `PermissionRepositoryTest`,
  `tests/Unit/Permission/PermissionsTest`; `AdminRoutesAccessTest` und `TenantReadRoutesAccessTest`
  unverändert grün.
- Dokumentiert in `CLAUDE.md`, "HTTP und REST-API" (Zugriffsregeln) und "Rollen und Rechte".

## 2026-11-04 – „Sperre für veraltete App-Versionen: client_outdated und X-Api-Version (Phase 0)“

Umsetzung des beschlossenen Plans „Versionierung der API für das Frontend“ (Änderungskonzept
2026-11, Abschnitt 9 „Alte App-Versionen“, Phase 0); bestehende Routen und Felder unverändert:

- `ClientApi::MIN` (= MIN_CLIENT_API, Start 1, also vorerst ohne Wirkung) und
  `ClientApi::isOutdated`/`fromHeader`: Ist `X-Client-Api` eine Nummer (nur Ziffern ohne führende
  Null, höchstens 9 Stellen) kleiner als `MIN`, antwortet `Application` mit 409 `client_outdated`
  (`HttpException::clientOutdated`, `details {min_client_api}`, Meldung „Diese Version der App ist
  veraltet – bitte aktualisieren.“). Geprüft nach `RequestGuard` und VOR dem Laden der Stadt, also
  ohne Datenbank, ohne Sitzung, ohne Handler. Unbekannte Adressen (404), falsche Methode (405) und
  abgelehnte ändernde Anfragen (415/403/400) bleiben wie bisher. Ohne Header oder mit einem Wert, der
  keine Nummer ist, gilt die Anfrage als aktuell (curl, Deploy-Skript, ältere Apps).
  `Application` nimmt optional `minClientApi` (nur für Tests).
- Neuer Header `X-Api-Version: <ClientApi::CURRENT>` (derzeit 1) an JEDER JSON-Antwort
  (`JsonResponse::DEFAULT_HEADERS`, auch Fehler und `health`) und jedem CSV-Download
  (`CsvResponse`). Nicht an `CacheableResponse` (Logo, Symbol, Manifest, Dateien des Frontends).
- Warum: Rechte und neue Status (Phase 1 ff.) ändern die Bedeutung der API; ein Handy mit alter App
  darf dann nicht falsch buchen. Die App prüft bei `client_outdated` bzw. größerer `X-Api-Version`
  sofort auf ein Update; Buchungen der Warteschlange mit `client_outdated` warten und gehen nach dem
  Update raus.
- Tests: `ClientApiTest` (Nummern, Grenze), `ApplicationTest` (409 ohne Datenbank und Handler,
  aktuelle/fehlende/ungültige Nummer, eigene Grenze, 404/405/415 unverändert, `X-Api-Version` an
  allen Antworten), `HttpExceptionTest`, `JsonResponseTest`, `CsvResponseTest`, `DeviceExportTest`,
  `FrontendDeliveryTest` (Dateien des Frontends ohne `X-Api-Version`).
- Dokumentiert in `CLAUDE.md`, "HTTP und REST-API" (Reihenfolge, Fehlercodes, Header,
  Versionierung).

## 2026-11-01 – „Offline: Warteschlange nach Verbindungsabbruch mit derselben client_ref und Aktionszeit“

Erweiterung der Wiederholung bei `POST api/v1/devices/{id}/state` (sonst unverändert): Eine Buchung
MIT `occurred_at` wiederholt jetzt auch eine schon gespeicherte Buchung OHNE `occurred_at`, wenn
`client_ref`, Konto, Gerät, Version, Standort, Status und Kommentar gleich sind (bisher 409
`client_ref_conflict`, weil die Aktionszeit in `request_hash` steckt). Antwort wie jede Wiederholung
(`replayed: true`, gespeichertes Ergebnis, aktueller Gerätestand, nichts geschrieben, keine Mail,
auch nach den Zeitgrenzen). Umgekehrt (gespeichert mit Aktionszeit, gesendet ohne oder mit anderer)
bleibt es ein Konflikt. Code: `BookingRequest::onlineHash` (Hash derselben Buchung ohne
Aktionszeit, nur bei Offline-Buchungen), `BookingRequest::$onlineHash` (optional),
`DeviceBooker::previous(…, $onlineHash)` und die Wiederholung bei gleichzeitiger Buchung.

- Warum: Scheitert „Speichern“ online an der Verbindung, legt die App die Buchung mit derselben
  `client_ref` und der Zeit des ersten Versuchs in die Warteschlange (Entscheidung Holger,
  01.11.2026; KONZEPT.md Entscheidung 52). Kam der erste Versuch doch an, wirkt die Buchung so nicht
  doppelt; kam er nicht an, gilt die Aktionszeit des ersten Versuchs.
- Tests: `DeviceOfflineBookingTest::testQueuedRetryOfOnlineBookingIsReplayed` (vorher rot).
- Dokumentiert in `CLAUDE.md`, "Offline-Buchungen (API)", Wiederholung.

## 2026-10-31 – „Admin-Konsole: Protokoll mit Hinweisen und Export des Verlaufs (Schritt 11)“

Ein neues Feld, sonst unverändert: Jeder Hinweis (`GET notices`, `GET notices/{id}`, Antworten von
`resolve`/`reopen`, `NoticeJson::notice`) enthält in `booking` zusätzlich `comment`, den Kommentar
der Buchung (aus ihrem Eintrag im Verlauf, `fe_device_events.comment` über `event_id`; `null` ohne
Kommentar). `DeviceNoticeRepository` liest ihn per `LEFT JOIN fe_device_events` (mit Stadt),
`DeviceNotice::$comment` (letzter Parameter, Standard `null`).

- Warum: Die Seite `protokoll/<id>` der Admin-Konsole zeigt die Buchung vollständig wie die
  Hinweis-Mail (dort stand der Kommentar schon); gerade bei „defekt“ erklärt er den Wunsch.
- Die App nutzt außerdem erstmals `POST notices/{id}/resolve` und `reopen` sowie
  `GET device-events/export` (Export des Verlaufs mit `from`, `to`, `device_type_id`, `location_id`
  mit `include_sublocations=1` und `order`); beide unverändert.
- Tests: `DeviceNoticeTest::testOverwrittenStateCreatesNoticeWithBothStates` (Kommentar),
  `testWhichResultsCreateNotices` (ohne Kommentar `null`).
- Dokumentiert in `CLAUDE.md`, "Hinweise (API)", und README "Hinweise (nur Admins)".

## 2026-10-31 – „Admin-Konsole: Sammelaktionen Prüfdatum und Umbuchen/Status (Schritt 10)“

Zwei neue ändernde Endpunkte, nur Admins der Stadt (`Access::Admin`, in `AdminRoutesAccessTest`),
KONZEPT.md Entscheidung 31; bestehende Routen und Felder unverändert:

- `POST api/v1/devices/bulk/inspection-due {devices: [{id, version, next_inspection_due}], comment?}`
  und `POST api/v1/devices/bulk/state {devices: [{id, version, location_id?, status?, client_ref?}],
  comment?}` (`DeviceBulkHandler` mit `DeviceBulkAction`). Höchstens 50 Geräte je Anfrage
  (`DeviceBulkHandler::MAX_DEVICES`, Body-Grenze bleibt 16 KiB). Felder je Eintrag wie bei
  `devices/{id}/inspection-due` bzw. `devices/{id}/state` ohne `occurred_at`/`confirmed_stale`
  (`DeviceFields::bulkEntries`, `bulkInspectionDue`, `bulkStateChange`, `deviceId`); alle Einträge
  werden vor der ersten Änderung gelesen, ungültig (Meldung „Eintrag N in "devices": …“), leer, mehr
  als 50 oder ein Gerät doppelt → 400, nichts geändert.
- Je Gerät eine eigene Transaktion und ein eigener Eintrag im Verlauf (gemeinsamer Kommentar), Regeln
  wie bei der einzelnen Route; Teilerfolg erlaubt. Antwort immer 200 `{results: [{id, outcome,
  replayed, device, error}], summary: {total, applied, unchanged, errors}}`: `outcome` `applied`,
  `unchanged` oder `error`; `device` = Stand danach (bei Fehler der aktuelle, `null` bei fremder oder
  unbekannter ID); `error` = `{error, message, details?}` wie die Fehlerantwort der einzelnen Route
  (z. B. `version_conflict` mit `details.device`, `rule_violation`, `not_found`, `invalid_request`
  bei unbekanntem Ziel-Standort, `client_ref_conflict`); `replayed` = dieselbe `client_ref` war schon
  angekommen (nur `state`).
- Neu `src/Device/Booking/DeviceBooker` (Wiederholung über die `client_ref`, gleichzeitig vergebene
  `client_ref`), jetzt auch von `DeviceStateHandler` benutzt (Verhalten unverändert).
- Benachrichtigung „Log“: EINE Sammelmail je Empfänger und Anfrage (`DeviceLogNotifier::bulkChanged`,
  `MailTemplates::deviceBulkLog`), Betreff „Log <Stadt>: 12 Geräte – Prüfdatum geändert“ bzw.
  „… – umgebucht, Status defekt“, je Gerät Kennung (Typ) und Von/Nach, Link für Admins auf
  `geraete`; nur ein geändertes Gerät → gewöhnliche Mail zum Gerät; nichts geändert → keine Mail.
- Tests: `tests/Integration/Device/DeviceBulkTest`, `DeviceBookingClientRefTest`
  (`testBookerRepeatsResultOfConcurrentRequest`), `DeviceLogNotificationTest` (Sammelmail),
  `tests/Unit/Mail/MailTemplatesTest` (`testDeviceBulkLogListsEveryDevice`), `AdminRoutesAccessTest`.
- Dokumentiert in `CLAUDE.md`, "Geräte (API)", "Offline-Buchungen (API)", "Benachrichtigung „Log“"
  und "Frontend" (Sammelaktionen der Geräteliste); README "Geräte" und "Benachrichtigung".

## 2026-10-31 – „CI: je Push nur betroffene Tests, nachts alles; Agenten committen selbst“

Keine Änderung an der API. Für den Backend-Chat wichtig:

- PHP-Tests laufen in CI nur noch, wenn ein Push PHP-Code, PHP-Tests oder Fixtures, Migrationen,
  `bin/`, `scripts/`, `config/`, `resources/`, `public/`, Composer oder die vom Backend abgeglichenen
  Dateien des Frontends ändert (Regeln in `frontend/build/ciScope.ts`); Browser-Tests nur bei
  Änderungen an der App, an der Auslieferung (`src/Http/`, `public/`, Composer) oder an
  `tests/Fixtures/*.json`. Jede Nacht läuft alles (`schedule`), ohne `deploy` zu ändern.
- Lokal `composer test` nur bei solchen Änderungen (CLAUDE.md, "Arbeitsweise").
- Agenten committen nach „✅ FERTIG“ selbst; gepusht wird gesammelt vom Nutzer.
- Tests: `frontend/tests/unit/ciScope.spec.ts`.
- Dokumentiert in `CLAUDE.md`, "Arbeitsweise" und "GitHub Actions (CI)", README "GitHub Actions und
  Branch deploy".

## 2026-10-30 – „Admin-Konsole: CSV-Import mit Vorlage, Vorschau und Vorschlägen (Schritt 7); CI in acht Teilen“

Zwei neue lesende Endpunkte (nur Admins der Stadt, `Access::Admin`, CSV-Download wie der Export
über `CsvResponse`, Format `CsvFormat`; `DeviceImportTemplateHandler`, Query-Parameter ignoriert):

- `GET api/v1/devices/import/template`: Vorlage mit den Spalten des Imports (= `DeviceCsv::DEVICE_HEADER`,
  `DeviceImportTemplate::header()`, per Test) und zwei Beispielzeilen `BEISPIEL-1` (erster aktiver
  Gerätetyp, erster aktiver Standort der Ebene 1, einsatzbereit, Prüfung heute + 365 Tage,
  Seriennummer, Hersteller, Modell, Beschaffung heute, Notiz „Beispielzeile – vor dem Import
  löschen“) und `BEISPIEL-2` (zweiter Gerätetyp, erstes aktives Fach, in Wartung). Ohne aktive Typen
  bzw. Standorte bleiben die Zellen leer. Dateiname `geraete-vorlage-<kürzel>.csv`.
- `GET api/v1/devices/import/values`: „Gültige Werte“, Spalten `Spalte;Gültiger Wert`: Kennung
  (Regel), aktive Gerätetypen, aktive Standorte als Pfad wie im Import (Fächer nur unter aktiven
  Standorten), die drei Status, Datumsformat für „Nächste Prüfung“ und „Beschaffungsdatum“.
  Dateiname `gueltige-werte-<kürzel>-<JJJJ-MM-TT>.csv` (heute über `Clock`).

Vorschau (`POST devices/import/preview`) und Übernehmen erweitert, bestehende Felder unverändert:

- Neues Feld `suggestions {device_types: [{value, suggestions}], locations: [...]}`: zu unbekannten
  Gerätetypen bzw. Standorten (`unknown`, unverändert) die ähnlichsten AKTIVEN Werte, nur Einträge
  mit Vorschlag, Reihenfolge wie `unknown`. Die Meldung der Zeile endet dann mit „Meinten Sie
  „HLF 20“?“ (bzw. „„LF 10“ oder „LF 16““). Ähnlichkeit (`ImportSuggestions`): Vergleichsform ohne
  Leerraum und Groß-/Kleinschreibung, ä/ö/ü/ß = ae/oe/ue/ss, Levenshtein über Unicode-Zeichen, höchstens
  ein Viertel der Länge (mindestens 1, höchstens 3), höchstens 3 Vorschläge (natürlich sortiert),
  höchstens 100 gesuchte Werte je Art; Fächer auch über ihren eigenen Namen („G1“ → „HLF 20 / G1“).
  Die Kandidaten werden nur gelesen, wenn es unbekannte Werte gibt.
- Neue Warnung je Zeile (neu oder bestehend, nicht bei Fehlern): Kennung `BEISPIEL-1`/`BEISPIEL-2`
  (ohne Groß-/Kleinschreibung, `DeviceImportTemplate::isExample`) „… gehört zu den Beispielzeilen der
  Vorlage. Bitte die Zeile vor dem Import löschen.“ Zählt in `summary.warnings`, blockiert nicht.
- Ein bisheriger Test erwartete für „HLF 20 / G9“ die Meldung ohne Vorschlag; jetzt mit
  „Meinten Sie „HLF 20 / G1“?“.
- Code: `src/Device/Import/DeviceImportTemplate.php`, `ImportSuggestions.php`,
  `UnknownImportValue.php` (neu; `DeviceImportPlan::$unknownDeviceTypes`/`$unknownLocations` sind
  jetzt `list<UnknownImportValue>`), `DeviceImportPlanner`, `DeviceImportJson`, Routen in `Routes::tenant`.
- Tests: `tests/Integration/Import/DeviceImportTemplateTest` (Vorlage, leere Stadt, Vorlage als
  Import mit Warnungen, Warnung bei bestehendem Gerät, gültige Werte nur aktive und eigene Stadt,
  Vorschläge samt deaktiviertem Typ und Gleichstand), `tests/Unit/Device/Import/ImportSuggestionsTest`,
  `DeviceImportPreviewTest` (angepasst), `AdminRoutesAccessTest` (zwei Einträge; liest Antworten
  jetzt über `sendAny`, auch CSV).
- CI: Browser-Tests in acht statt vier Teilen (`ci.yml`, Matrix `shard` 1–8); sonst unverändert.
- Dokumentiert in `CLAUDE.md`, "CSV-Import (API)" und "Frontend" (Import-Seite), "GitHub Actions (CI)".

## 2026-10-30 – „Admin-Konsole: Menü und Dashboard, Verlauf der Stadt (Schritt 1)“

Neuer lesender Endpunkt `GET api/v1/device-events` (nur Admins der Stadt, `Access::Admin`;
`DeviceHistoryListHandler`): die Historie der ganzen Stadt als JSON, alle Geräte (auch
ausgesonderte), neueste zuerst nach `created_at`, dann `id` (wie der CSV-Export mit
`order=newest`, nutzt `idx_fe_device_events_tenant`). Parameter `limit` 1–200 (Standard 50) und
`offset` 0–1000000 wie bei `GET devices/{id}/events`; keine Filter. Antwort `{events, total, limit,
offset}`, je Eintrag genau die Felder von `DeviceEventJson::event` plus `device {id, identifier,
device_type {id, name}}` mit dem aktuellen Stand des Geräts. Bestehende Antworten sind unverändert.

- Zweck: „Letzte Aktivitäten“ auf dem Dashboard der Admin-Konsole (KONZEPT.md, "Admin-Konsole –
  Entscheidungen", 35).
- Code: `DeviceEventRepository::listForTenant` (neu, nur lesend), `DeviceHistoryPage` (neu);
  `DeviceHistoryEntry` hat zusätzlich `deviceTypeId` (der Export liest dafür `t.id` mit, sonst
  unverändert).
- Tests: `DeviceHistoryListTest` (alle Geräte der Stadt, keine fremden, Reihenfolge bei gleicher
  Sekunde nach ID, aktuelle Kennung und aktueller Typ, gleiche Felder wie
  `devices/{id}/events`, Seiten, anonymisierte Konten, ungültige Parameter);
  `AdminRoutesAccessTest` (Eintrag „Verlauf der Stadt“).
- Dokumentiert in `CLAUDE.md`, "Geräte (API)".

## 2026-10-30 – „Code scannen (Schritt 9)“

Die REST-API ist unverändert; die App nutzt erstmals den vorhandenen Filter `GET devices?scan_code=…`
(Modus `server`, mehr als 2000 Geräte). Geändert hat sich die Auslieferung des Frontends:

- `FrontendAssets::CONTENT_TYPES` kennt `wasm` (`CacheableResponse::WASM` = `application/wasm`),
  aber nur unter `assets/` (`FrontendAssets::ASSETS_ONLY`; direkt im Build-Ordner 404), also immer
  `Immutable`. Die Datei ist das Lesemodul des Kamera-Scanners (zxing-wasm, rund 950 KB); der Build
  legt auch für sie `.br`/`.gz` daneben.
- CSP der App-Seite: `script-src 'self' 'wasm-unsafe-eval'` (nur das Übersetzen von WebAssembly,
  kein eval von JavaScript). Sonst unverändert.
- Neue gemeinsame Testfälle `tests/Fixtures/identifier-key-cases.json`: Kennung → `identifier_key`
  aus MariaDB (`IdentifierKeyCasesTest`, Integration) und für jeden Groß- und Titelbuchstaben das
  Ergebnis von `LOWER()` unter `utf8mb4_unicode_ci`. Die App vergleicht gescannte Kennungen damit
  wie der UNIQUE-Index. Wer `identifier_key` ändert, muss die Datei und `identifierKey` in
  `frontend/src/data/deviceSearch.ts` mitziehen.
- Tests: `FrontendDeliveryTest` (wasm in `assets/` 200, außerhalb 404), `FrontendAssetsTest`,
  `CacheableResponseTest`, `ContentSecurityPolicyTest::testScriptsOnlyFromSelfPlusWebAssembly`,
  `IdentifierKeyCasesTest`.
- Dokumentiert in `CLAUDE.md`, "Frontend ausliefern" und "Frontend" (Suchen, Code scannen).

## 2026-10-29 – „Umbuchen: Standortsuche als Schaltfläche, Zuletzt benutzt mit Änderungszeit“

`GET api/v1/devices` liefert je Gerät zusätzlich `state_changed_at` (UTC, Format wie alle Zeiten
`JJJJ-MM-TTTHH:MM:SSZ`): die Aktionszeit der letzten Änderung von Standort oder Status
(`fe_devices.state_changed_at`, bei Offline-Buchungen die Zeit auf dem Handy). Erzeugt in
`DeviceJson::listItem`; damit enthalten auch alle Antworten mit einem Gerät (`DeviceJson::device`:
`GET devices/{id}`, Anlegen, Ändern, Buchung, `details.device` bei `version_conflict`) das Feld.
Es kam nur ein Feld hinzu, kein bestehendes hat sich geändert; `DeviceJson::summary` (auch
`GET inspection-due`) bleibt unverändert.

- Zweck: Die App zeigt unter „Zuletzt benutzt“ je Gerät, wann es zuletzt umgebucht oder sein
  Status geändert wurde (egal von wem), z. B. „vor 5 Min.“ (KONZEPT.md, "User am Handy –
  Entscheidungen", 16). Stammdaten und Prüfdatum zählen bewusst nicht (deshalb nicht
  `updated_at`).
- Tests: `DeviceListTest::testListContainsTimeOfLastStateChange` (Wert aus der Spalte, Umbuchen
  setzt ihn neu und die Antwort der Buchung trägt denselben Wert wie danach die Liste, Stammdaten
  ändern ihn nicht); Feldlisten in `testUserSeesSummariesSortedNaturally` und
  `DeviceCreateTest` ergänzt.
- Dokumentiert in `CLAUDE.md`, "Geräte (API)".

## 2026-10-29 – „Datenschicht, Geräteliste mit Stammdaten (Schritt 2)“

`GET api/v1/devices` liefert je Gerät zusätzlich `serial_number`, `manufacturer` und `model`
(jeweils String oder `null`), erzeugt über die neue Funktion `DeviceJson::listItem` = `summary` +
diese drei Felder. Es kamen nur Felder hinzu, kein bestehendes hat sich geändert.
`DeviceJson::summary` (auch in `GET inspection-due`) bleibt unverändert, `DeviceJson::device` baut
jetzt auf `listItem` auf, seine Felder sind dieselben.

- Zweck: Die App lädt alle Geräte einer Stadt und sucht lokal auch in diesen Feldern (KONZEPT.md,
  "User am Handy – Entscheidungen", 20).
- Tests: `DeviceListTest::testListContainsSearchableMasterData`; die Liste der Felder in
  `testUserSeesSummariesSortedNaturally` ist ergänzt.
- Dokumentiert in `CLAUDE.md`, "Geräte (API)".
