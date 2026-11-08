# Live-Test: Zugang über Claude (MCP-Konnektor)

Nur in der Test-Stadt `https://www.prodad.de/firefighting/test/`, nie in echten Städten. Konzept:
[`../MCP-KONZEPT.md`](../MCP-KONZEPT.md), Plan: [`../PLAN-MCP.md`](../PLAN-MCP.md). Je Schritt wird
ergänzt, was live geprüft ist (☑ bestanden, ☒ Fehler, ☐ offen für Holger).

## Runde 7: Vermerk „über Claude“ in den Werkzeugen, Fehlerdetails, destructiveHint

Vom Agenten nach dem Deploy (Build `5c5609a24eea`, 08.11.2026; Chrome als Admin der Test-Stadt, curl):

- ☑ `build.json` = `5c5609a24eea`, `health` ok; Vermittler ohne Token 401 in 0,35 / 0,36 / 0,64 s,
  `…/ff-mcp.pl/immendingen` 404.
- ☑ Eintrag 257 (und 258) an M90: in der REST-API `channel` = `claude`; Detailseite und „Details und
  Verlauf“ der Handy-Seite „von Holger Burkarth · über Claude“; CSV-Export des Verlaufs (im Speicher
  gelesen, keine Datei) „Über Claude“ = „Ja“ bei beiden.
- ☐ Log-Mail zu 257 („Wer: … (über Claude)“): Mails kann der Agent nicht lesen (Holger).
- ☑ `get_equipment_history` an M90 zeigt `via_claude: true` bei 257 und 258; `version_conflict`
  liefert `details.equipment` mit `number` und `maintenance_due` (Holger mit Claude im Chat über den
  Konnektor, abgenommen 08.11.2026).
- ☐ Rückfrage nur noch vor aussondern, Import übernehmen, Standort sperren: nicht eigens geprüft.
- ☑ Service Worker (T15): die alte Version (`fa7433111922`) übernahm beim Seitenwechsel von selbst
  die neue; Vorab-Cache der Test-Stadt 51 Dateien mit einer CSS-Datei, Seiten der Konsole beim
  ersten Öffnen in `ff-assets` (Detailseite, Formulare).

## Runde 6: Werkzeuge (M5 Lesen, M6 Ändern)

MCP-Adresse unverändert `https://www.prodad.de/cgi-bin/ff-mcp.pl/test`. Ein bestehender Konnektor
zeigt die Werkzeuge nach „Trennen“ und erneutem „Verbinden“ bzw. beim nächsten Gespräch (Claude liest
`tools/list` beim Start der Verbindung neu).

### Vom Agenten nach dem Deploy (curl, ohne Anmeldung)

M5 (Build `05f594fa6c94`, 07.11.2026):

- ☑ `build.json` = `05f594fa6c94`, `health` `{"status":"ok","database":"ok"}`.
- ☑ Vermittler `POST tools/list` ohne Token: 401 in 0,41 / 0,41 / 1,01 s (drei Messungen).
- ☑ Vermittler `tools/call` mit falschem Token `ffa_A…`: 401, `WWW-Authenticate: Bearer
  error="invalid_token", resource_metadata="…/firefighting/test/.well-known/oauth-protected-resource",
  scope="read write"`, Body `not_authenticated`.
- ☑ direkt `…/firefighting/test/mcp` ohne Token: 401 in 0,35 s; `GET` am Vermittler 405;
  `…/ff-mcp.pl/immendingen` 404 (kein Claude-Zugang außerhalb der Test-Stadt).
- ☑ `.well-known/oauth-protected-resource` nennt `resource` = Adresse des Vermittlers.
- ☐ Werkzeuge mit echtem Token: nur Holger (Anmeldung mit Passwort, Agenten melden sich nicht an).

M6 (Build `573e164979a3`, 07.11.2026; Migrationen 0047/0048 nach Sicherung
`ff-db-20261107-161600Z-vor-migration-42858fbb.sql.gz`):

- ☑ `build.json` = `573e164979a3`, `health` ok, App-Seite `test` 200.
- ☑ Vermittler ohne Token 401 in 0,35 / 0,36 / 0,37 s; `tools/call book_equipment` mit falschem Token
  401 in 0,36 s (keine Änderung).
