# Live-Test: Zugang über Claude (MCP-Konnektor)

Nur in der Test-Stadt `https://www.prodad.de/firefighting/test/`, nie in echten Städten. Konzept:
[`../MCP-KONZEPT.md`](../MCP-KONZEPT.md), Plan: [`../PLAN-MCP.md`](../PLAN-MCP.md). Je Schritt wird
ergänzt, was live geprüft ist (☑ bestanden, ☒ Fehler, ☐ offen für Holger).

## Vorklärung (Runde 3, 07.11.2026)

Frage: Findet Claude die OAuth-Metadaten unter `/firefighting/test/` (Issuer
`https://www.prodad.de/firefighting/test`, Dokument unter
`…/firefighting/test/.well-known/openid-configuration`), ohne die `.htaccess` des Webspace?

### Vom Agenten geprüft (07.11.2026, Build `9a26dd0b30f4`, curl gegen den Server)

- ☑ Deploy endet mit „Deployment erfolgreich“, `build.json` = `9a26dd0b30f4`, `health` =
  `{"status":"ok","database":"ok"}`.
- ☑ Das Routing für `.well-known` unter `/firefighting/test/` funktioniert auf STRATO ohne Änderung
  der Webroot: `GET .well-known/oauth-protected-resource`, `.well-known/openid-configuration`,
  `.well-known/oauth-authorization-server` und `.well-known/jwks.json` liefern 200
  `application/json; charset=utf-8` mit den erwarteten Inhalten (`resource` =
  `https://www.prodad.de/firefighting/test/mcp`, Issuer `https://www.prodad.de/firefighting/test`,
  `code_challenge_methods_supported` `["S256"]`).
- ☑ `POST mcp` → 401 mit `WWW-Authenticate: Bearer
  resource_metadata="https://www.prodad.de/firefighting/test/.well-known/oauth-protected-resource",
  scope="read write"`; `GET mcp` → 405.
- ☑ `POST oauth/register` → 201 mit `probe-…`; `GET claude-verbinden?…` → JSON mit allen `checks`
  `true` (mit einer nachgebauten Anfrage).
- ☑ Nur Test-Stadt: `immendingen/.well-known/openid-configuration` und `POST immendingen/mcp` → 404;
  unter `service` 404; unbekannte `.well-known/…` (z. B. `change-password`) → 404 JSON statt App-Seite.
- ☑ Webroot unberührt: `https://www.prodad.de/.well-known/oauth-authorization-server/firefighting/test`,
  `…/openid-configuration/firefighting/test` und `…/oauth-protected-resource/firefighting/test/mcp`
  liefern die 404-Seite der Hauptseite (Varianten 1 und 2 der Spezifikation schlagen also fehl,
  Claude muss Variante 3 nehmen).
- ☑ Sperren der internen Ordner unverändert: `apps/firefighting-equipment/config/…`, `composer.json`,
  `.git/config` und `apps/_backup/` → 302 (Sperre der `.htaccess`), `…/var/mcp-probe/test.json` → 403.
  (`/firefighting/test/.git/config` liefert wie bisher die App-Seite, keine Datei.)
- ☑ `GET api/v1/mcp-probe` ohne Anmeldung 401; im Browser als Admin der Test-Stadt 200 mit den
  Abrufen von eben (User-Agent `curl/…` bzw. `agent-probe-curl` = Abrufe des Agenten).
- ☐ Ob **Claude selbst** die Variante findet, kann nur der Versuch von Holger zeigen (unten).

### Für Holger: Konnektor in claude.ai hinzufügen

1. In claude.ai: Einstellungen → Konnektoren → „Benutzerdefinierten Konnektor hinzufügen“.
2. Name: `Ausrüstung Test`, URL: `https://www.prodad.de/firefighting/test/mcp`. Keine Client-ID und
   kein Client-Secret eintragen (erweiterte Einstellungen leer lassen). „Hinzufügen“.
3. Beim Konnektor auf „Verbinden“ klicken.
4. **Erwartet, wenn alles klappt**: Es öffnet sich ein Fenster bzw. Tab mit
   `https://www.prodad.de/firefighting/test/claude-verbinden?...` und einer JSON-Anzeige, die mit
   `"vorklaerung": "Claude hat die Anmeldeseite der Test-Stadt erreicht …"` beginnt. Bitte darunter
   den Block `"checks"` ansehen (oder abfotografieren): erwartet sind alle Werte `true`
   (`client_id_from_registration`, `response_type_code`, `redirect_uri_allowed`, `pkce_s256`,
   `resource_matches`, `state_present`). Danach das Fenster schließen; in Claude erscheint ein Fehler
   („Verbindung fehlgeschlagen“ o. Ä.) – **das ist erwartet**, es gibt noch keine Anmeldung.
5. **Wenn kein solches Fenster erscheint**: die Fehlermeldung von Claude wörtlich notieren.
6. In jedem Fall danach (angemeldet als Admin der Test-Stadt) im Browser öffnen:
   `https://www.prodad.de/firefighting/test/api/v1/mcp-probe` – das zeigt, welche Adressen Claude
   abgerufen hat. Oder mir Bescheid geben, dann lese ich es aus.
7. Den Konnektor danach in claude.ai wieder entfernen (Einstellungen → Konnektoren → „…“ →
   „Entfernen“).
8. Optional, gleiche Probe mit Claude Code (Terminal):
   `claude mcp add --transport http ausruestung-test https://www.prodad.de/firefighting/test/mcp`,
   dann in Claude Code `/mcp` → `ausruestung-test` → „Authenticate“. Erwartet: Der Browser öffnet
   `…/claude-verbinden?…` mit `redirect_uri` `http://localhost:<port>/callback`, alle `checks` `true`.
   Danach `claude mcp remove ausruestung-test`.

Auswertung (vom Agenten nach Holgers Versuch):

- ☐ Claude ruft `POST mcp` auf und folgt `resource_metadata` (Protokoll: `mcp` mit Status 401, dann
  `protected-resource`).
- ☐ Claude findet die Metadaten über `openid-configuration` (Protokoll: Schritt
  `openid-configuration`; ob auch `oauth-authorization-server` abgerufen wurde, notieren).
- ☐ Claude registriert sich (`register`, `redirect_uris_allowed` true, `client_name`).
- ☐ Der Browser erreicht `claude-verbinden` mit PKCE S256, `resource` = MCP-Adresse, erlaubtem
  Rücksprung (`authorize`, alle `checks` true).