- ☑ Body von 600 KB (`preview_import`) geht durch den Vermittler und `RequestGuard` (401 in 0,83 s,
  vorher 16 KiB-Grenze).

### Von Holger (Claude im Chat, Admin-Konto, Zugang „Ändern“; abgenommen 08.11.2026)

- ☑ `get_overview`, `list_due` und `get_equipment` (M7) stimmen mit der App überein.
- ☑ M90 per `book_equipment` auf „in Wartung“ und zurück: beide übernommen, zurückgesetzt, Version
  danach 18.
- ☑ `book_equipment` mit veralteter Version → `version_conflict`, nichts gebucht.
- ☒ `get_equipment_history` (und `list_city_history`) zeigten den Vermerk „über Claude“ nicht (Eintrag
  257 an M90). Behoben in Runde 7 (`via_claude: true`, Text „… davon über Claude.“).
- ☐ Punkte 1–16 unten (als *Benutzer* und mit Datenfilter): für später offen (Holger, 08.11.2026).

### Für Holger: in claude.ai prüfen

Vorbereitung (Benutzerverwaltung der Test-Stadt, als Admin):

- Konto **Holger (Test)** (Rolle *Benutzer*): Claude-Zugang zuerst **„Lesen“**.
- Eine Rolle mit Datenfilter: als Super-Admin im Stadtdetail „Test-Feuerwehr“ → Reiter „Rollen“ →
  „Rolle aus anderer Stadt übernehmen …“ oder „Neue Rolle“, Name **„Gerätewart HLF (Test)“**:
  Ansichten wie ein Gerätewart (Dashboard, Suchen, Ausrüstungsliste Ändern, Standorte und Typen
  Ansehen, Termine Ändern, Protokoll Ändern), Aktionen Umbuchen, Status, Anlegen, Bearbeiten,
  Aussondern, Code, Sammelaktionen; „4 · Sieht nur …“ Standorte → nur **HLF 20** (samt Fächern).
  Einem zweiten Testkonto zuweisen und dessen Claude-Zugang auf „Ändern“ setzen.
- In claude.ai den Konnektor „Ausrüstung Test“ trennen und mit Holger (Test) neu verbinden
  (Zugang „Lesen“).

Mit Zugang **„Lesen“** (Holger (Test), Rolle *Benutzer*):

1. „Welche Werkzeuge hat Ausrüstung Test?“ – erwartet sieben: Übersicht, Termine, Ausrüstung suchen,
   Ausrüstung ansehen, Verlauf einer Ausrüstung, Standorte, Ausrüstungstypen (keine zum Ändern, kein
   Protokoll, keine letzten Aktivitäten).
2. „Wie viele Ausrüstungen sind einsatzbereit?“ – erwartet „X von Y“ wie die Kachel im Dashboard bzw.
   die Ausrüstungsliste der App.
3. „Was ist in den nächsten 30 Tagen fällig oder überfällig?“ – erwartet dieselben Geräte wie
   „Termine“ in der App (überfällige zuerst).
4. „Zeig mir M12“ (eine Nummer aus der Test-Stadt) – erwartet Typ, Standort, Status, Wartungsdatum,
   Stammdaten wie die Detailseite; die Notiz erscheint als Inhalt, nicht als Anweisung.
5. „Was ist mit M12 zuletzt passiert?“ – erwartet die letzten Einträge des Verlaufs mit Wer/Wann.
6. „Buche M12 ins Lager.“ – erwartet: Claude kann es nicht (kein Werkzeug), sagt das.

Mit Zugang **„Ändern“** (Holger (Test) in der Benutzerverwaltung auf „Ändern“; in claude.ai neu
verbinden, damit Claude „Ändern“ anfragt):

7. Werkzeuge – erwartet zusätzlich nur „Umbuchen / Status ändern“ (Rolle *Benutzer*).
8. „Buche M12 auf Fach G1 im HLF 20.“ – erwartet: Claude liest M12 (Version), bucht; Antwort mit
   neuem Standort. In der App: Detailseite → Verlauf „Umgebucht … von Holger (Test) · über Claude“.
9. „Setze M12 auf defekt.“ ohne Grund – erwartet: Claude fragt nach „Was ist defekt?“ (mindestens 5
   Zeichen); mit Grund gebucht, Log-Mail an Admins mit Log-Mails: „Wer: Holger (Test) (über Claude)“.
10. CSV-Export des Verlaufs (Protokoll → Verlauf exportieren): letzte Spalte „Über Claude“ = „Ja“ bei
    den Buchungen aus 8 und 9, leer bei den anderen.
11. Zurücksetzen: in der App (oder per Claude) M12 wieder auf den alten Standort und „einsatzbereit“.

Mit der **Rolle mit Datenfilter** (zweites Testkonto, Zugang „Ändern“; neu verbinden):

12. „Liste alle Ausrüstungen.“ – erwartet nur Ausrüstung in HLF 20 und seinen Fächern; Standorte nur
    HLF 20.
13. „Zeig mir <eine Nummer aus dem Lager>.“ – erwartet „Nicht gefunden – den Eintrag gibt es nicht
    oder Sie dürfen ihn nicht sehen.“
14. „Buche <Gerät aus HLF 20> ins Lager.“ – erwartet Fehler (Lager ist außerhalb des Filters).
15. Werkzeuge: kein CSV-Import, kein Anlegen/Sperren von Standorten oder Typen (F3).
16. „Lege M98 als Atemschutzmaske in HLF 20 / G1 an“, danach „Sondere M98 aus“ – erwartet:
    Claude fragt vor dem Aussondern nach (Hinweis „destructive“), danach ausgesondert; Verlauf „über
    Claude“.

Zurücksetzen danach: M98 bleibt ausgesondert (Testgerät, Kennungen M90–M99), geänderte Geräte wie in 11
zurück, Claude-Zugang beider Testkonten wieder „Aus“ (trennt die Verbindungen), die Rolle mit
Datenfilter löschen (Super-Admin, erst nach dem Entzug der Rolle beim Testkonto).

## Runde 5: OAuth, mcp und „Claude verbinden“ (M3, M4)

Stand: deployt am 07.11.2026 (Build `1a7d06d06086`, Migrationen 0045/0046 mit Sicherung vorher).

MCP-Adresse für Claude seit dem Vermittler (CLAUDE.md "Vermittler für Claude (cgi-bin)"):
`https://www.prodad.de/cgi-bin/ff-mcp.pl/test`, nicht mehr `…/firefighting/test/mcp`.

### Vermittler im cgi-bin (07.11.2026, Build `fa7433111922`)

Holger hat `scripts/cgi-bin/ff-mcp.pl` (755, binär, unverändert) und `.htaccess` mit `CGIPassAuth On`
ins `cgi-bin` geladen, `ff-auth-probe.pl` gelöscht. Vom Agenten per curl bestätigt (Claude im Chat
hatte die ersten fünf Punkte schon geprüft):

- ☑ Ohne Stadt, `/immendingen`, `/service`, `/test/x`, `/TEST` → 404 (`not_found` im Fehlerformat).
- ☑ `GET` und `PUT …/ff-mcp.pl/test` → 405 mit `Allow: POST`, `Cache-Control: no-store`,
  `X-Content-Type-Options: nosniff`; `DELETE` geht weiter an die App → 405 der App.
- ☑ `POST` ohne Token → 401 mit `WWW-Authenticate: Bearer resource_metadata="https://www.prodad.de/
  firefighting/test/.well-known/oauth-protected-resource", scope="read write"`.
- ☑ `POST` mit falschem Token (`ffa_` + 43 Zeichen) → 401 mit `error="invalid_token"`: Der Header
  `Authorization` kommt über den Vermittler bei der App an (direkt an `…/firefighting/test/mcp`
  weiterhin ohne `error="invalid_token"`, wie erwartet).
- ☑ Ein vom Client selbst gesendetes `X-FF-Authorization` gibt der Vermittler nicht weiter (401 ohne
  `error="invalid_token"`).
- ☑ Body: 20 KiB kommen bei der App an (400 „höchstens 16384 Byte“ der App), über 1 MiB lehnt der
  Vermittler ab (400 „höchstens 1 MiB“).
- ☑ `oauth-protected-resource`: `resource` = `https://www.prodad.de/cgi-bin/ff-mcp.pl/test`,
  `authorization_servers` = `["https://www.prodad.de/firefighting/test"]`.
- ☑ `oauth/register` (localhost-Rücksprung, Name „Agent-Pruefung Vermittler“, nie genutzt, wird nach
  24 Stunden aufgeräumt) und `api/v1/oauth/authorize/check` mit `resource` = Adresse des
  Vermittlers → 200; mit der alten Adresse `…/firefighting/test/mcp` → 400 mit Rücksprung
  `error=invalid_target`.
- ☑ Ein echtes Zugriffstoken über den Vermittler (`initialize`, `tools/list`): bestätigt durch
  Holgers Verbindungen mit claude.ai und Claude Code (unten).

### Vom Agenten nach dem Deploy (curl und Chrome, nur Test-Stadt)

Geprüft am 07.11.2026, Build `1a7d06d06086`:

- ☑ `GET …/test/.well-known/openid-configuration` (und `oauth-authorization-server`, gleiches
  Dokument): `revocation_endpoint`, `token_endpoint` `…/test/oauth/token`, `authorization_endpoint`
  `…/test/claude-verbinden`; `oauth-protected-resource` mit `resource` `…/test/mcp`; `jwks.json`
  `{"keys":[]}`.
- ☒ `POST …/test/mcp` ohne Token → 401 mit `WWW-Authenticate` ☑, `GET …/test/mcp` → 405 mit `Allow:
  POST` ☑; aber mit falschem Token (auch mit gültigem Format `ffa_` + 43 Zeichen) fehlt
  `error="invalid_token"`: Der Header `Authorization` kommt auf STRATO nicht bei PHP an (PHP als CGI).
  Damit könnte Claude `mcp` nie mit dem Zugriffstoken aufrufen. Korrektur: Commit „Authorization auch
  aus REDIRECT_HTTP_AUTHORIZATION lesen (Runde 5, Live-Test)“. Live mit Build `c05001794be8`
  weiterhin ☒: STRATO setzt auch `REDIRECT_HTTP_AUTHORIZATION` nicht. Nötig ist eine Regel in
  `public/.htaccess` (Rückfrage an Holger, 07.11.2026); bis dahin sind die Punkte für Holger ab
  Schritt 3 („Verbinden“) nicht sinnvoll. Mit `SetEnvIf Authorization "(.+)"
  HTTP_AUTHORIZATION=$1` (Build `bc86550f60e1`, freigegeben von Holger) weiterhin ☒: 401 ohne
  `error="invalid_token"`; health, Test-Stadt und Immendingen (Startseite, `api/v1/tenant`) ☑ 200,
  kein 500. Vermutlich ist `mod_setenvif` nicht geladen (dann überspringt `<IfModule>` die Regel
  still), oder der Header erreicht Apache gar nicht. Nächster Schritt: Rückfrage an Holger.
  Mit `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` statt `SetEnvIf` (Build
  `d50363bd1376`, freigegeben von Holger) ebenfalls ☒: 401 ohne `error="invalid_token"`; health,
  Test-Stadt (Startseite, `geraete/12`, `api/v1/tenant`) und Immendingen (Startseite,
  `api/v1/tenant`) ☑ 200, kein 500. Drei Versuche gescheitert (Code, `SetEnvIf`, `RewriteRule`),
  angehalten; Ursache auf dem Server noch unklar (Header erreicht Apache nicht, oder STRATO entfernt
  die Variable vor PHP).
  Prüf-Route `GET …/test/api/v1/auth-probe` (Build `74cb075a94a2`): mit `Authorization: Bearer
  test123`, ohne Header und mit Basic (`curl -u`) jeweils `{"sapi":"cgi-fcgi","gateway_interface":
  "CGI/1.1","server":[],"env":[]}`: keine einzige Variable mit „AUTH“ bei PHP (auch kein
  `PHP_AUTH_USER`), trotz `RewriteRule [E=HTTP_AUTHORIZATION:…]`. `…/immendingen/api/v1/auth-probe`
  → 404 ☑. Nächster Schritt: Rückfrage an Holger (`CGIPassAuth` oder STRATO-Support).
  Mit `CGIPassAuth On` und `E=FF_AUTH_SEEN:1` in der Rewrite-Regel (Build `16692f71c6c7`, freigegeben
  von Holger): health, Test-Stadt und Immendingen (Startseite, `api/v1/tenant`, nur lesend) ☑ 200,
  kein 500 (die Direktive ist also erlaubt). `auth-probe` mit Bearer, mit `curl -u` und ohne Header
  weiterhin `{"sapi":"cgi-fcgi","gateway_interface":"CGI/1.1","server":[],"env":[]}` ☒: auch
  `FF_AUTH_SEEN` (bzw. `REDIRECT_FF_AUTH_SEEN`) fehlt. Entweder erreicht der Header Apache nicht
  (ein vorgeschalteter Proxy entfernt ihn, die `RewriteCond` greift nie), oder Variablen aus der
  `.htaccess` kommen bei PHP gar nicht an. `CGIPassAuth` wieder entfernt, Rückfrage an Holger
  (STRATO-Support).
  Gelöst über den Vermittler im cgi-bin (siehe oben): ☑ `error="invalid_token"` über
  `…/cgi-bin/ff-mcp.pl/test`.
- ☑ `GET …/test/api/v1/mcp-probe` → 404; `POST …/immendingen/mcp` und
  `…/immendingen/.well-known/openid-configuration` → 404.
- ☑ Zusätzlich per curl: `POST oauth/register` mit claude.ai-Rücksprung → 201, mit fremder Adresse →
  400 `invalid_redirect_uri`; `POST api/v1/oauth/authorize/check` → 200 (Stadt „Test-Feuerwehr“, Ziel
  `claude.ai`, angefragt „Ändern“), mit nicht registrierter Adresse → 400 „Die Rücksprung-Adresse ist
  für diese Anwendung nicht registriert.“ ohne `redirect_url`; `POST oauth/token` mit falschem Code →
  400 `invalid_grant`; `POST oauth/revoke` → 200.
- ☑ `claude-verbinden?client_id=unbekannt` im Browser: „Diese Anwendung ist bei der Stadt nicht
  registriert. Bitte die Verbindung in Claude neu starten.“, kein Knopf „Zurück zu Claude“, kein
  Formular, keine Konsolenfehler.
- ☑ Einstellungen → „Angemeldete Geräte“ ohne Verbindung unverändert (nur Sitzungen, keine
  Claude-Einträge, keine Konsolenfehler).

### Für Holger: claude.ai verbinden

Ergebnis (Holger, 07.11.2026, über `https://www.prodad.de/cgi-bin/ff-mcp.pl/test`): ☑ alle
Schritte 1–10 wie erwartet („alles funktioniert“).

Vorbereitung: In der Benutzerverwaltung der Test-Stadt einem Testkonto, dessen Passwort Sie kennen
(z. B. „Holger Test 1“), den Claude-Zugang „Lesen“ geben. Einen alten Konnektor „Ausrüstung Test“ in
claude.ai vorher entfernen (Einstellungen → Konnektoren → „…“ → „Entfernen“).

1. claude.ai: Einstellungen → Konnektoren → „Benutzerdefinierten Konnektor hinzufügen“, Name
   `Ausrüstung Test`, URL `https://www.prodad.de/cgi-bin/ff-mcp.pl/test`, erweiterte Einstellungen leer
   → „Hinzufügen“ → „Verbinden“.
   - Erwartet: Fenster `https://www.prodad.de/firefighting/test/claude-verbinden?…` mit Logo bzw.
     Name der Test-Stadt, Überschrift „Claude verbinden“, „Claude möchte in Ihrem Namen …“, Stadt =
     Name der Test-Stadt, Ziel `claude.ai`, angefragter Zugang „Ändern“.
2. E-Mail und Passwort des Testkontos → „Anmelden und fortfahren“.
   - Erwartet: „Angemeldet als Holger Test 1. Claude bekommt Zugang: Lesen.“ (höchstens der Zugang
     des Kontos), Knöpfe „Verbinden“ und „Ablehnen“.
3. „Verbinden“.
   - Erwartet: zurück zu claude.ai, der Konnektor ist verbunden; er zeigt **keine Werkzeuge**
     (tools/list ist leer, Werkzeuge kommen mit M5). Eine Frage wie „Welche Werkzeuge hat Ausrüstung
     Test?“ ergibt: keine.
4. Postfach des Testkontos.
   - Erwartet: Mail „Neue Claude-Verbindung“ von „Feuerwehr Ausrüstungsverwaltung“ mit Stadt, „Ziel:
     claude.ai“, „Zugang: Lesen“, Uhrzeit und dem Hinweis „… „Angemeldete Geräte“ → „Trennen““; kein
     Code, kein Token.
5. App als Testkonto → „Profil“ → „Angemeldete Geräte“.
   - Erwartet: Eintrag „Claude (claude.ai)“ mit Chip „Claude: Lesen“ und „Zuletzt genutzt … ·
     verbunden <heute>“, Knopf „Trennen“.
6. App als Admin → Benutzerverwaltung.
   - Erwartet: beim Testkonto Chip „Claude: Lesen“ und „verbunden · zuletzt genutzt …“; im Fenster
     „Bearbeiten“ abseits „Claude-Verbindungen trennen …“.
7. Im Profil des Testkontos „Trennen“.
   - Erwartet: „Claude (claude.ai) wurde getrennt.“, Eintrag weg; in claude.ai fordert der Konnektor
     beim nächsten Gebrauch eine neue Verbindung.
8. Erneut „Verbinden“ in claude.ai, auf der Seite „Ablehnen“.
   - Erwartet: zurück zu claude.ai mit einer Fehlermeldung (Verbindung abgelehnt), keine Verbindung
     im Profil, keine Mail.
9. Erneut „Verbinden“, Anmeldung mit einem Konto ohne Claude-Zugang (z. B. Ihr Admin-Konto).
   - Erwartet: „Kein Claude-Zugang – bitte wenden Sie sich an Ihren Admin.“, kein „Verbinden“, kein
     Rücksprung.
10. Verbindung für den Rest wieder herstellen (Schritte 1–3) und über die Benutzerverwaltung
    „Claude-Verbindungen trennen …“ prüfen: Chip bleibt, „verbunden“ verschwindet.

### Für Holger: Claude Code verbinden

Ergebnis (Holger, 07.11.2026): ☑ alle Schritte wie erwartet.

1. Terminal: `claude mcp add --transport http ausruestung-test https://www.prodad.de/cgi-bin/ff-mcp.pl/test`
   (einen alten Eintrag vorher mit `claude mcp remove ausruestung-test` entfernen).
2. In Claude Code `/mcp` → `ausruestung-test` → „Authenticate“.
   - Erwartet: Der Browser öffnet `…/claude-verbinden?…` mit Ziel „Claude Code auf diesem Rechner
     (localhost)“.
3. Anmelden mit dem Testkonto, „Verbinden“.
   - Erwartet: Der Browser meldet den Erfolg von Claude Code; `/mcp` zeigt `ausruestung-test` als
     verbunden (`initialize` und `ping` haben geklappt) mit 0 Werkzeugen. Mail „Neue
     Claude-Verbindung“ mit „Ziel: Claude Code auf dem eigenen Rechner (localhost)“.
4. Profil → „Angemeldete Geräte“: „Claude Code (localhost)“ mit „Trennen“; die Benutzerverwaltung
   zeigt „2 Verbindungen“, solange auch claude.ai verbunden ist.
5. Zeigt die Seite stattdessen „Die Rücksprung-Adresse ist für diese Anwendung nicht registriert.“:
   Claude Code hat einen anderen Port als bei der Registrierung benutzt (Rücksprung-Adressen werden
   exakt verglichen, Plan Abschnitt 9a). Bitte melden; Abhilfe ist ein neues `claude mcp add`.
6. Zum Schluss `claude mcp remove ausruestung-test` und die Verbindung im Profil trennen.

## Runde 4: Claude-Zugang setzen und trennen (M1, M2)

Nach dem Deploy in der Test-Stadt (Agent im Browser, als Admin der Test-Stadt; Holger am iPhone
und als Super-Admin):

Vom Agenten geprüft am 07.11.2026 (Build `4681b1e6d623`, Migrationen 0041–0044 auf dem Server
gelaufen, Chrome als Admin der Test-Stadt; die App hat sich beim ersten Seitenwechsel selbst auf die
neue Version aktualisiert):

- ☑ Benutzerverwaltung → „Holger Test 1“ (Benutzer) → „Bearbeiten“: Abschnitt „Claude-Zugang“ mit
  Aus (gewählt)/Lesen/Ändern; „Lesen“ gespeichert → „Claude-Zugang von Holger Test 1: Lesen.“, Chip
  „Claude: Lesen“. Zurück auf „Aus“ → „Holger Test 1 hat keinen Claude-Zugang mehr; bestehende
  Verbindungen sind getrennt.“, Chip weg (Ausgangsstand wiederhergestellt).
- ☑ Eigenes Konto (Holger Burkarth) und anderer Admin (Phil Burkarth): Auswahl gesperrt mit Hinweis
  „Den eigenen Claude-Zugang kann man nicht ändern.“ bzw. „Für Admins setzt den Claude-Zugang der
  Super-Admin.“.
- ☑ `GET tenant`: `claude_available` in `test` true, in Immendingen false (per curl; in Immendingen
  nichts geändert).
- ☐ Super-Admin, Stadtdetail „Test“, Reiter „Admins und Benutzer“: „Claude-Zugang …“ nur bei aktiven
  Admins; setzen und wieder auf „Aus“. (Holger)
- ☑ „Claude-Verbindungen trennen …“ erscheint erst mit einer Verbindung (Holger, Runde 5,
  claude.ai Schritte 6 und 10).
- ☐ Am Handy: Chip in der Karte ohne waagerechten Überstand. (Holger)

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

Auswertung (vom Agenten nach Holgers erstem Versuch, Protokoll `api/v1/mcp-probe` gelesen am
07.11.2026, 07:30 UTC; Abrufe von Claude mit User-Agent `python-httpx/0.28.1`):

- ☑ Claude ruft `POST mcp` auf (`initialize`, Protokollversion `2025-11-25`, ohne Token → 401) und
  folgt `resource_metadata` (`protected-resource` 200); zwei Durchläufe, 07:16:15 und 07:17:12 UTC.
- ☑ Claude findet die Metadaten über `openid-configuration` (Variante 3, 200).
  `oauth-authorization-server` hat Claude NICHT abgerufen (O1: nicht nötig).
- ☑ Claude registriert sich (07:17:15, 201): `client_name` „Claude“, `redirect_uris`
  `["https://claude.ai/api/mcp/auth_callback"]` (erlaubt), `grant_types`
  `authorization_code`/`refresh_token`, `response_types` `code`, `token_endpoint_auth_method`
  `none`, `scope` `read write`.
- ☒ Der Browser hat `claude-verbinden` beim Server nicht erreicht (kein Schritt `authorize` von
  Claude): Der installierte Service Worker der Test-Stadt lieferte die App („Seite nicht gefunden“).
  Behoben mit Commit „Service Worker: Adressen des Claude-Zugangs immer vom Server (Runde 4,
  Aufgabe 0)“. Der Eintrag `authorize` um 07:18:14 mit nur dem Parameter `x` (User-Agent
  `curl/8.5.0`) ist eine Prüfung per curl, nicht Claude.
- ☑ Nach dem Deploy (Build `751d3207c2f2`, 07.11.2026, Chrome mit installiertem Service Worker der
  Test-Stadt, Agent): Solange der alte Service Worker aktiv ist (der neue wartet), zeigt
  `claude-verbinden?…` noch die App („Seite wird geladen …“); die App lädt dabei selbst die neue
  Version, danach erscheint dieselbe Adresse als JSON vom Server. Nach dem Update mit aktivem
  neuem Service Worker: `claude-verbinden?…` sofort `application/json` vom Server.
- ☑ Wiederholung durch Holger nach dem Deploy (07.11.2026, 08:15 UTC, claude.ai): Ablauf `mcp` 401
  → `protected-resource` → `openid-configuration` → `register` (201, „Claude“) → `authorize`
  innerhalb von 3 s. Der Browser zeigte das JSON der Vorklärung mit allen sechs `checks` `true`;
  Claude sendete `response_type`, `client_id`, `redirect_uri`
  (`https://claude.ai/api/mcp/auth_callback`), `code_challenge`, `code_challenge_method` `S256`,
  `state`, `scope` `read write` und `resource` `https://www.prodad.de/firefighting/test/mcp`.
  **Ergebnis O1: Claude findet die Metadaten unter `/firefighting/test/` ohne Änderung der Webroot.**
