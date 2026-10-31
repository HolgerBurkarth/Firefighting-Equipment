# Firefighting-Equipment – Regeln für KI-Agenten

## Zweck
Webanwendung für Feuerwehren: Geräte (z. B. Atemschutzmasken) werden Standorten
(Fahrzeuge, Räume/Lager, Extern) zugeordnet, ihr Status wird erfasst, jede
Änderung wird protokolliert. Mehrere Städte (Mandanten) nutzen denselben Code.

## Arbeitsweise
- Alle Antworten an den Nutzer immer auf Deutsch, auch Zwischenstände und Rückfragen.
- In kleinen Schritten arbeiten. Vor größeren Änderungen erst einen Plan vorlegen.
- Wünsche für später stehen in `docs/TODO-SPAETER.md`; nicht vorziehen, neue Wünsche dort ergänzen.
- Bestehende Funktionen dürfen nicht kaputtgehen: Jede Änderung bekommt Tests, vor jedem Commit
  müssen die Prüfungen von "Prüfen vor „✅ FERTIG“" grün sein. CI prüft je Push nur die betroffenen
  Bereiche, nachts alles (siehe "GitHub Actions (CI)"); deployt wird nur nach grünem CI-Lauf.
- Commits (seit 31.10.2026): Nach „✅ FERTIG“ committet der Agent selbst, ein Commit je Schritt mit
  allen Dateien des Schritts, Betreff wie in der Meldung genannt, mit der üblichen Attribution
  (`Co-Authored-By`). Vorher immer `git status` prüfen: keine fremden, temporären oder nicht zum
  Schritt gehörenden Dateien (Testausgaben, Notizen, Konfiguration mit Zugangsdaten). Pushen,
  CI verfolgen und deployen ebenfalls der Agent (seit 31.10.2026, siehe "Autonomer Ablauf"); schon
  gepushte Commits ändert er nie (kein `--amend`, kein `--force`).
- Muss ein Schritt vor dem nächsten unbedingt live geprüft werden oder durch CI laufen (z. B. neue
  Abläufe auf dem Server, Migrationen, Änderungen am Workflow), sagt die „✅ FERTIG“-Meldung das
  ausdrücklich.
- Während Prüfläufe laufen (z. B. mehrere Testläufe im Hintergrund), keine Testdateien und keinen
  getesteten Code ändern oder anlegen: Die Läufe lesen sie neu ein, das Ergebnis wäre verfälscht.
  Erst nach dem Ende weiterarbeiten.
- Das Repository ist öffentlich: niemals Passwörter, Zugangsdaten oder
  `config/config.local.php` committen.
- Code und Bezeichner auf Englisch, Dokumentation und Oberflächentexte auf Deutsch.
- Jede Änderung an der REST-API (auch neue Felder) kommt zusätzlich in `docs/UEBERGABE-BACKEND.md`
  (Datum, Commit-Betreff, was und warum, Tests), damit der Backend-Chat sie kennt. Als Betreff trägt
  der Agent den Betreff seines Commits ein (nicht „noch nicht committet“).
- Prüfen vor „✅ FERTIG“ (lokal nur das Nötige; alle Tests in allen fünf Profilen prüft der
  nächtliche Lauf der CI, siehe "GitHub Actions (CI)"):
  - Frontend geändert: in `frontend/` `npm run lint`, `npm run typecheck`, `npm run test:unit:log`
    (wie `test:unit`, hängt je Lauf Ergebnis und Namen gescheiterter Tests an
    `frontend/test-logs/unit.log` an, Ordner in `.gitignore`; einen Fehler, der sich nicht
    wiederholen lässt, dort mit Datum als „nicht reproduzierbar“ nachtragen und in der
    „✅ FERTIG“-Meldung mit Testnamen nennen) und
    die betroffenen Browser-Testdateien in Chromium und WebKit (`npm run build`, dann
    `npm run test:e2e -- tests/e2e/<datei>.spec.ts`; betroffen sind die Dateien der geänderten
    Seiten, Bausteine und Testhilfen).
  - Hängt eine Änderung von Bildschirmbreite oder Browser ab (Layout, Leisten, Touch, Kamera,
    Service Worker), diese Dateien zusätzlich in allen fünf Profilen (`npm run test:e2e:all --
    tests/e2e/<datei>.spec.ts`, auch Firefox und Handy-Breite). Tests, deren Erwartung von der
    Breite abhängt, setzen sie ausdrücklich (`page.setViewportSize`).
  - `composer test` NUR, wenn PHP-Code (`src/`, PHP-Tests in `tests/`, `public/`, `resources/`,
    die per PHPUnit geprüften `scripts/`), Migrationen, `bin/`, `composer.*`, Vorlagen der
    Konfiguration (`config/`) oder gemeinsame Fixtures (`tests/Fixtures/*.json`) geändert wurden.
    Sonst nicht; Ausnahmen mit Filter: Dateien des Frontends, die PHP-Tests abgleichen
    (`frontend/index.html`, `frontend/build/appBase.ts`, `buildInfo.ts`, `compress.ts`,
    `frontend/src/styles/tokens.css`) → `composer test -- --filter
    'FrontendAssetsTest|ContentEncodingTest'`; Regeln für Stadt-Kürzel in README oder CLAUDE.md →
    `composer test -- --filter SlugRulesDocumentationTest` (CI läuft bei reinen `.md`-Änderungen
    nicht, und `.md`-Dateien lösen die PHP-Tests der CI nie aus).
- Kann sich ein Browser-Test je Betriebssystem anders verhalten (z. B. Kamera und Medien,
  Testattrappen mit Browser-Objekten, Dateien, Prozesse und Ports, Schriften; CI läuft unter Linux,
  lokal Windows), vor „✅ FERTIG“ zusätzlich unter Linux (WSL) laufen lassen, nur die betroffenen
  Dateien, in allen fünf Profilen (README "Browser-Tests unter Linux (WSL)"); bei anderen
  Änderungen kein Lauf unter WSL.
  Attrappen und Testhilfen gehen in allen Browsern und Systemen denselben Weg der App, keine
  Sonderwege je Betriebssystem.
- Status von Nachrichten an den Nutzer – jede Nachricht beginnt mit genau einer Kennzeichnung:
  - "⏳ ZWISCHENSTAND – ich arbeite weiter": Es läuft noch etwas. Dazu: was gerade läuft, wie lange
    es voraussichtlich dauert und was danach kommt. Der Nutzer muss nichts tun.
  - "❓ RÜCKFRAGE – ich warte auf Ihre Antwort": Ohne Antwort geht es nicht weiter.
  - "✅ FERTIG – ich halte an": Die Aufgabe ist abgeschlossen. Dazu: Testergebnisse, was geändert
    wurde, welcher Commit angelegt ist (Betreff), wie der Nutzer es prüft, ob der Schritt vor dem
    nächsten live geprüft werden oder durch CI laufen muss, und das Ergebnis des letzten nächtlichen
    CI-Laufs (`gh run list --workflow=ci.yml --event=schedule --limit=1`; rot → Ursache nennen und
    zuerst untersuchen; ohne `gh` oder Anmeldung sagen, dass es nicht geprüft werden konnte).
  - "⛔ ABGEBROCHEN – Problem": Es geht nicht weiter; Ursache und Vorschlag.
  Bei lang laufenden Aktionen (Prüfläufe, Komplettläufe) vorher die erwartete Dauer nennen und eine
  feste Zeitgrenze setzen.
- Live-Tests (gegen den Server www.prodad.de) nur in der Test-Stadt `/firefighting/test/`, nie in
  echten Städten.

## Autonomer Ablauf
Seit 31.10.2026 arbeitet der Agent eine Runde selbstständig durch: programmieren, testen,
committen, pushen, CI grün bekommen, deployen. Ersetzt die frühere Regel „Pushen tut nur der Nutzer“.
- Werkzeuge (eingerichtet vom Nutzer): `gh` ist angemeldet (Konto `HolgerBurkarth`, Rechte `repo`
  und `workflow`; unter Windows `C:\Program Files\GitHub CLI\gh.exe`, falls nicht im PATH der Shell).
  Deploy: `ssh -T strato-ff` startet auf dem Server ausschließlich `scripts/deploy.sh` (erzwungener
  Befehl im Schlüssel, keine Shell, kein Passwort). Andere Befehle auf dem Server gibt es so nicht.
- Ablauf je Schritt:
  1. Programmieren, lokal prüfen (siehe "Arbeitsweise", "Prüfen vor „✅ FERTIG“"), committen.
  2. `git push`, dann den CI-Lauf dieses Commits verfolgen (`gh run list`, `gh run watch <id>`).
     Ändert der Push nur `.md`-Dateien, läuft keine CI und es gibt nichts zu deployen.
  3. Rot: `gh run view <id> --log-failed` lesen, Ursache in der „✅ FERTIG“-Meldung erklären,
     beheben, committen, pushen. Höchstens 3 Reparaturversuche je Fehler, danach anhalten und
     berichten („⛔ ABGEBROCHEN“). Nie einfach neu starten; einzige Ausnahme: Ein Lauf, der
     AUSSCHLIESSLICH am bekannten Firefox-Fehler #43413 scheitert (siehe "GitHub Actions (CI)"),
     darf einmal mit `gh run rerun <id> --failed` wiederholt werden.
  4. Grün (auch der Job `deploy`, der den Branch `deploy` schreibt): `ssh -T strato-ff`. Danach
     prüfen: Die Ausgabe endet mit „Deployment erfolgreich“,
     `https://www.prodad.de/firefighting/test/build.json` liefert als `build` die ersten 12 Zeichen
     des gepushten main-Commits, und `https://www.prodad.de/firefighting/service/api/v1/health`
     liefert `{"status":"ok","database":"ok"}`. Scheitert das Deploy: NICHTS zurückspielen,
     anhalten, die Ausgabe berichten.
  5. Live prüfen (seit 31.10.2026): Nach jedem Deploy prüft der Agent die betroffenen Funktionen
     selbst im Browser mit Claude in Chrome, bevor er „✅ FERTIG“ meldet.
     - Nur in der Test-Stadt `https://www.prodad.de/firefighting/test/`, nie in echten Städten
       (z. B. Immendingen).
     - Keine Passwörter eingeben und nicht abmelden (neu anmelden tut nur der Nutzer). Fehlt eine
       Anmeldung: „❓ RÜCKFRAGE“.
     - Keine Dateien herunterladen; CSV-Inhalte im Browser per JavaScript prüfen (den Blob vor dem
       Speichern abfangen).
     - Testgeräte: Kennungen M90–M99 bzw. Gerätetyp „Live-Testtyp 2“; an bestehenden Geräten
       geänderte Werte danach zurücksetzen.
     - Ergebnis je geprüftem Punkt (☑/☒) in die passende LIVETEST-Datei (z. B.
       `docs/frontend/LIVETEST-ADMIN-KONSOLE.md`) eintragen; Fehler beheben, Wünsche nach
       `docs/TODO-SPAETER.md`.
     - Was der Browser nicht prüfen kann (schmale Fensterbreiten, iPhone, Handscanner, Kamera,
       Mails), als offen für den Nutzer markieren.
  6. „✅ FERTIG“ zusätzlich mit: was live ist (Commit, Build-Kennung), Laufzeit der CI, Reparaturen
     (Ursache je roten Lauf), was live im Browser geprüft ist (☑/☒) und was für den Nutzer offen
     bleibt.
- Vorher anhalten und fragen („❓ RÜCKFRAGE“):
  - Migrationen, die bestehende Daten ändern oder löschen (`UPDATE`, `DELETE`, `DROP`, Spalten
    entfernen oder umbenennen). Reine Erweiterungen (neue Tabellen oder Spalten) gehen ohne.
  - Änderungen an `scripts/deploy.sh`, am Deploy im Workflow (Job `deploy`,
    `scripts/publish-deploy-branch.sh`) oder an Sicherungen (`bin/db-dump.php`, `src/Backup/`,
    Aufräumregeln).
  - Alles, was echte Städte (nicht `test`) betrifft.
- Weiterhin: keine Live-Tests in echten Städten, nie `purge-device-data`, nie Zugangsdaten
  committen.

## Zielumgebung (STRATO, Shared Hosting)
- PHP 8.5 (CGI), MariaDB 10.11, Apache mit `.htaccess` und mod_rewrite.
- Rewrite-Regeln mit `[L]`, nicht mit `[END]` (sonst greift die PHP-Konfiguration nicht).
- Verfügbare PHP-Erweiterungen: PDO_MySQL, MySQLi, mbstring, GD, BCMath, DOM, fileinfo,
  zip, curl, openssl. Nicht verwenden: intl, sodium, xmlreader, xmlwriter, SQLite.
- Keine Cronjobs: Fälligkeiten u. Ä. werden beim Aufruf berechnet.
- Composer ist auf dem Server vorhanden.
- E-Mail-Versand über `mail()` (sendmail) oder per SMTP über das STRATO-Postfach
  `feuerwehr-app@prodad.de` (`smtp.strato.de`, signiert mit DKIM; siehe "E-Mail-Versand").

## Aufbau und URLs
- Projektordner auf dem Server: `/apps/firefighting-equipment`
  - `public/` ist der einzige öffentlich erreichbare Ordner (Front-Controller `index.php`).
  - `config/` liegt außerhalb von `public/` und ist per `.htaccess` gesperrt.
    Zugangsdaten stehen in `config/config.local.php` (nicht im Repository).
- URL-Schema: `https://www.prodad.de/firefighting/<stadt>/`
  - REST-API: `/firefighting/<stadt>/api/v1/...`
  - Web-App-Manifest: `/firefighting/<stadt>/manifest.webmanifest` (einzige Route außerhalb von
    `api/v1`, siehe "Erscheinungsbild (API)")
  - Super-Admin: `/firefighting/service/`
  - Alle anderen GET-Adressen unter `/firefighting/<bereich>/` liefern das Frontend (App-Seite
    oder Datei aus dem Build, siehe "Frontend" und "Frontend ausliefern").
- Der Mandant wird aus dem URL-Pfad abgeleitet. Das Kürzel einer Stadt ist unveränderlich.
- Städte sind keine Ordner: Das Kürzel im Pfad ist virtuell und wird über `fe_tenants`
  in der Datenbank aufgelöst.
- `service` ist ein reserviertes Kürzel (Super-Admin) und darf nie als Stadt-Kürzel
  vergeben werden.
- `/api/v1/health` ist mandantenunabhängig und muss unter `/firefighting/service/` immer
  erreichbar sein (wird vom Deploy-Skript geprüft).

## HTTP und REST-API
- Code in `src/Http/` (Ablauf, Router, Fehler) und `src/Api/V1/` (Handler). `public/index.php`
  bleibt dünn: nur `Application::create(...)->handle(Request::fromGlobals())->send()`.
- Präfix `/firefighting` nur in `AppPath::PREFIX` (die App im Browser kennt es nicht, sie liest den
  Basispfad aus `<base href>`; nur Entwicklungswerkzeuge und Tests in `frontend/` nennen es); lokal
  gilt dasselbe Schema mit `php -S localhost:8000 public/index.php` (`localhost`, nicht
  `127.0.0.1`: nur dort nehmen Browser und curl das `Secure`-Cookie über `http://` an).
- Adresse `/firefighting/<bereich>/<rest>` (`AppPath::parse`): Query-String wird ignoriert, ein
  abschließender `/` toleriert; leere Segmente, fehlendes Präfix oder ein Bereich außerhalb
  `[a-z0-9-]+` → 404. Der Pfad wird nicht URL-dekodiert.
- Reihenfolge in `Application::handle` (nicht ändern): Adresse zerlegen → Route suchen
  (404/405; keine Route, Methode `GET` und Pfad nicht `api` bzw. `api/…` → Frontend, ohne Datenbank
  und Sitzung, siehe "Frontend ausliefern") → ändernde Anfrage prüfen (`RequestGuard`, ohne Datenbank; 415/403/400) → erst dann
  die Stadt laden → Sitzung und Zugriffsstufe prüfen (nur bei nicht öffentlichen Routen; 401/403)
  → Handler. Unbekannte Adressen und abgelehnte ändernde Anfragen verbinden sich so nie mit der
  Datenbank.
- Bereich `service` (`AppPath::SERVICE_SCOPE`): Super-Admin, keine Stadt, eigene Routen.
  Sonst Kürzel per `TenantSlug` (ungültig → 404 `not_found`), Stadt per `TenantRepository`
  (unbekannt → 404 `tenant_not_found`, gesperrt → 403 `tenant_inactive`, Datenbankstörung →
  503 `database_unavailable`), alles in `TenantResolver`.
- Router (`Router::forService()` / `Router::forTenant()`): Pfade relativ zum Bereich, ohne Slash
  am Anfang/Ende. Ein ganzes Segment darf ein Platzhalter `{name}` sein; er passt nur auf positive
  Ganzzahlen ohne führende Null (höchstens 18 Stellen, also immer `int`), sonst 404. Exakte Pfade
  haben Vorrang vor Platzhaltern. Falsche Methode → 405 mit `Allow` (sortiert). `HEAD` wird nicht
  wie `GET` behandelt. Alle Routen stehen in `Routes::service()` und `Routes::tenant()`.
- Zugriffsregeln: Jede Route deklariert ihre Stufe (`Access`): `Public`, `Authenticated`,
  `Admin` (nur unter einer Stadt), `SuperAdmin` (nur unter `service`); eine falsche Kombination
  wirft beim Anlegen eine `LogicException`. Geprüft wird zentral in `Application`, nie im Handler:
  keine oder ungültige Sitzung → 401 `not_authenticated` (ein mitgesendetes ungültiges Cookie
  wird gelöscht), Rolle reicht nicht → 403 `forbidden`. Bei `Public` wird keine Sitzung geladen.
  `health` und `tenant` bleiben öffentlich.
- Handler implementieren `RequestHandler` (Rückgabe `Response`, in der Regel `JsonResponse`) und
  erhalten `RequestContext` (Anfrage, Stadt oder
  `null` unter `service`, `LazyConnection`, `ErrorReporter`, Platzhalter `param()`, JSON-Body
  `body`/`stringField()`/`optionalStringField()`/`boolField()` (nur echte JSON-Werte true/false)/
  `intField(name, min, max)`/
  `optionalIntField(name, min, max)` (nur echte JSON-Ganzzahlen; optional: fehlt oder `null` →
  `null`), `hasField(name)` (Feld vorhanden, auch mit `null`; unterscheidet "leeren" von
  "unverändert"), Query-String über `request->query()` (nur einfache Werte), `queryFlag(name)` (`1` =
  an, `0` oder fehlend = aus, sonst 400) und `queryInt(name, min, max)` (nur Ziffern ohne führende
  Null, fehlend = `null`, sonst 400), Sitzung `auth()` bei nicht öffentlichen Routen, Mails über `notifier()`,
  absolute Links über `urls`). Die Datenbankverbindung
  (und das Laden der Konfiguration) erfolgt erst beim ersten `$context->db->get()`; schlägt sie im
  Handler fehl, antwortet `Application` mit 503 `database_unavailable`.
- CSRF-Schutz (`RequestGuard`) für alle ändernden Anfragen (alles außer GET, HEAD, OPTIONS):
  `Content-Type` muss `application/json` sein (optional `charset=utf-8`), sonst 415
  `unsupported_media_type`; ist ein `Origin`-Header vorhanden, muss er erlaubt sein, sonst 403
  `origin_not_allowed`. Erlaubt ist immer `https://www.prodad.de` (`AllowedOrigins::DEFAULT`);
  weitere nur lokal über die optionale Gruppe `http` → `allowed_origins` in der
  Konfigurationsdatei (wird nur geladen, wenn ein anderer Origin kommt). Der Body muss ein
  JSON-Objekt sein, auch `{}` (z. B. bei Logout und DELETE); höchstens 16 KiB
  (`Request::MAX_BODY_BYTES`). Sonst 400 `invalid_request`. Eine Route kann eine größere Grenze
  deklarieren (`Router::add(…, maxBodyBytes)`, über `RouteMatch::$maxBodyBytes` an `RequestGuard`),
  höchstens `Request::MAX_UPLOAD_BYTES` (3 MiB); bisher nur die Routen des CSV-Imports (3 MiB) und
  `POST tenant/logo` (720 KiB, `TenantLogoHandler::MAX_BODY_BYTES`; `DELETE tenant/logo` und
  `POST tenant/appearance` bleiben bei 16 KiB). `Request`
  liest bis zu 3 MiB, größere Bodys werden nicht gelesen. Kein anderer Content-Type und kein
  `multipart/form-data` (Formulare fremder Seiten könnten es senden): Dateien kommen als Base64 im
  JSON.
- Stadt-Kürzel (gemeinsame Prüfung Frontend/Backend, README "Stadt-Kürzel"): regulärer Ausdruck
  `^[a-z0-9]+(-[a-z0-9]+)*$` (Kleinbuchstaben `a-z`, Ziffern, Bindestrich nicht am Anfang/Ende und
  nicht doppelt), 1 bis höchstens 50 Zeichen, reserviert `service` (auch in anderer Schreibweise),
  eindeutig über alle Städte (auch gesperrte), unveränderlich und nie wieder frei. Reihenfolge der
  Prüfungen in `TenantSlug::fromString` (bestimmt die Meldung): leer → Länge → reserviert →
  Großbuchstaben, deren Kleinschreibung gültig wäre (Meldung mit Vorschlag,
  `InvalidTenantValueException::$suggestion`) → Format. Regeln nur gemeinsam in `TenantSlug`,
  Migration (CHECK), README und hier ändern; `SlugRulesDocumentationTest` gleicht README und
  CLAUDE.md mit `TenantSlug::PATTERN`, `MAX_LENGTH` und `RESERVED` ab.
- Fehlerformat immer `{"error":"code","message":"deutscher Text"}`, erzeugt über die Fabriken in
  `HttpException`; Codes: `invalid_request` 400, `invalid_token` 400, `invalid_invitation_code`
  400, `not_authenticated` 401, `invalid_credentials`
  401, `forbidden` 403, `origin_not_allowed` 403, `account_locked` 403, `email_not_verified` 403,
  `pending_approval` 403, `invalid_current_password` 403, `tenant_inactive` 403, `not_found` 404,
  `tenant_not_found` 404, `method_not_allowed` 405, `rule_violation` 409 (Meldung der verletzten
  Regel), `slug_taken` 409, `email_taken` 409 (beide nur für den Super-Admin; öffentliche Abläufe
  verraten nie, ob eine Adresse existiert), `name_taken` 409 (Name eines Gerätetyps bzw. Standorts
  vergeben), `identifier_taken` 409 (Kennung eines Geräts vergeben), `scan_code_taken` 409
  (Scan-Code eines Geräts vergeben), `version_conflict` 409
  (Gerät inzwischen geändert, aktueller Stand in `details`), `import_outdated` 409 (CSV-Import: seit
  der Vorschau geändert, Vorschau neu erstellen), `client_ref_conflict` 409 (Buchung: `client_ref`
  schon für eine andere Buchung verwendet), `stale_action` 409 (Offline-Buchung zu alt, ohne
  `confirmed_stale`), `unsupported_media_type` 415, `weak_password` 422, `clock_implausible` 422
  (Aktionszeit einer Offline-Buchung zu weit in der Zukunft), `too_many_attempts` 429 (mit
  `Retry-After`), `database_unavailable` 503, `frontend_unavailable` 503 (Build des Frontends fehlt, siehe
  "Frontend ausliefern"), `internal_error` 500. Neue Codes nur dort. Ein Frontend meldet bei 401 ab; daher ist ein falsches aktuelles
  Passwort beim Ändern 403, nicht 401.
- Optionales Feld `details` (immer ein JSON-Objekt) mit maschinenlesbaren Angaben zum Fehler,
  z. B. `{"error":"rule_violation","message":"…","details":{"devices":2,"sublocations":1}}` beim
  Sperren eines Standorts, der nicht leer ist (`HttpException::ruleViolation(message, details)`),
  oder `{"line": 4}` bei einer unlesbaren Import-Datei (`HttpException::invalidRequest(message,
  details)`), `{"contrast": 4.47, "min_contrast": 4.5}` bei einer zu hellen Akzentfarbe.
  Fehler ohne Angaben haben kein Feld `details` (alle bisherigen unverändert). Nie Interna,
  Zugangsdaten oder personenbezogene Daten in `details`.
- Einzige Ausnahme vom Fehlerformat: `GET api/v1/health` liefert `{"status":"ok","database":"ok"}`
  (200) bzw. `{"status":"error","database":"error"}` (503). Unter einer Stadt gilt vorher die
  Stadtprüfung (403/404/503 im Fehlerformat).
- Unerwartete Fehler → 500 `internal_error` mit allgemeiner Meldung. Details nur über
  `ErrorReporter` ins `error_log`: maskiert (`DatabaseError`), ohne Stack-Trace (enthält
  Argumente wie Zugangsdaten). Nie Details oder Zugangsdaten in die Antwort.
- Jede API-Antwort trägt `Content-Type: application/json; charset=utf-8`,
  `Cache-Control: no-store` und `X-Content-Type-Options: nosniff` (`JsonResponse::DEFAULT_HEADERS`,
  nicht überschreibbar). Ausnahmen: CSV-Downloads (`CsvResponse`, siehe "CSV-Export (API)")
  mit `text/csv; charset=utf-8` und `Content-Disposition: attachment`, ebenfalls `no-store` und
  `nosniff`; ihre Fehler kommen weiterhin als JSON. Und zwischenspeicherbare öffentliche Dateien
  (`CacheableResponse`, siehe "Erscheinungsbild (API)" und "Frontend ausliefern").
- Ausnahmen von `Cache-Control: no-store` (eng begrenzt): NUR `CacheableResponse`, NUR für die drei
  öffentlichen Routen `GET api/v1/tenant/logo/{size}`, `GET api/v1/app-icon/{size}` (auch unter
  `service`) und `GET manifest.webmanifest` sowie die Auslieferung des Frontends (`FrontendHandler`; alles ohne
  personenbezogene Daten, ohne Sitzung und nie `Set-Cookie`). `CacheableResponse` erlaubt nur die
  Content-Types `image/png`, `application/manifest+json; charset=utf-8` und für das Frontend
  `text/html; charset=utf-8`, `text/javascript; charset=utf-8`, `text/css; charset=utf-8`,
  `font/woff2`, `font/woff`, `application/wasm` (nur `assets/`, Kamera-Scanner) und `application/json; charset=utf-8` (NUR für `build.json` des
  Frontends; API-Antworten nie über `CacheableResponse`) (Konstanten in `CacheableResponse`), und nur die beiden Richtlinien aus
  `CachePolicy`: `Immutable` (`public, max-age=31536000, immutable`: Logo/Symbol nur, wenn `?v=`
  gleich der aktuellen Versionskennung ist; Frontend nur `assets/…` mit Hash im Namen) und
  `Revalidate` (`no-cache`: Logo/Symbol ohne oder mit veralteter Kennung, Manifest, App-Seite,
  `sw.js`/`workbox-….js`, `build.json`). Immer mit starkem `ETag` und `nosniff`; `If-None-Match` (auch `W/`,
  Liste, `*`) → 304 ohne Body. Zusätzliche Header nur aus der festen Liste
  `Content-Security-Policy`, `Referrer-Policy`, `X-Frame-Options` (ohne Steuerzeichen, sonst
  `InvalidArgumentException`), bisher nur auf der App-Seite. Dateien des Frontends mit
  vorkomprimierter Fassung tragen zusätzlich `Vary: Accept-Encoding` (auch bei 304) und, wenn
  komprimiert gesendet, `Content-Encoding` (`br` oder `gzip`, `ContentEncoding`; nicht bei 304;
  nie ohne `Vary`), siehe "Frontend ausliefern". Fehler dieser Routen bleiben JSON mit
  `no-store`. Keine weiteren Ausnahmen ohne Rückfrage; neue nur über
  `CacheableResponse`/`CachePolicy` und hier dokumentiert.
- Versionierung der API für das Frontend (beschlossener Plan, gilt für alle Arbeiten an API und
  Frontend): Die App sendet bei JEDEM API-Aufruf `X-Client-Api: <n>` (umgesetzt: `apiRequest` in
  `frontend/src/api/client.ts`, `ClientApi::HEADER`/`CURRENT` in `src/Http/ClientApi.php`; die Zahl
  steht in `resources/client-api.json`, abgeglichen von `ClientApiTest` und `clientApi.spec.ts`;
  derzeit 1). Ein gewöhnlicher Header einer Anfrage an dieselbe Adresse: kein CORS-Preflight,
  `RequestGuard` prüft nur `Content-Type`, `Origin` und Body (per Test). Das Backend wertet ihn
  NOCH NICHT aus. Geplant, jeweils erst nach Rückfrage umzusetzen:
  - Grundregel: Die API nur erweitern (neue Felder optional, alte noch mindestens einen Deploy
    lang behalten). `n` steigt nur bei einer inkompatiblen Änderung, dann in Datei, `ClientApi` und
    Frontend gemeinsam.
  - Backend: `MIN_CLIENT_API`; liegt `X-Client-Api` darunter, antwortet `Application` vor dem
    Handler mit 409 `client_outdated` (neuer Fehlercode, `details {min_client_api}`). Ohne Header
    (curl, ältere Apps) gilt die Anfrage als aktuell, bis das ausdrücklich geändert wird.
  - Jede Antwort trägt `X-Api-Version: <m>`; kennt die App eine kleinere Zahl, prüft sie sofort auf
    ein Update.
  - Frontend bei `client_outdated`: sofort auf eine neue Version prüfen und nach den Regeln der
    Updates aktualisieren (angefangene Eingaben bleiben, Hinweis „Diese Version ist veraltet –
    bitte aktualisieren“). Offline-Buchungen bleiben in der Warteschlange und gehen nach dem Update
    raus; die Warteschlange bekommt dafür eine eigene Formatversion.
- Antworten implementieren `Http\Response` (`statusCode()`, `headers()`, `send()`): `JsonResponse`
  (Regelfall), `CsvResponse` oder `CacheableResponse`. `Application` erzeugt nur JSON vorab
  (Kodierfehler → 500); ein CSV-Download wird erst beim Senden geschrieben.

## Frontend ausliefern
- Code in `src/Http/Frontend/` (`FrontendHandler`, `FrontendAssets`, `FrontendFile`,
  `ContentSecurityPolicy`, `FrontendUnavailableException`) und `src/Http/ContentEncoding.php`. `Application::create` setzt
  `FrontendHandler` mit `FrontendAssets::defaultDirectory()` (= `frontend/dist`, außerhalb von
  `public/`; nur PHP liest es). Ohne `FrontendHandler` (Konstruktor-Standard `null`, Tests mit eigenen
  Routern) bleiben unbekannte Pfade 404. `.htaccess` und `public/index.php` brauchen dafür nichts:
  Die Regel in `htdocs/.htaccess` schickt schon alles unter `/firefighting/<bereich>/` an PHP.
- Fallback nur, wenn der Router 404 `not_found` meldet, die Methode genau `GET` ist und der Pfad nicht
  `api` oder `api/…` ist (`FrontendHandler::handles`). Alle anderen Methoden (auch `HEAD`), API-Pfade
  und vorhandene Routen (405) verhalten sich wie vorher (per Test, `FrontendDeliveryTest`). Keine
  Datenbank, keine Sitzung, kein Cookie: Ob die Stadt existiert, fragt die App über `api/v1/tenant`;
  ein ungültiges Kürzel (`TenantSlug`, ohne Datenbank) → 404.
- Pfade (relativ zum Bereich): `assets/<datei>` → Datei aus `frontend/dist/assets/`, `Immutable`.
  Andere Pfade mit Dateiendung (z. B. `sw.js`, `workbox-….js`, `build.json`) → Datei direkt in
  `frontend/dist/`, `Revalidate`. Fehlt die Datei oder ist sie nicht erlaubt → 404 `not_found` (JSON), NIE die App-Seite
  (sonst würde HTML als Skript geladen). Alles andere (`""`, `index.html`, `geraete/12`,
  `passwort-zuruecksetzen` …) → `index.html` mit Basispfad, `Revalidate`.
- Erlaubte Dateien (`FrontendAssets`): nur Endungen aus `FrontendAssets::CONTENT_TYPES` (`js`, `css`,
  `woff2`, `woff`, `wasm`; `wasm` = `application/wasm`, Lesemodul des Kamera-Scanners, nur in
  `assets/`, also immer `Immutable`: `FrontendAssets::ASSETS_ONLY`), Name `[A-Za-z0-9][A-Za-z0-9_.-]*` (höchstens 128 Zeichen), keine Unterordner
  außer `assets/`, kein `..`, keine versteckten Dateien, zusätzlich per `realpath` innerhalb von
  `frontend/dist`. Neue Endungen nur, wenn der Build sie wirklich enthält (Content-Type in
  `CacheableResponse` ergänzen). Einzige Ausnahme ohne Endung in der Liste: genau
  `FrontendAssets::BUILD_INFO_FILE` (`build.json`, nur direkt in `frontend/dist`, nicht in `assets/`,
  `application/json; charset=utf-8`; per Test gleich mit `BUILD_INFO_FILE` in
  `frontend/build/buildInfo.ts`); andere `.json` bleiben 404. Dateinamen des Builds folgen
  derselben Regel (`frontend/build/fileNames.ts`, `sanitizeFileName` in `vite.config.ts`; z. B. wird
  Vues `_plugin-vue_export-helper` zu `plugin-vue_export-helper`, sonst 404); `delivery.spec.ts`
  ruft jede Datei des Builds über PHP ab (außer den komprimierten Fassungen).
- Komprimierung (KONZEPT.md Entscheidung 26; STRATO komprimiert die Ausgabe nicht): Der Build
  legt neben jede `.js`/`.css`/`.wasm` (auch `sw.js`, `workbox-….js`) `<datei>.br` (Brotli, Stufe 11) und
  `<datei>.gz` (gzip, Stufe 9), jeweils nur, wenn kleiner als die Datei (`frontend/build/compress.ts`,
  Vite-Plugin `compressBuild`, `closeBundle` mit `order: 'post'`, also nach vite-plugin-pwa).
  Schriften sind schon komprimiert, `index.html` ändert PHP (Basispfad), `build.json` ist zu klein:
  diese bleiben unkomprimiert. Enthält eine `.js`/`.css` ein CR, bricht der Build ab (Git schreibt
  sie im Branch `deploy` mit LF, die Fassung passte dann nicht mehr; `.wasm`, `.br`, `.gz` sind in
  `.gitattributes` `binary`). PHP dekomprimiert nie: `FrontendAssets::file(pfad, Accept-Encoding)`
  sucht die Fassungen neben der erlaubten, vorhandenen Datei (Endungen `ContentEncoding::fileSuffix`
  = `VARIANTS` in `compress.ts`, per Test gleich; ebenfalls per `realpath` innerhalb von
  `frontend/dist`) und wählt per `ContentEncoding::negotiate` (RFC 9110: Gewicht des eigenen
  Eintrags, sonst `*`, `q=0` schließt aus, höchstes Gewicht, bei Gleichstand `br` vor `gzip`;
  `x-gzip` = `gzip`; ungültige Gewichte übergangen; ohne erlaubte Fassung unkomprimiert, nie 406).
  ETag je Fassung über die gesendeten Bytes, bei komprimierten mit Anhang `-br`/`-gz`
  (`FrontendFile::etag`). `Vary: Accept-Encoding` nur, wenn es Fassungen gibt. Fassungen sind nie
  direkt abrufbar (Endung nicht erlaubt → 404), ohne die Datei selbst gelten sie nicht. Tests:
  `ContentEncodingTest`, `FrontendDeliveryTest` (Fassungen im Test-Build mit beliebigem Inhalt),
  `compress.spec.ts`, `delivery.spec.ts` (jede Fassung des echten Builds dekomprimiert gleich der
  Datei).
- Basispfad: `index.html` des Builds enthält genau einmal `<base href="/__FF_BASE__/" />`
  (`FrontendAssets::BASE_PLACEHOLDER` = `BASE_PLACEHOLDER` in `frontend/build/appBase.ts`, per Test
  gleich). PHP ersetzt ihn durch `AppPath::PREFIX/<bereich>/` (HTML-maskiert). Ein Build für alle
  Städte; die App liest Bereich und Basispfad aus `document.baseURI`.
- App-Seite mit zusätzlichen Headern: `Content-Security-Policy` (`ContentSecurityPolicy::header()`),
  `Referrer-Policy: same-origin`, `X-Frame-Options: DENY`.
- CSP (`ContentSecurityPolicy::DIRECTIVES`, eine Liste je Direktive): `default-src`, `script-src`,
  `style-src`, `img-src`, `font-src`, `connect-src`, `manifest-src`, `worker-src`, `base-uri`,
  `form-action` je `'self'`; `object-src` und `frame-ancestors` `'none'`. Nie `'unsafe-inline'`,
  `'unsafe-eval'`, `'unsafe-hashes'`, `*`, `http:` oder `https:` (per Test). Ergänzungen bisher (je per
  Test genau so): `script-src 'self' 'wasm-unsafe-eval'` für den Kamera-Scanner (erlaubt nur das
  Übersetzen von WebAssembly, kein eval von JavaScript; WASM aus `assets/`, kein Worker, kein
  `blob:`, kein CDN) und `img-src 'self' blob:` für das Logo in den Einstellungen (die selbst
  gewählte Datei und das daraus gezeichnete PNG über `URL.createObjectURL`; kein `data:`). Spätere
  Bausteine ergänzen gezielt nur, was sie brauchen, mit Begründung im Kommentar. Dynamische Farben
  (Farbkreise, Vorschau der Akzentfarbe) nur als CSS-Variable über `:style="{ '--name': wert }"`
  (Vue setzt sie per CSSOM, kein `style=""` im HTML). Der QR-Code der Einladungscodes braucht
  nichts: `uqr` liefert nur das Raster, Vue zeichnet es als SVG im DOM (`QrCode`).
  Daher im Frontend keine Inline-Skripte, keine `<style>`-Elemente und kein `style=""` im HTML;
  Schriften liegen im Build (kein Google Fonts). Kein natives `<select>`: Auswahllisten (Gerätetyp,
  Standort, Rolle …) mit `Select` bzw. `Combobox` von Reka UI, kurze feste Auswahlen (z. B.
  Sortierung am Handy) auch als Knöpfe mit `aria-pressed` oder Radiogruppe. Ursache: Ein natives
  `<select>` löst in Playwright-WebKit (unter Windows, 30.10.2026) beim Laden die Konsolenmeldung
  „Refused to apply a stylesheet because its hash, its nonce, or 'unsafe-inline' does not appear in
  the style-src directive“ und ein `securitypolicyviolation` (`style-src-attr`, `inline`) aus, auch
  auf einer leeren Seite ohne Inline-Stil (das eigene Stylesheet des Steuerelements); die Tests
  scheitern daran. Beobachtet nur in Playwright-WebKit; ob echtes Safari das ebenso meldet, ist nicht
  geprüft (`docs/TODO-SPAETER.md`, T8). Auswahllisten über `AppSelect` (`src/components/`): ohne
  `name` (sonst legt Reka UI in einem Formular ein verstecktes natives `<select>` an) und ohne
  `SelectViewport` bzw. `ComboboxViewport` (fügen ein `<style>`-Element ein, das die CSP blockiert;
  nur für `position="item-aligned"` und Scroll-Knöpfe nötig, die Liste scrollt in `SelectContent`).
- Build fehlt oder `index.html` ohne Platzhalter → 503 `frontend_unavailable` (Einzelheiten nur im
  Protokoll); die API läuft weiter.

## Frontend
- Ordner `frontend/` (Vue 3, Vite, TypeScript strikt, Vue Router, Reka UI, vite-plugin-pwa);
  verbindliches Konzept in `docs/frontend/KONZEPT.md`, Gestaltung nach `docs/frontend/mockup/`
  (`LIESMICH.md`). Oberflächentexte deutsch, Code und Bezeichner englisch.
- Aufbau: `index.html` (mit Platzhalter für `<base href>`), `src/main.ts`, `src/App.vue`,
  `src/router/` (Pfade relativ zum Basispfad, deutsche Adressen wie `anmelden`, Wächter, `back.ts`), `src/pages/`,
  `src/components/`, `src/api/` (Zugriffe auf die REST-API, Antworten geprüft; alle über
  `client.ts`; `appearance.ts`, `devices.ts`, `deviceBulk.ts`, `deviceEvents.ts`, `deviceImport.ts`, `deviceSummary.ts`,
  `deviceTypes.ts`, `inspections.ts`, `invitationCodes.ts`, `locations.ts`, `notices.ts`, `tenants.ts`, `users.ts`), `src/data/` (Datenschicht für Geräte, Standorte,
  Gerätetypen, Benutzer, Hinweise und das Dashboard; unter `service` `service.ts`),
  `src/auth/` (Sitzung, Fehlermeldungen, Weiterleitung), `src/runtime/` (`tenantState.ts` geladene
  Stadt für Kopfleiste und Formulare, Änderungen des Erscheinungsbilds `saveAccentColor`, `saveLogo`,
  `removeLogo` über `setTenant`, `logoImage.ts` Logo auf 512 × 512 PNG, `appBase.ts` Bereich und
  Basispfad aus `document.baseURI`, `theme.ts` Anzeigemodus je Gerät, `accent.ts` Stadtfarbe,
  `appearance.ts` Manifest-Link und Erscheinungsbild der Stadt, `serviceWorker.ts` Registrierung,
  `appUpdate.ts` Updates, `updateTrace.ts` Zeitleiste der Updates, `unsavedInput.ts` ungespeicherte
  Eingaben, `build.ts` Build-Kennung,
  `now.ts` `useNow` für Zeitangaben: alle 30 s und bei `visibilitychange` neu, `countdown.ts`
  `useCountdown` Sperre nach 429 bis `Retry-After`, `download.ts` `saveDownload`, `share.ts`
  `shareLink`/`copyText`), `src/scanner/` (Kamera-Scanner:
  `barcodeReader.ts` Lesemodul, `camera.ts` Kamera und Meldungen), `src/styles/` (`tokens.css` Design-Variablen, `base.css`),
  `build/appBase.ts` (Vite-Plugin: Platzhalter im Build, Basispfad im Dev-Server),
  `build/compress.ts` (Vite-Plugin: `.br`/`.gz` neben jede Datei, siehe "Frontend ausliefern"),
  `tests/unit/` (Vitest), `tests/e2e/` (Playwright). Build nach `frontend/dist/` (in `.gitignore`).
- Design-Variablen nur in `src/styles/tokens.css` (Farben hell/dunkel aus dem Mockup, Statusfarben,
  Radien, Schatten, Mindestgröße 44 px; `--paper`, `--ink`, `--ink-muted` für QR-Code und Druck, in
  beiden Modi gleich); Komponenten verwenden nur `var(--…)`. Anzeigemodus: ohne
  `data-theme` gilt `prefers-color-scheme`, `data-theme="light"`/`"dark"` erzwingt; die beiden
  Dunkel-Blöcke müssen gleich sein, Text- und Statusfarben mindestens 4,5:1 (beides per Test).
  Stadtfarbe zur Laufzeit als `--accent` aus `GET api/v1/tenant`, Standard `#b71c1c`
  (= `AccentColor::DEFAULT`); nach einer Änderung in den Einstellungen aus deren Antwort.
- API-Zugriffe nur über `apiRequest` (`src/api/client.ts`): JSON-Body (ohne Angabe `{}`),
  `credentials: same-origin`, wirft nie; Ergebnis `{ok, status, data}` oder `ApiError {code,
  message, status, retryAfterSeconds, details}` (Netzwerkfehler `network`, unerwartete Antwort
  `invalid_response`). Jede Antwort prüft ein Parser. 401 einer nicht öffentlichen Anfrage ruft den
  Abmelde-Weg des Routers (Sitzung vergessen, Hinweis, `anmelden?weiter=<Seite>`); öffentliche
  Aufrufe (`public: true`, z. B. `auth/login`, `auth/me` im Wächter) behandeln 401 selbst.
  Downloads (CSV-Export, Vorlage des Imports) über `apiDownload` (ebenda): GET mit Cookie und
  `X-Client-Api`, Ergebnis `{blob, fileName}` (Name aus `Content-Disposition`, nur `a-z0-9.-`, sonst
  Ersatzname), Fehler wie bei `apiRequest` als `ApiError` (401 meldet ab); die Seite speichert die
  Datei per `saveDownload` (`src/runtime/download.ts`: `blob:`-Adresse und `<a download>`; kein Link
  direkt auf die API, Fehler kämen sonst als Datei).
- Datenschicht `src/data/` (KONZEPT.md "User am Handy – Entscheidungen", 13 und 14): Geräte und
  Standorte lesen Seiten NUR hier (`deviceStore`, `locationStore` aus `src/data/index.ts`), nie direkt
  über `src/api/`; sie ist später die lokale Kopie für den Offline-Modus. `DeviceStore` lädt
  `GET devices` in Blöcken zu 200 (`DEVICE_PAGE_LIMIT`, nacheinander), entfernt doppelte IDs und
  lädt einmal neu, wenn sich `total` während des Ladens ändert oder die Anzahl nicht passt; mehr als
  `MAX_LOCAL_DEVICES` (2000) → Modus `server` (nur der erste Block, Suche später per `q`). Fehler
  behalten die bisherige Liste (`error` im Zustand). `upsert` übernimmt ein Gerät aus einer Antwort
  (nur neuere Version, ausgesondert → aus der Liste). Auffrischen über `Refresher`
  (`refresh(reason)`): höchstens eine Abfrage gleichzeitig, `changed` während einer laufenden →
  danach noch einmal; Mindestabstand seit dem letzten Erfolg `REFRESH_MIN_INTERVAL_MS` (`open` 5 s,
  `visible` 30 s, `changed` 0); `visible` erst, nachdem die Daten einmal angefordert wurden.
  `installDataLayer` (in `main.ts`, nur unter Städten): bei `visibilitychange` (sichtbar)
  `refresh('visible')`, bei `onSessionEnded` (`src/auth/session.ts`, mit bisherigem Konto und Grund
  `logout` = Abmelden beim Server erfolgreich, `ended` = `resetSession` z. B. nach 401 oder
  `setSession(null)`, `replaced` = anderes Konto per `setSession`) `clear()` aller Stores und die
  Bestätigung einer Buchung verwerfen; nur bei `logout` zusätzlich „Zuletzt benutzt“ (Geräte und
  Standorte) des Kontos löschen. Im Modus `server`: `searchServer(q, limit)` (`q` getrimmt,
  höchstens 100 Zeichen) und `findMany(ids)` (einzeln `GET devices/{id}`). `findByCode(code)`: im
  Modus `local` `findByCode` über die Liste, sonst (auch vor dem ersten Laden) `GET
  devices?scan_code=…&limit=1` (nur wenn `isPossibleScanCode`: Regeln wie `ScanCode`, sonst gäbe
  es 400), dann `q` mit der Kennung (`identifierText`, nur bis 30 Zeichen, `limit=200`) und der
  genaue Vergleich in der App. `fetchCurrent(id)`
  (`GET devices/{id}`, übernimmt den Stand per `upsert`) und `book(id, request)` (siehe Umbuchen).
- Suchen (`SearchPage`, KONZEPT.md Entscheidungen 14, 16, 17, 20, 24, 27): lokale Suche
  `searchDevices` (`src/data/deviceSearch.ts`): Wörter mit UND über Kennung, Scan-Code,
  Seriennummer, Hersteller, Modell, Gerätetyp, Standortpfad; Vergleichsform `searchKey` (ohne
  Groß-/Kleinschreibung und Akzente, ß = ss); Reihenfolge Kennung genau, Kennung beginnt mit dem
  ersten Wort, Rest (je in der Reihenfolge der Liste); höchstens `MAX_SEARCH_HITS` (50), darüber
  „Mehr als 50 Treffer – bitte weiter eingrenzen.“. Modus `server`: 300 ms nach dem letzten
  Tastendruck `searchServer`, ältere Antworten verworfen. Enter (auch USB-Scanner) wie ein Scan:
  `findByCode` (Scan-Code exakt, Rand ohne Leerzeichen/Tab/CR/LF wie `ScanCode`, dann Kennung per
  `codeKey`: zusammengesetzte Form, Leerraum am Rand weg, mehrere Leerzeichen wie eines, dann
  `identifierKey`) → Gerät öffnen (im Modus `server` zuerst unter den Treffern, sonst
  `deviceStore.findByCode`); sonst genau ein Treffer → öffnen; mehrere → Tastatur schließen;
  keiner → „Kein Gerät mit diesem Code gefunden.“, Text bleibt. `identifierKey` schreibt klein wie
  `LOWER()` in MariaDB unter `utf8mb4_unicode_ci` (Unicode von UCA 4.0.0): je Zeichen (kein Sigma
  am Wortende), İ → i, Großbuchstaben aus `DATABASE_UNCASED` und alle außerhalb der BMP bleiben
  (dort kennt die Kollation keine Kleinbuchstaben), dann ẞ → ß. Gemeinsame Testfälle
  `tests/Fixtures/identifier-key-cases.json` (`cases`: Kennung → `identifier_key` über eine echte
  Zeile; `letters`: alle `\p{Lu}`/`\p{Lt}` laut Node → `LOWER()`), geprüft von
  `IdentifierKeyCasesTest` (PHPUnit, Testdatenbank) und `deviceSearch.spec.ts` (Vitest, meldet auch
  Buchstaben einer neueren Node-Version, die in der Datei fehlen). Änderungen an `identifier_key`
  daher immer mit `identifierKey` und neuen Fällen.
- Code scannen (`CodeScanner` in `src/components/`, KONZEPT.md Entscheidungen 17 und 27; auch in
  „Code zuordnen“ des Gerätedetails, Hinweis nach dem Lesen per `detectedText`): Knopf
  „Code scannen“ unter dem Suchfeld; der Scanner ist eine Ansicht von „Suchen“ über `?scannen=1`
  (wie die Standortwahl: Zurück-Knopf schließt nur ihn, `closeQueryView`), bildschirmfüllend
  (`Teleport` nach `body`, `role="dialog"`, Kopfzeile `FocusHeader` „Scanner schließen“, „Abbrechen“,
  Escape). Beim Öffnen gleichzeitig Lesemodul (`import()` von `src/scanner/barcodeReader.ts`:
  zxing-wasm `zxing-wasm/reader`, `prepareZXingModule` mit `locateFile` auf die WASM-Datei des
  Builds per `?url`, nie das CDN; `textMode: 'Plain'`, damit GS1-Trennzeichen wie im Scan-Code
  bleiben; alle Formate, ein Code) und Kamera (`openCamera` in `src/scanner/camera.ts`:
  Rückkamera, ohne Ton, 1280 × 720 ideal); alle `SCAN_INTERVAL_MS` (120 ms) ein Bild über eine
  Canvas (höchstens `MAX_FRAME_EDGE` 1280 px) lesen. Erster Code: Kamera sofort aus, dann
  `deviceStore.findByCode`; gefunden → `router.replace` auf das Gerät (Zurück führt zu „Suchen“);
  sonst Scanner zu, Text (`trimCode`) im Suchfeld, „Kein Gerät mit diesem Code gefunden.“ bzw.
  `ErrorNotice`, Fokus auf „Code scannen“. Fehler (`CAMERA_ERROR_MESSAGES`): `unsupported` (kein
  `navigator.mediaDevices`, ohne „Erneut versuchen“), `denied`, `missing`, `busy`, `load` (Lesemodul
  nicht ladbar). Schließen, Zurück und Verlassen beenden die Kamera immer. Browser-Tests
  `tests/e2e/scanner.spec.ts` mit simulierter Kamera (`tests/e2e/support/camera.ts`: Codes mit dem
  Schreibmodul von zxing-wasm in Node, Kamerabild als Canvas; in ALLEN Browsern und Systemen derselbe
  Weg: `getUserMedia` liefert einen Ersatz-Stream aus eigenen Klassen, das Init-Skript ersetzt für
  ein Video damit `srcObject`, `play`, Größe und `drawImage`. Kein echter `MediaStream`:
  Playwright-WebKit unter Windows hat keinen, und WebKit räumt das JS-Objekt einer echten Spur weg,
  dann ginge ein Zähler an der Spur verloren (rote CI unter Linux). `cameraCounts`: `opened`,
  `stopped` (Spuren je einmal), `attached` (Videos, die den Stream noch halten); nach jedem Ende
  `cameraReleased(n)`, auch bei Fehlern wie „Lesemodul nicht ladbar“), echt sind Lesemodul,
  WASM-Datei, CSP und PHP; Unit-Tests
  `camera.spec.ts`, `deviceSearch.spec.ts`, `deviceStore.spec.ts`. `devicesEndpoint` kennt dafür
  auch `scan_code`.
  „Zuletzt benutzt“ (`src/data/recentDevices.ts`, `recentDeviceIds`/`rememberDevice` aus
  `src/data/index.ts`): localStorage `ff-recent-devices:<kürzel>:<konto-id>`, nur IDs, höchstens 8,
  neueste zuerst; `DevicePage` merkt sich das Gerät beim Öffnen; angezeigt nur Geräte der aktuellen
  Liste (im Modus `server` per `findMany`, auch bei jeder Rückkehr auf „Suchen“). Je Gerät klein
  unter dem Standort die letzte Umbuchung/Statusänderung (`stateChangedAt` aus `state_changed_at`,
  `changeTimeText` in `src/data/changeTime.ts`, Ortszeit: „gerade eben“, „vor 5 Min.“ unter 1 h,
  „heute 14:02“, „gestern 18:30“, sonst „12.10.2026“; `SearchPage` frischt die Uhr alle 30 s und
  bei `visibilitychange` auf); nach eigener Buchung sofort aus der Antwort (`upsert`). Karten
  `DeviceCard` (optional `changeTime`), Status `StatusBadge` (Symbol und Wort). Browser-Tests, die „Suchen“ nur durchlaufen, simulieren `GET api/v1/devices` mit
  `NO_DEVICES` (`tests/e2e/support/devices.ts`; dort auch `deviceJson`, `devicesEndpoint`).
- Umbuchen (`DevicePage`, `geraete/<id>`, KONZEPT.md Entscheidungen 13, 15, 18, 21, 22): Stand
  sofort aus `deviceStore.find`, dann `fetchCurrent`; ausgesondert → Hinweis ohne Formular, 404 →
  „Dieses Gerät gibt es nicht (mehr).“. Standort: aktueller und die letzten 3 eigenen Ziele
  (`recentLocationIds`/`rememberLocation` aus `src/data/index.ts`, `src/data/recentLocations.ts`,
  localStorage `ff-recent-locations:<kürzel>:<konto-id>`; gemeinsame Klasse `RecentIds` in
  `src/data/recentIds.ts`, auch für „Zuletzt benutzt“), nur aktive aus `locationStore`
  (`locationPaths`). Status über drei Tasten (`aria-pressed`, Bezeichnungen `STATUS_LABELS` wie
  `DeviceStatus::label()`, per Test). Auswahl `null` = unverändert (folgt dem aktuellen Stand);
  gesendet werden nur geänderte Felder (`deviceStore.book` → `POST devices/{id}/state`, online nie
  `occurred_at`), nichts geändert → Hinweis ohne Anfrage. `client_ref` (`ClientRefKeeper` in
  `src/data/booking.ts`, UUID v4): gleicher Inhalt (Gerät, Version, Felder) behält die Kennung
  (erneutes Senden nach einem Netzwerkfehler wirkt nicht doppelt), anderer Inhalt bekommt eine neue.
  Kommentar: Zeilenumbrüche und Steuerzeichen → Leerzeichen (`normalizeComment`, der Server nimmt
  nur eine Zeile), höchstens 500; Pflicht mit mindestens 5 Zeichen (`commentLength`, Unicode-Zeichen)
  nur, wenn der Status auf „defekt“ WECHSELT (dann rot „Was ist defekt?“). 409 `version_conflict`:
  Stand aus `details.device` übernehmen (Kopf und Liste), eigene Wahl bleibt, nicht geänderte Felder
  folgen dem neuen Stand, Hinweis; erneut speichern sendet die neue Version mit neuer `client_ref`.
  Andere Fehler: `ErrorNotice`, Eingaben bleiben. Erfolg: Liste übernimmt den Stand und lädt neu
  (`changed`), Gerät in „Zuletzt benutzt“, Ziel (nur bei `applied`) unter die Standorte, Bestätigung
  (`setBookingNotice`, sessionStorage `ff-booking-notice`, übersteht ein Neuladen; `applied` grün
  „M1 → HLF 20 / G2 · einsatzbereit“, sonst `result.message`), dann
  `returnTo(router, ['search', 'inspections', 'devices', 'device-details', 'notice'])` (`src/router/back.ts`:
  ist eine der Seiten die vorige, `back()`, sonst `replace` auf die erste, also „Suchen“ nach einem
  direkt geöffneten Link). Die Seite zeigt die Bestätigung (`BookingNoticeMessage`, holt sie per
  `takeBookingNotice`): „Suchen“ bis zur nächsten Eingabe, „Prüffristen“, die Geräteliste, das
  Gerätedetail und der Hinweis des Protokolls in der Admin-Konsole bis zum Verlassen (Gerätedetail
  und Hinweis bis zur nächsten Aktion). Für
  Admins zusätzlich der Link „Gerätedetail (Admin)“ unter „Details und Verlauf“. Nach jeder Buchung `locationStore.refresh('changed')` und
  `adminDeviceList.refresh('changed')` (nur, wenn die Geräteliste schon geladen ist)
  (Gerätezahlen). Browser-Tests mit der
  Umbuchen-Ansicht simulieren `GET api/v1/devices/{id}` (`deviceDetail`) und `GET api/v1/locations`
  (`LOCATIONS`, mit Gerätezahlen), beide in `tests/e2e/support/devices.ts`; Tests in
  `tests/e2e/booking.spec.ts`.
- Prüffristen (`InspectionsPage`, `prueffristen`, KONZEPT.md Entscheidung 25): Abschnitte
  „Überfällig“ und „In den nächsten 30 Tagen fällig“ (`INSPECTION_WINDOW_DAYS` = 30, per Test gleich
  `InspectionWindow::DEFAULT_DAYS`) mit Anzahl. Im Modus `local` aus der Geräteliste
  (`inspectionOverview` in `src/data/inspections.ts`, heute = `localDateKey` der Ortszeit über
  `useNow`, Tage per `daysBetween` ohne Zeitzone; Grenzen wie `GET inspection-due`: überfällig
  Prüfdatum < heute, bald fällig heute bis heute + 30; sortiert nach Prüfdatum, bei Gleichstand wie
  die Liste nach Kennung; ausgesonderte nie), daher nach eigener Buchung sofort aktuell. Im Modus
  `server` `fetchServerInspections` (`src/data/index.ts`, `GET inspection-due?days=30&limit=200`,
  `src/api/inspections.ts`; Geräte als `DeviceSummary` = `DeviceJson::summary`), nach jeder neu
  geladenen Liste erneut, „Angezeigt werden die ersten N.“, wenn `total` größer ist. Je Gerät
  `DeviceCard` (Typ `DeviceSummary`, Zusatzzeile per Slot) mit „Prüfung: 01.10.2026 · seit 28 Tagen
  überfällig“ (rot, `--status-defective-fg`) bzw. „· heute/morgen/in N Tagen fällig“ (gelb,
  `--status-maintenance-fg`; `inspectionText`). Antippen öffnet das Umbuchen; nach dem Speichern
  zurück auf „Prüffristen“ mit Bestätigung (siehe Umbuchen). Tests
  `tests/e2e/inspections.spec.ts`, `tests/unit/inspections.spec.ts`, `inspectionWindow.spec.ts`.
- Details und Verlauf (`DeviceDetails` in `src/components/`, KONZEPT.md Entscheidung 15): Link
  „Details und Verlauf“ unten auf der Umbuchen-Seite (auch bei ausgesonderten Geräten), eine
  Ansicht derselben Seite über `?details=1` wie die Standortwahl (Eingaben bleiben, Zurück-Knopf
  schließt nur die Ansicht; `FocusHeader` mit `backAction` = `closeQueryView` und `focusTitle`).
  Inhalt: aktueller Standort, Status, nächste Prüfung (überfällig rot, Vergleich mit heute in der
  Ortszeit), Stammdaten (leer „–“, Scan-Code mit `scanCodeDisplay` wie `ScanCode::display`,
  Beschaffungsdatum und Notiz aus `GET devices/{id}`: `fetchCurrent` liefert `DeviceDetail`), dann
  der Verlauf (Bausteine `DeviceMasterData` und `DeviceHistoryList`, auch im Gerätedetail der
  Admin-Konsole): `deviceHistory(id)` aus `src/data/index.ts` (`DeviceHistory` in
  `src/data/deviceHistory.ts`, `GET devices/{id}/events` in Blöcken zu `EVENT_PAGE_LIMIT` = 20,
  „Weitere Einträge laden“, doppelte IDs entfernt, Fehler behält die geladenen Einträge). Je Eintrag
  `type_label`, Aktionszeit (`eventTimeText`: „heute 14:02“, „gestern 18:30“, sonst „12.10.2026,
  09:15“), „von <Name>“, Zeilen aus `eventLines` (`src/data/eventDisplay.ts`: Standort und Status
  „alt → neu“, bei nicht übernommenen „(gewünscht)“ und „Grund“, Prüfdatum und Stammdaten mit
  „keins“/„leer“, „Eingang beim Server“ ab 1 Minute nach der Aktionszeit) und Kommentar. Tests in
  `tests/e2e/deviceDetails.spec.ts` (Hilfen `fullDevice`, `eventJson`, `eventsEndpoint` in
  `tests/e2e/support/devices.ts`; `deviceDetail` liefert alle Felder) und
  `tests/unit/deviceHistory.spec.ts`.
- Profil (`ProfilePage`, `profil`, KONZEPT.md "User am Handy", Profil): `AccountPanel` (Konto,
  Abmelden), `ThemeModeSetting` (Radiogruppe System/Hell/Dunkel, sofort `saveThemeMode` und
  `applyThemeMode`, Hinweis per `matchMedia`), `PasswordChangeForm` (aufklappbar; `changePassword`
  → `POST auth/password` mit Passwörtern unverändert; vorher leeres aktuelles Passwort und
  `checkPassword` mit E-Mail, Name, Anzeigename und Stadt der Sitzung; Erfolg: `setSession` mit der
  Antwort (neue Sitzung, dasselbe Konto), Formular zu, Hinweis, Liste der Geräte neu; 403
  `invalid_current_password` → Meldung am Feld „Aktuelles Passwort“, Feld geleert und fokussiert; 422
  `weak_password` → `serverError` von `NewPasswordField`; 429 → `useCountdown`, Knopf „Wieder
  möglich in m:ss“; ungespeicherte Eingaben per `useUnsavedInput`), `SessionList`
  (`fetchSessions`, `endSession` → `DELETE auth/sessions/{id}` (404 = schon beendet, gilt als
  erledigt), `logoutOtherSessions` → `POST auth/sessions/logout-others` nach `ConfirmDialog`; nach
  jeder Aktion neu laden; Anzeige `src/auth/sessionList.ts`: dieses Gerät zuerst, ohne eigenen Knopf,
  „Angemeldet heute 08:05“, andere „Zuletzt aktiv vor 5 Min. · angemeldet 12.10.2026“ über
  `changeTimeText`; Knöpfe „Abmelden“ mit `aria-label` „<Gerät> abmelden“). Browser-Tests, die das
  Profil nur öffnen, simulieren `GET api/v1/auth/sessions` mit `CURRENT_SESSION_ONLY`
  (`tests/e2e/support/profile.ts`, dort auch `sessionJson`); Tests in `tests/e2e/profile.spec.ts`,
  `tests/unit/sessionList.spec.ts`, `countdown.spec.ts`, `auth.spec.ts`.
- Standortwahl („Anderen Standort suchen …“, `LocationPicker` in `src/components/`, Logik
  `src/data/locationSearch.ts`): eine Ansicht der Umbuchen-Seite, geöffnet über `?standortwahl=1`
  am selben Pfad (kein eigener Pfad: Eingaben bleiben, `installUpdateGuard` lädt bei gleichem Pfad
  nicht neu, der Zurück-Knopf schließt nur die Auswahl). Schließen (Abbrechen oder Wahl) über
  `closeQueryView` (`src/router/back.ts`: war die Adresse ohne Parameter die vorige, `back()`, sonst
  `replace`). Ohne Suchbegriff `locationGroups`: Standorte der Ebene 1 (ohne gesperrte) mit
  `device_count_total`; mit Unterstandorten klappt die ganze Zeile auf (`aria-expanded`, zu Beginn
  die Gruppe des gewählten Standorts), erster Eintrag `WHOLE_LABELS` („Ganzes Fahrzeug, ohne Fach“,
  bei Räumen „Ganzer Raum, ohne Fach“, Extern „Ganzer Standort, ohne Fach“) = der Standort selbst,
  dann die Fächer; ohne Unterstandorte wählt Antippen. Mit Suchbegriff `searchLocations`: Wörter mit
  UND (Vergleichsform `searchKey`) im Namen der Ebene 1 bzw. im ganzen Pfad eines Unterstandorts
  („hlf g1“), flach, Fächer mit ihrem Fahrzeug darunter, „N Treffer“, sonst „Kein Standort passt zu
  „…“.“. Markierung „aktuell“ und Häkchen „gewählt“. Ein gewählter Standort, der nicht unter den
  zuletzt benutzten steht, erscheint beim Umbuchen nach dem aktuellen mit „gewählt“. Fokus: beim
  Öffnen die Überschrift, danach „Anderen Standort suchen …“. Tests in
  `tests/e2e/locationPicker.spec.ts` und `tests/unit/locationSearch.spec.ts`.
- Admin-Konsole (KONZEPT.md "Admin-Konsole – Entscheidungen", 29–38; Seiten mit `layout: 'admin'`,
  nur `admin` unter einer Stadt; den Bereich des Super-Admins siehe unten): `App.vue` zeigt `AdminMenu` (`src/components/`) und daneben `AppNotices` (Hinweise
  zu Stadt, Sitzungsprüfung und Updates, in allen Darstellungen gleich) und die Seite. Menü
  (`AdminMenuNav`, Einträge in `ADMIN_MENU`, `src/router/adminMenu.ts`, jeder Eintrag kommt mit
  seiner Seite; Symbole `AdminMenuIcon`): ab 1024 px links mit Text (240 px), 768–1023 px eingeklappt
  auf Symbole (Text nur für Bildschirmleser, Tooltip über `title`), darunter Kopfleiste in der
  Stadtfarbe mit „Menü öffnen“ (☰) und einer Schublade (Reka UI `Dialog` „Menü“, Escape und „Menü
  schließen“, ein Seitenwechsel schließt sie). Oben Logo (192 px) bzw. Standard-Symbol und Name der
  Stadt, unten „Einstellungen“ (`ADMIN_SETTINGS`), der Name des Kontos als Text und „Abmelden“;
  Zähler an Einträgen (`badge`:
  `pendingUsers`, `openNotices`) aus `adminOverview`; `sections` hebt einen Eintrag auch auf
  Unterseiten hervor („Geräte“ auf dem Gerätedetail). Auf den Handy-Seiten behält der Admin die Leiste
  unten mit „Dashboard“. Dashboard (`AdminDashboardPage`, Mockup „Main“) aus `adminOverview`
  (`src/data/adminOverview.ts`, `AdminOverviewStore` mit `Refresher`, in `installDataLayer`): vier
  Abfragen gleichzeitig, `GET device-summary`, `GET users?status=pending`, `GET
  devices?status=maintenance,defective&sort=status&dir=desc&limit=10` (`NOT_READY_LIMIT`) und `GET
  device-events?limit=8` (`ACTIVITY_LIMIT`); nur wenn alle antworten, gilt der neue Stand, sonst
  bleibt der bisherige mit Fehler und „Erneut versuchen“. Kacheln (`src/data/dashboard.ts`):
  Einsatzbereitschaft „42 von 45“ mit Balken (`<progress>`), nicht einsatzbereit „2 defekt · 1 in
  Wartung“, Prüffristen (führt zu `prueffristen`), offene Hinweise plus Freischaltungen; Listen „Nicht
  einsatzbereit“ („… und N weitere“) und „Letzte Aktivitäten“ (Zeit, Kennung, Art, Wer, Zeilen aus
  `eventLines`, Kommentar; nicht übernommene abgesetzt); Geräte öffnen das Gerätedetail.
  Einsatzbereitschaft führt zu `geraete`, nicht einsatzbereit und „… und N weitere“ zu
  `geraete?status=maintenance,defective`; in der Kachel „Offene Hinweise“ führen „Hinweise ansehen“
  (nur mit offenen) zu `protokoll` und „Freischaltungen ansehen“ (nur mit wartenden) zu `benutzer`.
  Browser-Tests, die als Admin auf
  dem Dashboard landen, simulieren dessen Abfragen mit `ADMIN_OVERVIEW` (`tests/e2e/support/admin.ts`,
  dort auch `adminOverview`, `summaryJson`, `userJson`, `tenantEventJson`); Tests in
  `tests/e2e/adminConsole.spec.ts` (Menü in drei Breiten, Dashboard) und
  `tests/unit/adminOverview.spec.ts`.
- Standorte und Gerätetypen der Admin-Konsole (`LocationsPage`, `standorte`; `DeviceTypesPage`,
  `geraetetypen`; nur `admin`, `layout: 'admin'`, Menü „Standorte“ und „Gerätetypen“ nach „Suchen“):
  Lesen über eigene Stores (`adminLocationStore` = `LocationStore` mit `GET
  locations?include_inactive=1`, `deviceTypeStore` = `DeviceTypeStore` mit `GET
  device-types?include_inactive=1`, beide in `installDataLayer`), Ändern nur über `src/data/index.ts`
  (`addLocation`, `renameLocation`, `setLocationActive`, `addDeviceType`, `renameDeviceType`,
  `setDeviceTypeActive`; API in `src/api/locations.ts` und `src/api/deviceTypes.ts`): nach Erfolg lädt
  die Verwaltung neu (abgewartet), `locationStore`, `deviceStore` und `adminOverview` nur, wenn sie
  schon geladen sind. Prüfung beim Tippen (`src/data/masterData.ts`): `nameProblem` wie
  `DeviceNameRules` (Rand wie PHP `trim()`, Länge in Unicode-Zeichen, 100 bzw. 50, keine `\p{Cc}`),
  gleicher Name unter den Geschwistern (auch gesperrten/deaktivierten) per `sameName` (`searchKey`);
  verbindlich bleibt der Server (409 `name_taken` am Feld). Standorte als Baum (`filterLocationTree`:
  Wörter mit UND im Namen bzw. Pfad, gesperrte nur mit „Gesperrte anzeigen (N)“), Symbol je Art
  (`src/components/locationIcons.ts`, auch in `LocationPicker`), Gerätezahl (Ebene 1
  `device_count_total`); Knöpfe „Fach in … anlegen“ (nur aktive Ebene 1) und „… bearbeiten“
  (Stift). Fenster `FormDialog` (Reka UI `Dialog`, Slot `extra` für Sperren/Deaktivieren abseits,
  `returnFocus` = öffnender Knopf, da WebKit beim Klick nicht fokussiert; `variant="danger"` = roter
  Knopf, z. B. Aussondern; `unsaved` siehe „Fenster mit Eingaben“ unten). Sperren nur leer
  (`lockBlocker` aus dem Baum: Geräte direkt dort und aktive Fächer; Knopf gesperrt mit Hinweis), dann
  `ConfirmDialog`; 409 des Servers als Meldung auf der Seite. Entsperren eines Fachs erst nach dem
  übergeordneten Standort. Gerätetypen als Tabelle (`deviceTypeRows`: Name natürlich per
  `Intl.Collator` mit `numeric`, Gerätezahl, Gleichstand nach Name; Spaltenköpfe mit `aria-sort`, am
  Handy Karten und Knöpfe „Sortieren“ statt `<select>`, siehe CSP), Deaktivieren mit Rückfrage
  (vorhandene Geräte behalten den Typ). Erfolg als `success-notice`. Der Name eines Standorts bzw.
  Typs führt zur Geräteliste (`geraete?standort=<id>` bzw. `?typ=<id>`). Browser-Tests
  `tests/e2e/locations.spec.ts`,
  `tests/e2e/deviceTypes.spec.ts` mit zustandsbehafteten Servern (`locationServer`,
  `deviceTypeServer`, je Test neu über `delegate` und `test.beforeEach`;
  `tests/e2e/support/masterData.ts`), waagerechter Überstand am Handy über `horizontalOverflow`
  (`tests/e2e/support/layout.ts`: `scrollWidth` gegen die Breite von `body`, nicht `clientWidth`, das
  Playwright-WebKit unter Windows im Handy-Profil um eine 20 px breite Bildlaufleiste kürzt);
  Unit-Tests `masterData.spec.ts`, `deviceTypesApi.spec.ts`,
  `deviceTypeStore.spec.ts`, `locationsApi.spec.ts`, `router.spec.ts` (jeder Menüeintrag hat eine
  Seite).
- Geräteliste der Admin-Konsole (`DevicesPage`, `geraete`, nur `admin`, `layout: 'admin'`, Menü
  „Geräte“ nach „Suchen“; Mockups „Geraete“ und „AdminHandy“, KONZEPT.md Entscheidung 33): anders
  als „Suchen“ ohne lokale Kopie, Filter und Sortierung rechnet der Server (wie beim Export, auch
  ausgesonderte und mehr als 2.000 Geräte). Store `adminDeviceList` (`AdminDeviceList` in
  `src/data/adminDeviceList.ts`, in `installDataLayer`): `show(filter)` lädt bei neuem Filter die
  erste Seite neu (Liste leer bis zur Antwort, ältere Antworten verworfen), bei gleichem nur
  `refresh('open')`; `more()` hängt die nächsten `ADMIN_DEVICE_PAGE_SIZE` (50) an („Weitere laden“,
  „50 von 312 angezeigt“, doppelte IDs entfernt); Auffrischen lädt so viele wie geladen (höchstens
  200). Filter (`src/data/deviceList.ts`) stehen in der Adresse (`filterFromQuery`/`filterToQuery`,
  `router.replace`): `suche` (an den Server als `q` nach 300 ms, höchstens 100 Zeichen), `typ`,
  `standort` (immer mit Fächern, `include_sublocations=1`), `status` (`ready`, `maintenance`,
  `defective`, kommagetrennt), `ausgesonderte=1` (mit Status als weiterer Status `retired`, sonst
  `include_retired=1`), `sortierung` (`DeviceSort`), `richtung` (`desc`); Unbekanntes fällt still auf
  den Standard. Oberfläche: Suchfeld, `AppSelect` „Gerätetyp“ und „Standort“ (aus `deviceTypeStore`
  bzw. `adminLocationStore`, gesperrte/deaktivierte markiert), Status als Knöpfe mit `aria-pressed`,
  „Ausgesonderte anzeigen“, „Filter zurücksetzen“, Anzahl („45 Geräte“, mit Filter „12 Geräte
  gefunden“). Suche nur in den Feldern von `q` (Gerätetyp und Standort wählt man über die
  Auswahllisten; live geprüft 30.10.2026). Tabelle Kennung (öffnet das Gerätedetail), Gerätetyp,
  Standort, Status, Nächste
  Prüfung (überfällig rot, bis 30 Tage „bald fällig“ gelb, `dueState`; ausgesonderte nie), Spaltenköpfe
  mit `aria-sort`; unter 768 px Karten mit `AppSelect` „Sortieren nach“ und Knopf für die Richtung.
  „Export (CSV)“: `GET devices/export` mit denselben Filtern und derselben Sortierung
  (`downloadDeviceExport`, `apiDownload`), Fehler als `ErrorNotice`. „Neues Gerät“ (Stadtfarbe)
  führt zu `geraete/neu`, „Import (CSV)“ zu `geraete/import`. Browser-Tests `tests/e2e/devices.spec.ts`
  (Server-Attrappe `deviceServer` mit Status, Typ, Standort samt Fächern, `q` und Seiten; Download über
  `MockResponse.text`/`contentType`); Unit-Tests `deviceList.spec.ts`, `adminDeviceList.spec.ts`,
  `client.spec.ts` (`apiDownload`).
- Sammelaktionen der Geräteliste (KONZEPT.md Entscheidung 31, Schritt 10): erste Spalte bzw. Karte mit
  Kontrollkästchen („M1 auswählen“; ausgesonderte gesperrt, „… (ausgesondert, nicht wählbar)“), im
  Kopf „Alle angezeigten Geräte auswählen“ (auch `indeterminate`). Auswahl = ID → zuletzt bekannter
  Stand (`DeviceListItem`, die Version wird mitgesendet); ein neuerer Stand beim Auffrischen ersetzt
  ihn, ein neuer Filter hebt die Auswahl auf. Sind alle angezeigten gewählt und passen mehr (bis
  `MAX_LOCAL_DEVICES` = 2000), „Alle N Geräte der Liste auswählen“ (`adminDeviceList.all()`: alle
  Seiten zu 200 des Filters, die angezeigte Liste bleibt). Leiste „N Geräte ausgewählt“ mit
  „Prüfdatum setzen …“ (`DeviceBulkDueDialog`: Datum, leer = entfernen, Kommentar) und „Umbuchen /
  Status ändern …“ (`DeviceBulkStateDialog`: „Neuer Standort“ als `AppSelect` mit „Standort nicht
  ändern“ und nur aktiven Standorten bzw. Fächern unter aktiven, „Neuer Status“ als Radiogruppe mit
  „Status nicht ändern“; mindestens eines, bei „defekt“ Kommentar „Was ist defekt?“ mit mindestens 5
  Zeichen wie beim Umbuchen), „Auswahl aufheben“. Senden über `bulkSetInspectionDue` bzw.
  `bulkChangeState` (`src/data/index.ts`; API `postBulkInspectionDue`, `postBulkState` in
  `src/api/deviceBulk.ts`, `BULK_MAX_DEVICES` = 50 per Test gleich `DeviceBulkHandler::MAX_DEVICES`):
  `runInBlocks` (`src/data/deviceBulk.ts`) sendet Blöcke zu 50 nacheinander, das Fenster zeigt „8
  von 12 gesendet …“ und bleibt solange offen (`useUnsavedInput`); `bulk/state` mit neuer
  `client_ref` je Gerät. Scheitert eine ganze Anfrage (z. B. Netzwerk), endet der Lauf, der Rest gilt
  als nicht gesendet. Danach `deviceStore.upsert` je Gerät und Auffrischen der geladenen Listen.
  Ergebnis über der Leiste (`bulkResultText`: „Prüfdatum: 10 Geräte geändert, 1 unverändert, 1 nicht
  möglich.“, grün nur ohne Fehler), Liste „Nicht geändert (bleiben ausgewählt)“ mit `bulkErrorText`
  (`version_conflict` „Inzwischen geändert – bitte prüfen und erneut senden.“); übernommene und
  unveränderte verlassen die Auswahl, Fehler (mit aktuellem Stand) und nicht gesendete bleiben.
  Browser-Tests `tests/e2e/deviceBulk.spec.ts` (Server-Attrappe bucht je Gerät mit Version, `bump`
  simuliert eine fremde Änderung, `failNextRequest` einen Netzwerkfehler); Unit-Tests
  `deviceBulk.spec.ts`.
- Gerätedetail der Admin-Konsole (`DeviceDetailPage`, `geraete/<id>/details`, `<id>` wie die
  Platzhalter im Backend, nur `admin`, `layout: 'admin'`; KONZEPT.md "Bereiche des Admins", Geräte,
  und Entscheidung 33): „Zurück“ (`goBack`, sonst `geraete`), Kennung als Überschrift mit Typ, Karte
  mit Standort, Status (groß) und nächster Prüfung (`dueState`: überfällig rot, bald fällig gelb),
  Stammdaten (`DeviceMasterData`) und Verlauf (`DeviceHistoryList`, ab 1200 px nebeneinander).
  Gerät per `deviceStore.fetchCurrent` (auch ausgesonderte; 404 „Dieses Gerät gibt es nicht
  (mehr).“). Aktionen: groß „Umbuchen / Status ändern“ (Link auf `geraete/<id>`, danach zurück hierher
  mit Bestätigung), „Bearbeiten“, „Prüfdatum ändern“, „Code zuordnen“; rot und abseits (Kasten unter
  den Stammdaten) „Aussondern …“; ausgesondert stattdessen ein Hinweis mit „Wieder aufnehmen“. Je
  Aktion ein `FormDialog` in `src/components/` (`DeviceEditDialog`, `DeviceDueDialog`,
  `DeviceCodeDialog`, `DeviceRetireDialog`, `DeviceReinstateDialog`), jeweils mit optionalem
  Kommentar für den Verlauf (eine Zeile wie beim Umbuchen) und `useUnsavedInput`. Prüfung beim Tippen
  und was gesendet wird in `src/data/deviceForm.ts` (wie `DeviceIdentifier`, `DeviceText`,
  `DeviceDate`, `ScanCode`, nie strenger; Datum aus `<input type="date">` als `JJJJ-MM-TT`, ohne
  Datumsauswahl auch `TT.MM.JJJJ`): gesendet werden nur geänderte Felder (binär verglichen, leer =
  `null`); nichts geändert → Fenster zu ohne Anfrage. Ändern nur über `src/data/index.ts`
  (`saveDeviceFields`, `saveInspectionDue`, `retireDevice`, `reinstateDevice`; API
  `postDeviceFields`, `postInspectionDue`, `postRetire`, `postReinstate` in `src/api/devices.ts`, Antwort
  `DeviceDetail`): nach Erfolg `deviceStore.upsert` und Auffrischen der schon geladenen Listen
  (`deviceStore`, `locationStore`, `adminLocationStore`, `deviceTypeStore`, `adminOverview`,
  `adminDeviceList`); 409 `version_conflict` → Stand aus `details.device` (`conflictDeviceDetail`) oben
  übernommen, Hinweis im Fenster, beim Bearbeiten folgen nicht geänderte Felder dem neuen Stand
  (`rebaseEditForm`); passt die Aktion nicht mehr (inzwischen ausgesondert bzw. aufgenommen), schließt
  das Fenster. 409 `identifier_taken` bzw. `scan_code_taken` am Feld. Nach jeder Aktion grüne Meldung
  (`success-notice`) und Verlauf neu. „Bearbeiten“: Kennung, Gerätetyp (aktive und der bisherige),
  Seriennummer, Hersteller, Modell, Beschaffungsdatum, Notiz (ohne Scan-Code). „Code zuordnen“: Feld
  mit Fokus (USB-Handscanner tippt, Enter speichert), Rand wie `trimCode`, Trennzeichen darunter als
  „Erkannt: …[GS]…“; „Mit der Kamera scannen“ öffnet `CodeScanner` als Ansicht `?scannen=1` (das
  Fenster schließt sich solange und öffnet sich mit dem gelesenen Code wieder, ohne zu speichern);
  „Code entfernen“ im Slot `extra`. „Aussondern“: Titel und Knopf nennen das Gerät, Grund freiwillig.
  „Wieder aufnehmen“: Status (Radiogruppe), Standort und Gerätetyp (`AppSelect`, gesperrte bzw.
  deaktivierte nur als bisheriger Wert, dann Hinweis und Knopf gesperrt), Kennung, „Scan-Code …
  behalten“; gesendet nur Abweichungen (`reinstateRequest`). Browser-Tests
  `tests/e2e/deviceAdmin.spec.ts` (zustandsbehafteter Server je Test über `delegate`, Kamera über
  `fakeCamera`); Unit-Tests `deviceForm.spec.ts`, `deviceAdminApi.spec.ts`, `router.spec.ts`.
- Neues Gerät der Admin-Konsole (`DeviceNewPage`, `geraete/neu`, Route `device-new`, nur `admin`,
  `layout: 'admin'`, im Menü unter „Geräte“; KONZEPT.md Entscheidung 32): eine Seite statt eines
  Fensters (Serienerfassung). Felder Kennung (Fokus beim Öffnen), Gerätetyp und Standort (`AppSelect`
  mit `placeholder` „Bitte wählen“, nur aktive Typen bzw. aktive Standorte und Fächer unter aktiven
  Standorten), Status (Radiogruppe, Standard einsatzbereit), Scan-Code (optional; Handscanner,
  „Mit der Kamera scannen“ als Ansicht `?scannen=1` mit `CodeScanner`, Vorschau „Erkannt: …[GS]…“),
  nächste Prüfung, Seriennummer, Hersteller, Modell, Beschaffungsdatum, Notiz, Kommentar für den
  Verlauf. Prüfung beim Tippen und Body in `src/data/deviceForm.ts` (`newDeviceProblems`,
  `newDeviceRequest`: leere optionale Felder weggelassen; ein stehen gebliebener Typ bzw. Standort,
  der inzwischen deaktiviert bzw. gesperrt ist, gilt als Problem). Anlegen über `createDevice`
  (`src/data/index.ts`, `postDevice` → `POST devices`, danach Auffrischen wie bei jeder Änderung).
  „Speichern und nächstes“ (erster Knopf, auch Enter in einem Feld): grüne Meldung „M24 angelegt.“ mit
  Link „Gerät öffnen“, dann `nextNewDeviceForm`: Gerätetyp, Standort und nächste Prüfung bleiben, die
  Kennung zählt weiter (`nextIdentifier`: Zahl am Ende + 1, führende Nullen bleiben, M99 → M100;
  ohne Zahl am Ende leer), alles andere leer, Fokus und Auswahl auf der Kennung. „Speichern“:
  Bestätigung per `setBookingNotice`, `router.replace` auf das Gerätedetail. Enter im Scan-Code
  speichert nie (Handscanner senden Enter), sondern führt zum nächsten Feld. 409 `identifier_taken`
  bzw. `scan_code_taken` am Feld, andere Fehler als `ErrorNotice` (bei `rule_violation` und
  `invalid_request` laden Typen und Standorte neu); nach einem Fehler Fokus auf das erste Feld mit
  `aria-invalid`. Ungespeichert (`useUnsavedInput`) = Formular anders als nach dem Öffnen bzw. nach
  „Speichern und nächstes“. „Zurück“ oben (`.detail-nav`/`.back-link` in `base.css`, auch im
  Gerätedetail) und „Abbrechen“ (`goBack`, sonst `geraete`). Unter 768 px eine Spalte, Knöpfe volle
  Breite. Browser-Tests `tests/e2e/deviceNew.spec.ts` (simulierter `POST devices` ab ID 100);
  Unit-Tests `deviceForm.spec.ts`, `deviceAdminApi.spec.ts`, `router.spec.ts`.
- Benutzerverwaltung der Admin-Konsole (`UsersPage`, `benutzer`, Route `users`, nur `admin`,
  `layout: 'admin'`, Menü „Benutzer“ mit Zähler `pendingUsers`; KONZEPT.md "Bereiche des Admins",
  Benutzer; API siehe "Benutzerverwaltung durch den Admin", unverändert): Store `userStore`
  (`UserStore`, `src/data/userStore.ts`, `GET users` ohne gelöschte, in `installDataLayer`), Ändern
  nur über `src/data/index.ts` (`approveUser`, `rejectUser`, `setUserLocked`, `saveUser`,
  `deleteUser`; API in `src/api/users.ts`): danach lädt `userStore` neu (abgewartet), `adminOverview`
  nur, wenn geladen; 404 und `rule_violation` laden die Liste ebenfalls neu; betrifft eine Änderung
  das eigene Konto, übernimmt die Sitzung den Anzeigenamen (`setSession`, gleiches Konto). Logik in
  `src/data/users.ts`. Oben (deutlich, nur mit wartenden) „N neue Anmeldungen warten auf
  Freischaltung“ (`pendingUsers`: Status `pending`, älteste zuerst) als Karten mit „Freischalten …“
  (`FormDialog`: Anzeigename vorbelegt mit dem Namen, Prüfung `displayNameProblem` wie `PersonName`;
  Rolle als Radiogruppe `ROLE_OPTIONS`, Standard Benutzer) und „Ablehnen …“ (`ConfirmDialog`, nennt
  die Mail). Darunter alle anderen Konten (`userRows`: Suche mit UND in Anzeigename, Name und E-Mail
  per `searchKey`; Spalten Name (Anzeigename, abweichender Name darunter `nameHint`, „Sie“ am eigenen
  Konto), E-Mail, Rolle, Status (`statusText`: gesperrt vor dem Status, „E-Mail unbestätigt“),
  Registriert; Spaltenköpfe mit `aria-sort`, am Handy Karten mit Knöpfen „Sortieren“ wie bei den
  Gerätetypen). Unbestätigte Konten haben statt des Stifts „Ablehnen …“ (sie verschwinden sonst nach
  7 Tagen von selbst). Der Stift öffnet Anzeigename (nur `active`) und Rolle, gesendet nur Geändertes
  (`userChanges`, erst Name, dann Rolle), nichts geändert → zu ohne Anfrage; abseits (Slot `extra`)
  „… sperren …“ (Rückfrage) bzw. „… entsperren“ und rot „… löschen …“ (Rückfrage, nennt „Gelöschter
  Benutzer #ID“). Am eigenen Konto Hinweis, Rolle gesperrt, kein Sperren und Löschen (der Server lehnt
  es ohnehin mit 409 ab). Fehler des Servers im Fenster (400 am Anzeigenamen) bzw. auf der Seite,
  Erfolg als `success-notice`. Browser-Tests `tests/e2e/users.spec.ts` (zustandsbehafteter Server je
  Test über `delegate`); Unit-Tests `users.spec.ts`, `router.spec.ts`.
- Einladungscodes (KONZEPT.md Entscheidung 30; API siehe "Einladung, Registrierung und
  E-Mail-Bestätigung", unverändert): zweiter Reiter der Benutzerverwaltung (Reka UI `Tabs`, Reiter
  „Konten“ mit Zahl der wartenden und „Einladungscodes“; in der Adresse als
  `benutzer?ansicht=einladungscodes` per `router.replace`, gleicher Pfad), Baustein
  `InvitationCodesPanel` (Inhalt bleibt beim Wechsel erhalten, `unmount-on-hide` aus; geladen erst,
  wenn der Reiter sichtbar ist). Store `invitationCodeStore` (`InvitationCodeStore`, `GET
  invitation-codes`, in `installDataLayer`), Ändern über `src/data/index.ts`
  (`createInvitationCode`, `revokeInvitationCode`; danach lädt die Liste neu, abgewartet), Logik in
  `src/data/invitations.ts`. Liste: aktive Codes (Bezeichnung, Status, „3 von 50 Registrierungen“,
  gültig bis, angelegt am/von), andere nur mit „Abgelaufene, aufgebrauchte und widerrufene anzeigen
  (N)“; „Widerrufen …“ nach `ConfirmDialog`. „Neuer Einladungscode“ (`FormDialog`): Bezeichnung
  (freiwillig, wie `InvitationCodeLabel`), „Gültig (Tage)“ 1–90 (Standard 30) und „Registrierungen
  höchstens“ 1–500 (Standard 50), Grenzen per Test gleich mit PHP (`MAX_VALID_DAYS`, `MAX_USES`,
  `MAX_LENGTH`). Danach erscheint der Code genau einmal (nur in der Antwort im Klartext; zählt als
  ungespeicherte Eingabe, bis „Schließen“ oder Widerrufen): groß, QR-Code (`QrCode`, `qrShape` in
  `src/components/qrCode.ts`: `uqr`, Fehlerkorrektur M, ein SVG-Pfad, Ruhezone 4 Module, dunkel auf
  hell über `--ink`/`--paper`) mit dem Link `registerLink` = `registrieren#code=XXXX-XXXX-XXXX`
  (Code nur im Fragment), „Aushang drucken“ (`window.print()`), „Link teilen“ (`shareLink`: Web Share
  API, abgebrochen = nichts, sonst oder ohne API Link in die Zwischenablage) und „Code kopieren“
  (`copyText`). Aushang `InvitationPoster` (Teleport nach `body`, auf dem Bildschirm unsichtbar;
  solange er besteht, trägt `<html>` die Klasse `has-print-poster`, und das Druck-CSS in `base.css`
  blendet alles andere aus, A4 mit 12 mm Rand): Logo (512 px oder Standard-Symbol) und Name der
  Stadt, „Jetzt registrieren“, QR-Code 100 mm, drei Schritte, Code in Klarschrift, Adresse ohne Code
  zum Abtippen (`registerAddress`), „Gültig bis …“ und Spam-Hinweis. Kein Nachdruck ohne neuen Code
  (der Server kennt nur den Hash). Browser-Tests `tests/e2e/invitationCodes.spec.ts` (Drucken,
  Teilen und Zwischenablage per Init-Skript in allen Browsern gleich ersetzt; Druckansicht über
  `emulateMedia`; in Chromium zusätzlich `page.pdf`: genau eine A4-Seite); Unit-Tests
  `invitations.spec.ts` (auch: der gezeichnete QR-Code, gelesen mit zxing-wasm, ergibt genau den
  Link), `invitationCodesApi.spec.ts`, `tokens.spec.ts`.
- CSV-Import der Admin-Konsole (`DeviceImportPage`, `geraete/import`, Route `device-import`, nur
  `admin`, `layout: 'admin'`, im Menü unter „Geräte“; KONZEPT.md Entscheidung 32): „1. Vorlage
  ausfüllen“ mit „Vorlage herunterladen“ und „Gültige Werte herunterladen“ (`downloadImportTemplate`,
  `downloadImportValues` in `src/api/deviceImport.ts`, `saveDownload`); „2. Datei prüfen“: Knopf
  „CSV-Datei wählen …“ öffnet ein verstecktes `<input type="file">` (danach geleert, damit dieselbe
  Datei nach dem Ändern erneut gewählt werden kann); leere bzw. Dateien über
  `MAX_IMPORT_FILE_BYTES` (2 MiB = `DeviceImportFile::MAX_BYTES`) lehnt die App ohne Anfrage ab, sonst
  sofort `previewDeviceImport` (`src/data/index.ts`, `POST devices/import/preview`, Datei per
  `toBase64`), „Erneut prüfen“ sendet denselben Inhalt (z. B. nach dem Anlegen eines Standorts).
  „3. Vorschau“ (Überschrift fokussiert): erkannte Kodierung und Trennzeichen, Zusammenfassung,
  Fehlerhinweis, „Unbekannte Werte“ mit „Meinten Sie …?“ (Feld `suggestions`) und Links auf
  Gerätetypen und Standorte, Filter als Knöpfe mit `aria-pressed` und Anzahl (`IMPORT_FILTERS`,
  Standard `defaultFilter`: Fehler, sonst Warnungen, sonst alle), Zeilen mit Ergebnis, Änderungen
  (`changeLines`: neu „Standort: …“ ohne Kennung, sonst „alt → neu“, leer = „leer“), Fehlern und
  Warnungen, je `IMPORT_ROWS_STEP` (100) mit „Weitere anzeigen“. Übernehmen nur mit Kennzeichen und
  etwas Neuem bzw. Geändertem (sonst „Die Datei ändert nichts“): Kommentar (`normalizeComment`),
  „Import übernehmen (2 neu, 1 geändert)“ → `applyDeviceImport` (`POST devices/import`, danach laden
  die schon geladenen Listen neu wie nach jeder Änderung); Erfolg grün „Import übernommen: …“ mit
  „Zur Geräteliste“, Datei und Vorschau weg; 409 `import_outdated` → neu prüfen und Hinweis „Die
  Vorschau ist neu erstellt“; andere Fehler als `ErrorNotice`. Ungespeichert (`useUnsavedInput`) =
  Vorschau vorhanden oder Kommentar. Logik in `src/data/deviceImport.ts`. Browser-Tests
  `tests/e2e/deviceImport.spec.ts` (Datei über das Ereignis `filechooser`, Vorschau-Server mit Liste
  von Antworten je Aufruf); Unit-Tests `deviceImport.spec.ts`, `router.spec.ts`.
- Protokoll der Admin-Konsole (`NoticesPage`, `protokoll`, Route `notices`; `NoticePage`,
  `protokoll/<id>`, Route `notice`, Ziel der Hinweis-Mails `AppUrls::notice`; nur `admin`,
  `layout: 'admin'`, Menü „Protokoll“ mit Zähler `openNotices` und `sections: ['notice']`; KONZEPT.md
  "Hinweise für Admins"; API siehe "Hinweise (API)" und "CSV-Export (API)"): Reka UI `Tabs` wie bei
  den Benutzern (Stile `.tabs`, `.tab`, `.tab-panel` in `base.css`), Reiter „Hinweise“ (Zahl der
  offenen) und „Verlauf exportieren“ (`protokoll?ansicht=export`, `HistoryExportPanel`, Inhalt bleibt
  beim Wechsel). Store `noticeList` (`NoticeList`, `src/data/noticeList.ts`, in `installDataLayer`):
  `GET notices` mit Filter `open`/`resolved`/`all` (in der Adresse `status=erledigt`/`alle`, ohne =
  offen; `noticeFilterFromQuery`), Seiten zu `NOTICE_PAGE_SIZE` (50) mit „Weitere laden“ wie
  `adminDeviceList`, Auffrischen so viele wie geladen (höchstens 200). Je Hinweis eine Karte (Link):
  `kind_label`, Status, Gerät, „Buchung: <Stand> · von <Name> · <Zeit>“ und „Überschriebener Stand“
  bzw. „Stand beim Eingang“ (`otherStateHeading`, `stateText` in `src/data/notices.ts`). Der Hinweis
  (zuerst aus der Liste, dann `loadNotice` = `GET notices/{id}`; 404 „Diesen Hinweis gibt es
  nicht.“): Überschrift `kind_label` mit Status, Gerät (Link zum Gerätedetail), Erklärung je Art
  (`noticeExplanation`, bei `stale_confirmed` zusätzlich der Satz zur Rückfrage), Karten „Buchung“
  (Konto mit E-Mail, Erfasst, Eingang, gewünschter Standort/Status, Kommentar), anderer Stand und
  „Gerät jetzt“ (`deviceStore.fetchCurrent`, „Umbuchen / Status ändern“ auf `geraete/<id>`, danach
  zurück mit Bestätigung; ausgesondert nur ein Hinweis). „Rückfrage per E-Mail“ je Konto mit E-Mail
  (`inquiryMailLink`: `mailto:` mit Betreff „Rückfrage zu Ihrer Buchung von <Typ> <Kennung>“ und
  Anfang des Textes mit Zeit und Stand; ohne E-Mail kein Link). „Erledigen …“ (`FormDialog`, Notiz
  freiwillig, mehrzeilig: `noticeNote` wie `DeviceText::noticeNote`, andere Steuerzeichen werden
  Leerzeichen, höchstens 500 Zeichen per `noticeNoteProblem`; leer = `{}`) bzw. „Wieder öffnen“
  (sofort), über `resolveNotice`/`reopenNotice` (`src/data/index.ts`; danach `noticeList` und, wenn
  geladen, `adminOverview` neu); darunter „Verlauf des Hinweises“ (Aktion, Zeit, Konto, Notiz).
  „Verlauf exportieren“: Schnellwahl „Letzte 30/90/365 Tage“ (`aria-pressed`), „Von“ und „Bis
  (einschließlich)“ (`<input type="date">`, Standard die letzten 90 Tage bis heute wie der Server;
  Prüfung `historyExportProblems`: leer, ungültig, Ende vor Beginn, mehr als
  `HISTORY_EXPORT_MAX_DAYS` = 366; `HISTORY_EXPORT_DEFAULT_DAYS` und `MAX_DAYS` per Test gleich PHP),
  `AppSelect` „Gerätetyp“ und „Standort (mit Fächern)“ (aus `deviceTypeStore` bzw.
  `adminLocationStore`, mit deaktivierten/gesperrten), Reihenfolge als Radiogruppe; Download
  `downloadHistoryExport` (`src/api/deviceEvents.ts`, `apiDownload`, Standort immer mit
  `include_sublocations=1`, `order` nur bei `oldest`) und `saveDownload`; 409 (zu viele Einträge) und
  andere Fehler als `ErrorNotice` mit der Meldung des Servers. Browser-Tests
  `tests/e2e/notices.spec.ts` (zustandsbehafteter Server je Test über `delegate`, `noticeJson` in
  `tests/e2e/support/admin.ts`); Unit-Tests `notices.spec.ts`, `router.spec.ts`.
- Einstellungen der Admin-Konsole (`SettingsPage`, `einstellungen`, Route `settings`, nur `admin`,
  `layout: 'admin'`, im Menü unten `ADMIN_SETTINGS`; Mockup „Einstellungen“, KONZEPT.md "Bereiche des
  Admins", Einstellungen, und Entscheidung 37; API siehe "Erscheinungsbild (API)" und
  "Benachrichtigung „Log“", unverändert): `ThemeModeSetting`, `AppearanceSettings`,
  `NotificationSetting` und `PasswordChangeForm` (ab 1100 px nebeneinander), `SessionList` (Bausteine
  in `src/components/`, die übrigen wie im Profil). `AppearanceSettings`: einzige Quelle der Auswahl
  ist das Feld „Farbe als Hex-Wert“, die Farbkreise (`ACCENT_SWATCHES`, Radiogruppe, erster =
  Standard `null`) schreiben hinein; Prüfung beim Tippen `checkAccent` (`src/data/appearance.ts`:
  Format wie `AccentColor::fromString` mit `trim()` wie PHP, Kontrast `contrastWithWhite` wie
  `AccentColor::contrastOf`, abgerundet `contrastText`, Grenzfälle `#ec1400`/`#ea1c05` per Test; die
  Standardfarbe gilt als „Standard“ und wird als `null` gesendet, `accentSelection`/`accentRequest`).
  Vorschau (`appearance-preview`: Kopfleiste mit Logo und Name, Gerät mit Status, Knopf) über
  `--preview-accent`, die App erst nach „Farbe speichern“ (`saveAccentColor` → `POST
  tenant/appearance`); „Verwerfen“ stellt den gespeicherten Stand her. Logo: verstecktes `<input
  type="file">` (`LOGO_ACCEPT`, danach geleert), vorher `logoSourceProblem` (leer, über 10 MB), dann
  `renderLogo` (`src/runtime/logoImage.ts`: `blob:` in ein `<img>`, `decode()`, Canvas `LOGO_EDGE`
  512 mit `placeLogo`: Rand `LOGO_MARGIN` 16 px, Seitenverhältnis bleibt, ohne Maße quadratisch;
  `toBlob('image/png')`), dann `logoSizeProblem` (über `MAX_LOGO_BYTES` = `PngInspector::MAX_BYTES`,
  per Test); unlesbar → `LOGO_UNREADABLE`. Das PNG steht als `blob:`-Adresse in Logo und Vorschau,
  gespeichert erst mit „Logo speichern“ (`saveLogo`, Base64 per `toBase64`), „Logo entfernen …“ nach
  `ConfirmDialog` (`removeLogo` → `DELETE tenant/logo`). Nach Erfolg `setTenant`
  (`applyTenantAppearance`: Farbe, Titel, `icon`/`apple-touch-icon` mit Logo bzw. ohne Logo wieder
  `DEFAULT_ICON_HREFS` wie in `index.html`, per Test) und `success-notice`; Fehler als `ErrorNotice`.
  Ungespeichert = Farbe geändert oder neues Logo noch nicht gespeichert. `NotificationSetting`:
  `fetchNotifications`/`saveNotifications` (`src/api/auth.ts`, `GET`/`POST auth/notifications`),
  Kästchen wirkt sofort, Fehler → bisheriger Stand und `ErrorNotice`. Browser-Tests
  `tests/e2e/settings.spec.ts` (zustandsbehafteter Server über `delegate`; Logo als SVG über
  `filechooser`, das gesendete PNG wird im Test entfiltert und geprüft); Unit-Tests
  `appearanceSettings.spec.ts`, `appearanceApi.spec.ts`, `appearance.spec.ts`, `router.spec.ts`.
- Super-Admin unter `service` (KONZEPT.md "Super-Admin – Entscheidungen", 39–47; API siehe "Städte
  verwalten und Admins einladen", unverändert): Seiten mit `layout: 'admin'` und `roles:
  ['superadmin']`, nur unter `service` (unter einer Stadt „Seite nicht gefunden“). `AdminMenu`/
  `AdminMenuNav` mit `service`: Einträge `SERVICE_MENU` (`src/router/adminMenu.ts`, jeder Eintrag
  kommt mit seiner Seite), oben „Service“/„Super-Admin“ mit Standard-Symbol, keine Zähler, kein
  `adminOverview`. Datenschicht `src/data/service.ts` (`installServiceDataLayer` in `main.ts` statt
  `installDataLayer`): `ListStore` mit `Refresher` für `tenantList` (`GET tenants`) und
  `superAdminList` (`GET superadmins`), geleert bei `onSessionEnded`; Ändern nur über die Funktionen
  dort (danach lädt die Liste neu, abgewartet). API `src/api/tenants.ts`, Logik `src/data/tenants.ts`
  (`slugProblem` wie `TenantSlug` in derselben Reihenfolge, `suggestSlug`, `tenantNameProblem`,
  `emailProblem` wie `EmailAddress`, nie strenger; Konstanten per Test gleich PHP; `tenantState`
  aktiv/„Einladung offen“/gesperrt, `tenantRows`, `serviceTiles`, `openInvitations`). Dashboard
  (`ServiceDashboardPage`): Kacheln Städte (aktiv/gesperrt, führt zu `staedte`), offene
  Admin-Einladungen (Summe `admins_invited`), Benutzer gesamt (Summe `users_active`); offene
  Einladungen über `GET tenants/{id}/users?status=invited` je Stadt mit Einladungen (nur nicht
  gesperrte, älteste zuerst) mit „Erneut senden“; darunter `TenantTable` (Name, Adresse als Text,
  Status, Admins, Benutzer; Spaltenköpfe mit `aria-sort`, am Handy Karten mit Knöpfen „Sortieren“).
  `staedte` (`TenantsPage`, Route `tenants`): Suche in Name und Kürzel und `TenantTable`.
  `einstellungen` (`ServiceSettingsPage`, Route `settings` wie unter Städten): Anzeigemodus,
  `NotificationSetting` mit `all-tenants`, Passwort, angemeldete Geräte; kein Erscheinungsbild.
  Keine Links in eine Stadt. Browser-Tests `tests/e2e/superAdmin.spec.ts` (Hilfen `tenantJson`,
  `accountJson`, `superAdminJson`, `SERVICE_OVERVIEW` in `tests/e2e/support/service.ts`; Tests, die als
  Super-Admin auf dem Dashboard landen, simulieren dessen Abfragen damit); Unit-Tests
  `tenants.spec.ts`, `router.spec.ts`.
- Anmeldung im Frontend (Konzept: KONZEPT.md "Anmeldung und Registrierung"): Routen mit `meta`
  `public` (ohne `auth/me`), `landing` (Weiche `/`) oder `roles`; optional `layout` (`App.vue`):
  ohne Angabe Kopfleiste und Inhalt, `user` Handy-Seiten mit Begrüßung „Hallo <Anzeigename>“ und
  Leiste unten (`UserTabBar`: Suchen, Prüffristen, Profil; für Admins vorne zusätzlich Dashboard),
  `focus` bildschirmfüllend ohne Kopfleiste der App, die Seite zeigt `FocusHeader` (Zurück über
  `goBack` in `src/router/back.ts`: vorige Seite der App laut `history.state.back`, sonst
  „Suchen“), `admin` Seiten der Admin-Konsole mit deren Menü (siehe „Admin-Konsole“ unten).
  Handy-Seiten (`suchen`, `prueffristen`, `profil`, `geraete/<id>` mit `<id>` wie die
  Platzhalter im Backend) für `user` und `admin` (KONZEPT.md "User am Handy – Entscheidungen").
  Der Wächter fragt `auth/me`
  höchstens einmal je Seitenaufruf (`ensureSession`); ohne Sitzung → `anmelden?weiter=…`, falsche
  Rolle → Startseite der Rolle (`landingRoute`: `user` → `suchen`, `admin`/`superadmin` →
  `dashboard`). Nur eine echte Antwort entscheidet über die Sitzung (401 = nicht angemeldet,
  403/404 der Stadt ebenso endgültig): Bei einer Störung (`isTransientError`: keine Verbindung,
  `invalid_response`, 5xx) führt der Wächter nie zur Anmeldung, sondern wartet und fragt erneut
  (`RETRY_DELAYS_MS` 2/4/8/15 s, sofort bei `online`); solange zeigt `App.vue` den Hinweis aus
  `sessionCheckError`. Rückkehradressen nur über `safeReturnPath`/`afterLogin` (Pfad der App, kein `//`,
  Schema oder Backslash, Rolle muss die Seite öffnen dürfen). Unter `service` gibt es `suchen`,
  `registrieren`, `email-bestaetigen` und `einladung-annehmen` nicht. Fehlertexte nur über
  `errorMessage` (`src/auth/errorMessages.ts`), Code klein daneben (`ErrorNotice`). Abmelden vergisst
  die Sitzung erst nach Erfolg beim Server. Anrede immer „Sie“. Bausteine in `src/components/`:
  `PasswordField` (Auge-Knopf mit fester Beschriftung „Passwort anzeigen“ und `aria-pressed`,
  mindestens 48 px), `ErrorNotice`, `MailHint` (Spam-Ordner, Absender zu den Kontakten),
  `BrandCard` (Logo 512 px oder Standard-Symbol, Name; unter `service` „Super-Admin“),
  `AccountPanel`, `ConfirmDialog` (Rückfrage über Reka UI `AlertDialog`, Fokus auf „Abbrechen“;
  nie `window.confirm`). Anmeldeseite: Formular ausgeblendet bei `tenant_not_found`/`tenant_inactive`,
  Passwort wird unverändert gesendet und nur bei `invalid_credentials` geleert, bei 429 Knopf mit
  Countdown bis `Retry-After`, bei `email_not_verified` „Bestätigungsmail erneut senden“.
- Tokens und Einladungscodes aus Links (`#token=…`, `#code=…`) nur über `takeFragmentParam`
  (`src/runtime/fragment.ts`): aus dem Fragment lesen, per POST senden, das Fragment sofort aus der
  Adresszeile entfernen (per Browser-Test: keine angefragte Adresse enthält das Token).
- Einladungscodes (`src/auth/invitationCode.ts`, Seite `registrieren`): Normalisierung genau wie
  `InvitationCodeValue::fromInput` (höchstens 64 Bytes, Leerraum wie PCRE `\s` mit `/u` – Kategorie
  Z, Tab, LF, VT, FF, CR, NEL, NICHT U+FEFF – und Bindestriche weg, nur ASCII groß); gesendet wird
  `XXXX-XXXX-XXXX`. Gemeinsame Testfälle `tests/Fixtures/invitation-code-cases.json` (`server` =
  Ergebnis des Backends), geprüft von `InvitationCodeCasesTest` (PHPUnit) und `invitationCode.spec.ts`
  (Vitest, gleicht auch `ALPHABET`, `LENGTH`, `MAX_INPUT_LENGTH` ab); Änderungen an
  `InvitationCodeValue` daher immer mit dem Frontend und neuen Fällen. Seiten der Mail-Links
  (`AppUrls::*_PAGE`, auch `geraete/<id>/details` und `protokoll/<id>`) muss es im Router geben
  (`appUrlsPages.spec.ts`). `email-bestaetigen` sendet
  das Token erst per Knopf (Virenscanner öffnen Links vorab).
- `einladung-annehmen` (`InvitationAcceptPage`, neue Admins): Name und Passwort (Live-Prüfung mit
  Name und Stadt, die E-Mail kennt nur der Server); danach keine Sitzung, sondern Anmeldung mit
  Hinweis `invitation_accepted` (`LoginNotice`, steht in sessionStorage wie alle Hinweise der
  Anmeldeseite); `invalid_token` blendet das Formular aus und verweist auf den Super-Admin (nur er
  sendet neue Einladungen). Mail-Links aller Seiten (`passwort-zuruecksetzen`, `email-bestaetigen`,
  `einladung-annehmen`, `registrieren#code=…`) lesen Token bzw. Code per `takeFragmentParam`.
- Neue Passwörter (`NewPasswordField`, Live-Prüfung `src/auth/passwordRules.ts`): Regeln wie
  `PasswordPolicy` OHNE Sperrliste (nur der Server, 422 `weak_password` am Feld). Absenden erst nach
  bestandener Prüfung, daher nie strenger als der Server: gemeinsame Testfälle
  `tests/Fixtures/password-policy-cases.json` (`server` = Ergebnis des Backends; bei `blocklist`
  muss das Frontend `ok` liefern, sonst dasselbe), geprüft von `PasswordPolicyCasesTest` (PHPUnit,
  echte Sperrliste) und `passwordRules.spec.ts` (Vitest, gleicht auch `MIN_LENGTH`, `MAX_LENGTH`,
  `MIN_FRAGMENT_LENGTH` und `TENANT_STOPWORDS` mit dem PHP-Code ab). Änderungen an `PasswordPolicy`
  daher immer mit `passwordRules.ts` und neuen Fällen in der Datei. Vergleichsform nur zerlegte
  ä/ö/ü zusammensetzen, Kleinschreibung, ß = ss; keine allgemeine NFC-Normalisierung (das Backend
  hat ohne intl keine). Länge in Unicode-Zeichen (`Array.from`), nicht UTF-16-Einheiten.
- Service Worker `sw.js` (Workbox, `generateSW`), registriert aus dem Bundle (kein Inline-Skript),
  Scope `/firefighting/<bereich>/`, Rückfall auf `index.html` außer `api/` und
  `manifest.webmanifest`. Das Manifest liefert PHP (`manifest: false` im Plugin).
- Updates (KONZEPT.md "Updates der App", `src/runtime/appUpdate.ts`): `skipWaiting: false`, die neue
  Version wartet, bis `AppUpdater` sie per `SKIP_WAITING` aktiviert und die Seite neu lädt (nie
  `skipWaiting: true`: die laufende App bräuchte sonst gelöschte Teile). Prüfen
  (`registration.update()` und `build.json` gleichzeitig) beim Start, bei `visibilitychange`
  (sichtbar) und stündlich, offline nie, Fehler still. Meldet `build.json` eine neue Version, der
  Browser aber keinen neuen Service Worker, fragt die App nach 0,5/1/2/4 s erneut
  (`UPDATE_RETRY_DELAYS_MS`, `findNewWorker`: EINE gemeinsame Suche für alle Aufrufer, endet bei
  `updatefound`), danach erst beim nächsten Anlass. Grund (Prüflauf unter Last): Der Browser legt
  `update()` mit einer noch laufenden Prüfung zusammen (gleichwertige Jobs im Service-Worker-
  Standard); begann diese vor dem Deploy, liest sie die alte `sw.js`. Aktualisiert wird beim nächsten Seitenwechsel auf einen anderen Pfad
  (`installUpdateGuard`; nicht bei gleichem Pfad wie beim Entfernen von `#token=…`), beim Start
  (`checkOnStart`, vor der ersten Seite; nur wenn ein Service Worker die Seite liefert, Kennung aus
  `build.json` höchstens `START_CHECK_TIMEOUT_MS` = 2 s abwarten, Registrierung ebenso; neu geladen
  wird die vollständige Startadresse samt Fragment, bei gleicher Adresse per `location.reload()`, da
  `assign` nur zum Fragment springen würde), vor „Seite nicht gefunden“ (`checkBeforeNotFound`, über
  `UPDATER_KEY` per `inject`; Start und „nicht gefunden“ suchen bis rund 30 s weiter,
  `START_RETRY_DELAYS_MS` mit zusätzlich 8 und 15 s, und zeigen solange „Seite wird geladen …“
  (`loadingPage` in `App.vue` bzw. die Seite selbst), nie kurz „nicht gefunden“; gelingt es nicht,
  bleibt die Seite beim Laden und `App.vue` zeigt „Die neue Version konnte nicht geladen werden.“ mit
  Knopf „Neu laden“ (`reloadNeeded`, `reloadFromServer`: Service Worker abmelden, Adresse samt
  `#token`/`#code` neu laden, OHNE Schleifensperre: ein Klick ist keine Schleife); bei
  Schleifensperre erscheint die Seite wie bisher) oder per Knopf „Jetzt aktualisieren“ im Hinweis von `App.vue` (nur mit ungespeicherten
  Eingaben oder während der Sperre; mit ungespeicherten Eingaben erst nach der Rückfrage „Ihre
  Eingaben auf dieser Seite gehen verloren. Trotzdem aktualisieren?“, Standard „Abbrechen“). Gegen Schleifen: nach jedem automatischen Neuladen 10 Minuten
  keines mehr (`RELOAD_COOLDOWN_MS`, sessionStorage je Tab). Seite ohne Kontrolle eines Service
  Workers (vom Server: erster Besuch, Strg+F5, nach „Neu laden“; `checkUncontrolled`, beim Start
  nur, wenn einer wartet, sonst bei jeder fertigen Installation): liefert `build.json` eine andere
  Kennung, ist ein Update da; dieselbe Kennung und ein wartender Service Worker → `SKIP_WAITING`
  ohne Neuladen (die Seite ist schon neu). Grund (live 31.10.2026, nur Chrome/Chromium): Hält ein
  anderer Tab den alten Service Worker, löscht `unregister()` die Registrierung nicht; das
  `register()` der Seite vom Server holt sie samt altem Service Worker zurück, die neue Version
  wartet weiter, das nächste Neuladen lieferte die alte. Andere Tabs übernimmt der neue Service
  Worker (`clientsClaim`); sie laden erst beim nächsten Seitenwechsel neu (mit Eingabe: Hinweis),
  auch wenn die Dateien der alten Version fehlen (der Wächter lädt vor dem Nachladen der Zielseite).
  `attach` verfolgt auch eine Installation, die schon vor `attach` lief (Chrome installiert eine
  zurückgeholte Registrierung sofort neu). Die erste Übernahme nach der Installation
  ist kein Update und gilt auch beim Aktualisieren nicht als „neue Version übernommen“ (deren Service
  Worker lieferte beim Neuladen die alte Seite). Meldet `build.json` dabei schon eine andere Kennung
  (Strg+F5, dann Deploy; `claimedByNewer`), ist die übernehmende Version vermutlich die neue, sicher
  nicht (die Antwort kann nach einem späteren Deploy kommen): Beim Aktualisieren genügt dann EIN
  `update()` ohne Suche; findet es keinen neueren Service Worker, wird neu geladen. Die Übernahme
  durch einen anderen Tab ist ein Update. Eine installierte Version gilt nur als wartendes Update,
  wenn ein ANDERER Service Worker die Seite kontrolliert (`controller !== worker`): WebKit meldet bei
  der ersten Installation den neuen schon als `controller`, während er noch `installed` ist.
  Zusätzlich vergleicht jede Prüfung (und jede
  Übernahme einer Seite ohne Kontrolle, jede neu installierte Version einer Seite ohne Kontrolle) die
  Kennung in `build.json`
  (vom Plugin `frontend/build/buildInfo.ts` in den Build gelegt, `fetch` mit `cache: 'no-store'`) mit
  `BUILD_ID`: verschieden = Update, unabhängig vom Zustand des Service Workers (sonst bliebe z. B. eine
  per Strg+F5 geladene Seite, die der neue Service Worker übernimmt, dauerhaft alt). Eine neu
  installierte Version unter einem Service Worker fragt `build.json` NICHT (die Anfrage liefe über den
  alten Service Worker; aktiviert ein anderer Tab gerade die neue Version, hält Chrome die Aktivierung
  an). Beim Aktualisieren (`apply`) fragt die App den Service Worker (`update()`, bei Bedarf die Suche
  wie oben), wartet auf die Installation (beim Seitenwechsel höchstens `ACTIVATION_TIMEOUT_MS` = 5 s;
  beim Start und vor „nicht gefunden“ bis zur Frist `START_LOAD_TIMEOUT_MS` = Summe von
  `START_RETRY_DELAYS_MS` ab Beginn, für Suche und Installation zusammen, mindestens 5 s; endet die
  Installation früher, sofort weiter; live dauerte sie länger als 5 s, der Start gab nach rund 6 s
  auf, 31.10.2026; Browser-Test mit hängender Installation: Schaltdatei `next-held` des
  Update-Testservers hält die Anfrage nach `index.html` (nur das Vorab-Laden des Service Workers
  fragt so; Workbox lädt ohne `__WB_REVISION__`, der steht nur im Cache-Schlüssel), bis der Test auf
  `next` umstellt; eine leere oder unbekannte Schaltdatei liest der Server kurz erneut, da
  `writeFileSync` sie erst leert), sendet `SKIP_WAITING` und lädt NUR
  nach der Übernahme (`controllerchange`, höchstens `TAKEOVER_TIMEOUT_MS`) neu; bleibt sie aus
  (Chrome hält die Aktivierung an, wenn der alte Service Worker beim Wechsel eine Anfrage bekommt),
  meldet sie als letzten Ausweg den Service Worker ab und lädt dann neu (Seite vom Server; mit
  Schleifensperre). Liefert `unregister()` false, gilt es trotzdem als abgemeldet, wenn
  `navigator.serviceWorker.getRegistration()` nichts mehr kennt (`unregisterForReload`: früher
  abgemeldet, ein anderer Tab hält sie nur noch; neue Seiten kommen vom Server). Ohne neue Version
  oder wenn das Abmelden scheitert, kein Neuladen (sonst lieferte der alte Service Worker die alte
  Seite und die Sperre verhinderte einen zweiten Versuch). Solange: Hinweis „Neue Version wird geladen …“
  (`switching`). Lädt die App neu, lässt der Wächter den Seitenwechsel offen (Promise ohne Ende) statt
  ihn abzubrechen: Ein Abbruch setzte bei Zurück/Vor (popstate) die Historie zurück, das überholte das
  Neuladen. Vor und während des Neuladens (`reload`) beginnt keine Anfrage mehr (keine Prüfung, keine
  geplante Wiederholung der Suche; der Start beginnt die regelmäßigen Prüfungen nur, wenn er nicht neu
  lädt); laufende (`update()`, `build.json`) wartet die App höchstens `REQUEST_SETTLE_TIMEOUT_MS`
  (1 s) ab. Grund: Bricht das Neuladen ein `update()` ab, meldet WebKit „…/sw.js due to access control
  checks“ als Skriptfehler. Ohne Service Worker gilt
  nur die Kennung. Mail-Links öffnen sich im Test in einem zweiten Tab (wie aus einem
  Mailprogramm): Ein Seitenwechsel mitten in einer Anfrage meldet WebKit als Skriptfehler. Fehlende Teile nach einem Deploy
  (`isChunkLoadError`) → Zielseite einmal neu laden. Was ein Neuladen überstehen muss (z. B. der
  Hinweis der Anmeldeseite in `session.ts`), steht in sessionStorage.
  Zeitleiste zur Diagnose (`UpdateTrace`, `src/runtime/updateTrace.ts`, KONZEPT.md "Updates der
  App"): sessionStorage `ff-update-trace` (`UPDATE_TRACE_KEY`), JSON-Liste `{at, event, detail?}`,
  höchstens `UPDATE_TRACE_MAX` (50), älteste fallen weg; live lesbar per
  `JSON.parse(sessionStorage['ff-update-trace'])`. Nur feste Ereignisse und Build-Kennungen, nie
  Adressen (Token im Fragment) oder persönliche Daten; Fehler des Speichers ohne Folgen.
- Regel für ALLE Formulare: ungespeicherte Eingaben über `useUnsavedInput(() => …)`
  (`src/runtime/unsavedInput.ts`) melden: true, solange etwas eingegeben und nicht gespeichert ist;
  nach dem Speichern oder Leeren false. Nur so lädt die App nie mitten in einer Eingabe neu.
- Fenster mit Eingaben NUR über `FormDialog` (eigene Reka-UI-Fenster gibt es nur für das Menü
  `AdminMenu` und Rückfragen `ConfirmDialog`, per Test `formDialogs.spec.ts`), immer mit
  `:unsaved` = derselbe Wert wie für `useUnsavedInput` (`const unsaved = computed(…)`, per Test).
  Mit ungespeicherten Eingaben schließt ein Klick bzw. Fokus daneben (`interact-outside`) das
  Fenster nicht, Escape fragt nach (`ConfirmDialog` „Eingaben verwerfen?“, „Weiter bearbeiten“
  Standard, „Verwerfen“); ohne Eingaben schließen beide wie gewohnt, „Abbrechen“ immer ohne
  Rückfrage; während `busy` weder Klick daneben noch Escape. Flächen mit `data-dialog-passthrough`
  (Update-Hinweise in `AppNotices`) zählen nie als Klick daneben; `base.css` stellt sie bei offenem
  Fenster (`body:has([data-form-dialog])`) fest oben über die Abdunklung und macht sie trotz
  `pointer-events: none` von Reka UI bedienbar (auch am Handy und bei gescrollter Seite, Reka UI
  sperrt das Scrollen); das Fenster rückt um `--app-notice-height` nach unten (Höhe des Hinweises,
  `ResizeObserver` in `AppNotices`, per CSSOM). Schichten: Abdunklung 10, Hinweis 20, Fenster 30,
  `ConfirmDialog` 40/45, Auswahllisten (`AppSelect`) 50. Der Hinweis ist dabei für Bildschirmleser
  verborgen (Reka UI setzt `aria-hidden` außerhalb des Fensters), Browser-Tests klicken ihn über
  den Text. Grund: Live-Test 31.10.2026, ein Klick auf „Jetzt aktualisieren“ bei offenem Fenster
  schloss es und verwarf die Eingabe ohne Rückfrage. Tests: `deviceAdmin.spec.ts`,
  `locations.spec.ts`, `appUpdate.spec.ts` (zweiter alter Tab), Unit-Test `formDialogs.spec.ts`.
- Build-Kennung `__APP_BUILD_ID__` (`vite.config.ts`: `FF_BUILD_ID`, sonst `GITHUB_SHA` gekürzt,
  sonst `dev`; nur `A-Za-z0-9._-`, 1–40) an `<html data-build>`; derselbe Stand ergibt denselben
  Build.
- Befehle (in `frontend/`): `npm ci`, einmalig `npx playwright install chromium firefox webkit`;
  `npm test` (ESLint + Prettier, Vitest, `vue-tsc` + Build, alle Browser-Tests in Chromium und
  WebKit); was vor „✅ FERTIG“ lokal laufen muss, steht unter "Arbeitsweise". Einzeln: `npm run lint`, `npm run format`, `npm run typecheck`, `npm run test:unit`,
  `npm run test:unit:log` (dasselbe mit Protokoll in `test-logs/unit.log`,
  `tests/unit/support/unitLog.ts`: Reporter `json` nach `test-logs/unit-last.json`, danach je Lauf
  eine Zeile Zeit (UTC) und Ergebnis, bei Rot je gescheitertem Test `FAIL <datei> > <describe> >
  <test>: <erste Zeile der Meldung>`; leert kein Playwright-Lauf),
  `npm run build`, `npm run test:e2e` (braucht den Build; lokal nur Chromium und WebKit),
  `npm run test:e2e:all` (alle fünf Profile, `playwright.all.config.ts`), `npm run test:load`
  (Lastläufe, siehe unten). Entwicklung: `npm run dev`
  (`http://localhost:5173/firefighting/test/`, API per Proxy an `php -S localhost:8000`; für
  ändernde Anfragen `http://localhost:5173` in `http.allowed_origins`).
- Browser-Tests (Playwright: Chromium, Firefox, WebKit, iPhone 13, Pixel 7) laufen gegen den Build,
  ausgeliefert von PHP (`php -q -S localhost:<port> public/index.php`), also mit echter CSP und
  echtem Caching. Profile: in CI (`CI` gesetzt) alle fünf, aufgeteilt auf bis zu acht Jobs (`--shard`,
  Reporter `blob`, zusammengeführt mit `playwright merge-reports`; siehe "GitHub Actions (CI)"),
  lokal mit `npm test`/`npm run test:e2e` nur `chromium` und `webkit` (`LOCAL_PROJECTS` in
  `playwright.config.ts`, Reporter `list`); Firefox und die Handy-Profile prüft vor allem CI, lokal
  `npm run test:e2e:all`. Server: Jeder Playwright-Worker
  startet eigene (Worker-Fixtures `appServer`, `updateServer` in `tests/e2e/fixtures.ts`,
  `tests/e2e/support/phpServer.ts`): Ports `4173 + 2 × parallelIndex` (App) und eins darüber
  (Update-Testserver, nur gestartet, wenn ein Test ihn braucht); `baseURL` kommt aus dem Fixture,
  kein `webServer` in der Konfiguration. Nie fremde Server mitbenutzen: Ist ein Port belegt (Prüfung
  per TCP auf IPv4 und IPv6, erkennt auch einen hängenden Server eines abgebrochenen Laufs), scheitert
  der Start mit „… is already used“. `php -S` arbeitet Anfragen nacheinander ab: unter Linux (CI)
  `PHP_CLI_SERVER_WORKERS` = 4 je Server, Zahl der Worker nach Standard von Playwright; unter
  Windows (dort ohne) weist ein Server unter hoher Last Verbindungen ab (Firefox:
  `NS_ERROR_CONNECTION_REFUSED`), daher `WINDOWS_TEST_WORKERS` in `playwright.config.ts`: 6 für
  Chromium und WebKit, 4 für alle fünf Profile (gemessen 29.10.2026, README "Frontend"; erst nach
  neuer Messung ändern). Keine eigene Grenze für Firefox in `playwright.all.config.ts` (gemessen
  30.10.2026: 2 Firefox-Worker nicht stabiler, 1 fast doppelt so lang; abgewiesen wird am
  PHP-Server des einzelnen Workers). Keine
  automatischen Wiederholungen (`retries: 0`), sie würden zeitweise Fehler verdecken. API und Manifest simuliert `page.route()`
  (`tests/e2e/fixtures.ts`) mit denselben Bereichen wie die Routen in PHP (unter `service` nur
  `api/v1/app-icon/…`), eine Datenbank wird nicht gebraucht. Weitere Routen je Test über die
  Option `api` (`"METHODE pfad"` → Antwort, Funktion je Anfrage oder `'abort'` für einen
  Netzwerkfehler; Antwort ohne JSON über `text` und `contentType`; gesendete Bodys in `apiRequests`); Hilfen `meBody(role)`, `NOT_AUTHENTICATED`,
  `DATABASE_UNAVAILABLE`, `HTTP_ERROR_CONSOLE` (Browsermeldung zu 4xx/5xx) und
  `NETWORK_ERROR_CONSOLE` (zusätzlich abgebrochene Anfragen), beide nur per `allowedConsoleError`. Tests auf öffentlichen
  Seiten (z. B. `anmelden`) brauchen kein `auth/me`. Jeder Konsolenfehler, Skriptfehler,
  jede CSP-Verletzung und jede nicht simulierte API-Anfrage lässt den Test scheitern (erwartete
  Browsermeldung nur per Option `allowedConsoleError`, nie für CSP). Einzige feste Ausnahme für alle
  Tests (`isAbortedFontDownload`, `tests/e2e/support/consoleErrors.ts`, Unit-Test
  `consoleErrors.spec.ts`): Firefox meldet einen Schrift-Download, den Neuladen oder Schließen
  abbricht, als „downloadable font: download failed … status=2152398850“ (`NS_BINDING_ABORTED`);
  zugelassen nur in Firefox, nur mit diesem Status und nur für `.woff2`/`.woff` aus `assets/` des
  eigenen Testservers (`baseURL`). Andere Schriftfehler bleiben Fehler. Service Worker sind gesperrt
  außer in `serviceWorker.spec.ts` und `appUpdate.spec.ts`; der Offline-Test läuft nur in Chromium (`setOffline` umgeht in
  Firefox/WebKit den Service Worker). Update-Tests (`appUpdate.spec.ts`) mit zwei echten Builds: `globalSetup.ts` baut
  die neue Version (`FF_BUILD_ID=e2e-next`) nach `frontend/test-builds/` (in `.gitignore`), der
  Update-Testserver des Workers (`frontend/tests/e2e/support/update-server.php`, nur für Tests,
  `baseURL` dieser Testdatei) liefert je Bereich `frontend/dist` oder, mit Schaltdatei
  `test-builds/state/<bereich>`, die neue (Inhalt `next`; `build-json` = nur `build.json` neu, `sw.js`
  bleibt alt); jeder Test hat einen eigenen Bereich. Er beantwortet die öffentlichen Aufrufe der Anmeldeseite
  selbst, weil WebKit Anfragen einer vom Service Worker kontrollierten Seite nicht an `page.route()`
  gibt; für Seiten hinter der Anmeldung in weiteren Tabs zusätzlich eine Tabelle je Bereich
  `test-builds/state/<bereich>.api.json` (`writeApiTable`, `"METHODE pfad"` ohne Query → `{status,
  body}`, z. B. `adminDeviceApi`: Gerätedetail und Geräteliste als Admin). Bereiche je Test über `randomUUID` (Prozess-IDs werden unter Windows wiederverwendet).
  Abgedeckte Fälle: ohne/mit Eingabe, Rückfrage, erster Besuch mit Deploy vor der
  Installation (Registrierung per Init-Skript zurückgehalten), Seite per Strg+F5 geladen und vom
  neuen Service Worker übernommen (nur Chromium: `Page.reload` mit `ignoreCache` über CDP), zwei Tabs
  (einer aktualisiert, der andere folgt; einer geschlossen), Mail-Link mit alter Version, „Seite nicht
  gefunden“, Zurück-Knopf nach erkannter neuer Version, neue Kennung ohne neuen Service Worker (nach
  rund 30 s „Neu laden“), Installation beim Start länger als 5 s (`next-held`; nach der Freigabe
  lädt Workbox die übrigen Dateien nacheinander, `index.html` zuerst; der Test wartet darauf bis zur
  Frist der App, `START_LOAD_TIMEOUT_MS` ab Beginn des Starts, nicht länger; die Uhr läuft nach
  `clock.install()` weiter, Timer feuern, `runFor` springt nur vor), „Neu laden“ mit zweitem alten
  Tab (Gerätedetail mit offenem „Bearbeiten“ und Eingabe: danach steuert die neue Version die Seite,
  keiner wartet, auch nach `page.reload()`; der andere Tab lädt nicht neu, zeigt den Hinweis, behält
  die Eingabe und wechselt mit „Zurück“ auf die neue Version, obwohl die Dateien der alten fehlen;
  vor der Korrektur rot in Chromium und Chrome, in Firefox und WebKit nicht betroffen). Tests mit
  eigenem Tab (Mail-Link, zweiter alter Tab) prüfen Konsolenfehler selbst (`collectProblems`), mit
  derselben Ausnahme `isAbortedFontDownload`. Sporadische Fehler der Update-Logik nie mit längeren Wartezeiten
  überdecken: unter Last wiederholen (`npm run test:load -- [Datei] [--project=…] [--runs=N]`,
  `tests/e2e/support/loadRun.ts`: alle fünf Profile, ohne Angabe `--repeat-each=10`, je Durchgang
  `--output=test-results/load/<Zeit>-<n>`, damit die Traces bleiben; der nächste normale Lauf leert
  `test-results/`) und die Ursache im Trace suchen.
- Feste Wartezeiten der App (Wiederholungen nach 2 s bei `auth/me`, Countdown nach `Retry-After`,
  Suche bis rund 30 s nach dem neuen Service Worker) werden in den Browser-Tests mit `page.clock`
  vorgespult (`install()` vor dem Laden, dann `runFor()`), nie abgewartet. Netzwerk und Service
  Worker laufen dabei in Echtzeit: Hängt die nächste Wartezeit an einer Antwort (z. B. `update()`),
  in kleinen Schritten vorspulen (`expect.poll` mit `runFor(1000)`, `intervals: [50]`). Begrenzt
  die App das Warten auf eine echte Antwort mit einem Timer (z. B. `START_CHECK_TIMEOUT_MS` für
  `build.json` beim Start), erst vorspulen, wenn die Antwort sichtbar verarbeitet ist (z. B.
  `update-loading` sichtbar); sonst läuft der Timer unter Last vor der Antwort ab. Nur gezielt
  in diesen Tests, nicht global.
- Zustände, die die App asynchron setzt (CSS-Variablen wie `--accent`, localStorage,
  sessionStorage, Eigenschaften ohne Locator), in Browser-Tests nie mit einmaligem `page.evaluate`
  + `toBe` prüfen, sondern wartend: Locator-Assertions (`toHaveAttribute`, `toHaveCSS`, …) bzw.
  `expect.poll(() => page.evaluate(…))`. Auch nicht nach einem scheinbar passenden Merkmal: Ein
  Knopf, der beim Speichern „Wird gespeichert …“ heißt, ist unter seinem alten Namen schon vor der
  Antwort verschwunden (sporadisch rote CI in `settings.spec.ts`, 31.10.2026). Ausnahmen nur für
  Werte, die sich nicht mehr ändern (z. B. `document.baseURI`).
- Formatierung: Prettier (4 Leerzeichen laut `.editorconfig`, JSON 2), ESLint mit typgeprüften
  strikten Regeln, `no-console`, nur `<script setup lang="ts">`, kein `v-html`.

## Server und .htaccess
- Die `.htaccess` in der Hauptebene des Webspace (`htdocs/.htaccess`) gehört NICHT zum
  Projekt. Sie steuert ganz www.prodad.de und darf von Agenten niemals angelegt,
  verändert oder vorgeschlagen werden.
- Sie enthält die Regel
  `RewriteRule ^firefighting/[a-z0-9-]+(/.*)?$ apps/firefighting-equipment/public/index.php [L]`.
  Daher braucht `public/` keine Rewrite-Regel; alle Anfragen landen bei `public/index.php`.
- Stadt-Kürzel bestehen nur aus Kleinbuchstaben, Ziffern und Bindestrich.
- Projektordner (`.htaccess`) und `config/` (`config/.htaccess`) sind per `.htaccess`
  gesperrt, `public/` ist freigegeben (`public/.htaccess`). Diese Dateien nur nach
  Rückfrage ändern; die Freigabe in `public/.htaccess` nie entfernen.
- Nur Dateien in `public/` sind von außen erreichbar; dort nichts Geheimes ablegen.

## Datenbank
- Nur eine Datenbank; alle Tabellen mit Präfix `fe_` (z. B. `fe_tenants`, `fe_users`,
  `fe_devices`, `fe_device_events`).
- Zeichensatz und Kollation: Alle Tabellen und die Verbindung verwenden utf8mb4 mit
  `utf8mb4_unicode_ci`. Die Verbindung läuft mit Sitzungs-Zeitzone UTC (`+00:00`).
  Dokumentierte Ausnahmen auf Spaltenebene: `fe_users.email` ist `utf8mb4_bin`, damit z. B.
  "ß"/"ss" oder "é"/"e" nicht als gleich gelten (Kleinschreibung erzwingt ein CHECK); ebenso
  `fe_devices.identifier_key`, `fe_devices.scan_code`/`scan_code_key` (exakter Vergleich) sowie
  `fe_device_events.old_value`/`new_value` (siehe "Standorte und Geräte"); feste Werte (`role`, `status`, `purpose`, `action`, `kind`, `event_type`, `field`,
  `not_applied_reason`, `outcome`, `reason`, `requested_status`, `other_status`),
  `password_hash`, `fe_tenants.accent_color`, `fe_tenant_logos.version` und
  `fe_device_bookings.client_ref` sind
  `ascii`/`ascii_bin`, damit `IN (...)` bzw. `LIKE` Groß- und Kleinschreibung unterscheiden.
- Bilddaten: einzige Tabelle mit Binärdaten ist `fe_tenant_logos` (`MEDIUMBLOB`, siehe
  "Erscheinungsbild (API)"); Dateien liegen nie im Dateisystem.
- `sql_mode` setzt `ConnectionFactory` ausdrücklich und vollständig, damit lokal und STRATO
  identisch sind: `STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,
  NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY`. Zu lange Werte führen daher zu einem Fehler statt
  zu stiller Kürzung. Nicht auf den Server-Standard verlassen und nicht abschwächen.
- Zeitstempel als `DATETIME` in UTC; der Code schreibt sie ausdrücklich mit `UTC_TIMESTAMP()`.
- Mandantendaten tragen immer eine Mandanten-Spalte; jede Abfrage filtert danach.
- Schemaänderungen nur über versionierte Migrationen.
- Tests laufen nur gegen Datenbanken, deren Name auf `_test` endet
  (lokal: `firefighting_test`). Der Testcode bricht sonst ab.
- `config/config.local.php`: Zugang der Anwendung (auf dem Server: Produktiv-DB).
- `config/config.test.php`: Zugang nur für Tests; existiert nur lokal und nie auf dem
  Server. Der Datenbankname muss auf `_test` enden.
- Format beider Dateien wie `config/config.example.php` (Gruppe `db` mit `host`, `name`,
  `user`, `pass`); den Zeichensatz utf8mb4 setzt der Code. Unbekannte Schlüssel in `db`
  sind ein Fehler, ein leeres `pass` ist erlaubt. Optional (nur lokal) Gruppe `http` mit
  `allowed_origins` (Liste wie `http://localhost:8000`, ohne Pfad, kleingeschrieben); andere
  Schlüssel in `http` sind ein Fehler. Ebenso optional (Standardwerte = Produktion, auf dem Server
  weglassen): `app` → `base_url` (Standard `https://www.prodad.de`, Format wie ein Origin) und
  `mail` → `transport` (`sendmail` Standard, `smtp`, `file`), `file_dir` (Standard `var/mail`) und
  `smtp` (Schlüssel siehe "E-Mail-Versand"; Pflicht bei `transport = smtp`, sonst geprüft, aber
  nicht verwendet) sowie `offline` → `max_age_days`, `queue_max_age_days` (je 1–365, Standard 7;
  siehe "Offline-Buchungen (API)"). Die Gruppen werden erst geladen, wenn ein Link, eine Mail bzw.
  die Zeitlimits gebraucht werden.
  Meldungen von `ConfigLoader` nennen nie Werte.
- Tests: Testsuiten `unit` und `integration` (`composer test`, `composer test:unit`,
  `composer test:integration`). Die ganze Suite dauert lokal rund 3 Minuten;
  `process-timeout` 900 in `composer.json` bleibt als Reserve. Integrationstests werden übersprungen, wenn
  `config/config.test.php` fehlt. Dauerhafte Tabellen legen sie nur mit Präfix `fe_test_`
  an und löschen sie wieder; aufgeräumt wird ausschließlich `fe_test_*` und nur in einer
  Datenbank mit Endung `_test` (`TestDatabase::dropTestTables`).
- Fachliche Integrationstests erben von `tests/Integration/DomainTestCase.php`: Diese führt
  einmal pro Testlauf die echten Migrationen aus `migrations/` in der Testdatenbank aus
  (Protokoll in deren `fe_schema_migrations`) und leert vor jedem Test alle Tabellen `fe_*`
  außer `fe_schema_migrations` und `fe_test_*` (`TestDatabase::clearDomainTables`, nur in
  einer Datenbank mit Endung `_test`). Geleert wird per `DELETE`, nicht `TRUNCATE` (DDL, legt
  jede Tabelle neu an, lokal rund 125 ms je Tabelle und Test), und zwar alle Tabellen in EINER
  Transaktion (jeder Commit kostet lokal rund 9 ms; per Test über `Com_commit` gesichert);
  `AUTO_INCREMENT` läuft daher weiter, Tests dürfen sich nicht auf bestimmte IDs verlassen.
  Lokal empfohlen: `innodb_flush_log_at_trx_commit = 2` (README), nie auf dem Server.
- Argon2id in Tests: Tests verwenden schwache Parameter (`tests/Support/TestPasswordHasher`,
  memory_cost 1024, time_cost 1, threads 1), Produktion nie. Die Anwendung
  (`Application::create`) und die Kommandozeile (`bin/user.php`) erzeugen `PasswordHasher` immer
  ohne Argumente (Standardparameter von PHP); die Konfigurationsdatei kennt keine Hash-Parameter,
  und es darf keinen Weg geben, sie darüber oder anders von außen zu schwächen (per Test
  gesichert). Andere Parameter nur per Konstruktor in Testcode. Mit Standardparametern bleiben
  gezielte Tests: Hash-Format, `needsRehash`, Dummy-Hash und dessen Laufzeit
  (`PasswordHasherTest`, `LoginTest::testUnknownEmailTakesAboutAsLongAsWrongPassword`).
- Agenten öffnen, zeigen oder ändern `config.local.php` und `config.test.php` niemals;
  nur PHP-Code darf sie laden.

## Migrationen
- Migrationen sind SQL-Dateien in `migrations/` mit dem Namen `NNNN_beschreibung.sql`
  (vierstellig, ab `0001` lückenlos aufsteigend, Beschreibung nur `a-z`, `0-9`, `_`).
  Dateien sind UTF-8 ohne BOM mit LF-Zeilenenden (`.gitattributes`).
- Bereits ausgeführte Migrationen werden **nie** geändert, umbenannt oder gelöscht
  (geprüft per SHA-256-Prüfsumme). Korrekturen immer als neue Migration.
- Nur vorwärts, keine Down-Migrationen.
- Klein halten: höchstens eine DDL-Anweisung (CREATE/ALTER/DROP) pro Migration, weil
  MariaDB DDL nicht zurücknimmt. Zusätzliche DML-Anweisungen (z. B. INSERT von
  Startwerten) sind erlaubt.
- Neue Tabellen: Präfix `fe_`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci`.
- Kein `DELIMITER`, keine Trigger oder gespeicherten Prozeduren.
- Protokoll in `fe_schema_migrations` (wird von `run` automatisch angelegt). Der Name ist im
  `Migrator` konfigurierbar; die Migrationstests verwenden `fe_test_schema_migrations` und
  fassen die echte `fe_schema_migrations` nie an. Einzige Ausnahme: `DomainTestCase` führt die
  echten Migrationen in der Testdatenbank (Endung `_test`) mit `fe_schema_migrations` aus.
- Wurde eine Migration lokal geändert, nachdem sie in der Testdatenbank lief, schlägt die
  Prüfsumme an. Dann die Testdatenbank neu anlegen (README), nie die Prüfung umgehen.
- CHECK-Bedingungen sind erlaubt und erwünscht (MariaDB 10.11 prüft sie). Bei `REGEXP` auf
  `_ci`-Spalten `COLLATE utf8mb4_bin` verwenden (sonst wird Groß-/Kleinschreibung ignoriert)
  und `\z` statt `$` als Ende, im SQL-String als `'…\\z'` geschrieben (PCRE-`$` passt auch
  vor einem abschließenden Zeilenumbruch).
- Aufruf (nur Kommandozeile): `php bin/migrate.php status`, `php bin/migrate.php run`,
  `php bin/migrate.php run --dry-run`, `php bin/migrate.php check`; andere Konfiguration
  mit `--config=PFAD` (Standard `config/config.local.php`). `status`, `check` und
  `--dry-run` ändern nichts.
- `check` ist für Skripte: Exit-Code 0 = nichts offen, 3 = Migrationen offen (`EXIT_PENDING`),
  1 = Fehler (z. B. Prüfsumme, Verbindung), 2 = falscher Aufruf.
- Auf dem Server immer mit `php85` ausführen:
  `cd ~/htdocs/apps/firefighting-equipment && php85 bin/migrate.php run`.
- Integrationstests für Migrationen verwenden eigene Test-Migrationen aus
  `tests/Fixtures/migrations/`, die nur Tabellen `fe_test_*` anlegen.

## Mandanten (Städte)
- Tabelle `fe_tenants` (Migration `0001`): `id`, `slug`, `name`, `active`, `created_at`,
  `updated_at` (UTC), dazu `accent_color` (Migration `0021`, siehe "Erscheinungsbild (API)"). Code
  in `src/Tenant/` (`TenantSlug`, `TenantName`, `Tenant`, `TenantRepository`, `TenantSummary`,
  `TenantAdministration`; Erscheinungsbild getrennt in `TenantAppearanceRepository`, damit
  `TenantRepository` nie Bilddaten lädt).
- Kürzel (`TenantSlug`): nur `a-z`, `0-9` und Bindestrich, Bindestrich nicht am Anfang/Ende
  und nicht doppelt, höchstens 50 Zeichen, eindeutig. Großbuchstaben werden abgelehnt (mit
  Vorschlag der kleingeschriebenen Form), nie still umgewandelt. `service` ist reserviert
  (`TenantSlug::RESERVED`). Dieselben Regeln prüft die Datenbank per CHECK; beide Stellen
  immer gemeinsam ändern.
- Name (`TenantName`): Leerraum am Rand wird entfernt, nicht leer, höchstens 100 Zeichen,
  keine Steuerzeichen.
- Neue Städte erhalten beim Anlegen die Start-Gerätetypen (siehe "Standorte und Geräte").
- Das Kürzel ist nach dem Anlegen unveränderlich, Städte werden nie gelöscht, nur gesperrt
  (`active = 0`, Daten bleiben erhalten). Da Trigger verboten sind, sichert das der Code:
  `TenantRepository` hat bewusst keine Methode zum Ändern des Kürzels oder zum Löschen; eine
  solche nicht hinzufügen. Umbenennen (`rename`) ändert nur den Namen.
- Sperren einer Stadt (`TenantRepository::setActive(…, false)`) beendet in derselben Transaktion
  alle Sitzungen der Konten dieser Stadt (auch wenn sie schon gesperrt war), damit nach dem
  Entsperren keine alten Sitzungen wieder gelten. Entsperren lässt Sitzungen unberührt.
  `setActive` öffnet eine eigene Transaktion (eine bereits offene ist ein Fehler).
- Verwaltung über die API des Super-Admins (siehe "Städte verwalten und Admins einladen") oder per
  Kommandozeile `bin/tenant.php` (`list`, `create --slug=… --name=…`, `rename --slug=… --name=…`,
  `deactivate --slug=…`, `activate --slug=…`, `invite-admin --slug=… --email=… [--print-link]`,
  `purge-device-data --slug=… [--execute --confirm=…]` (siehe "Gerätedaten zurücksetzen"),
  `--config=PFAD` wie bei `migrate.php`). `create` per Kommandozeile legt keinen Admin an.
  Auf dem Server: `cd ~/htdocs/apps/firefighting-equipment && php85 bin/tenant.php list`.
  Exit-Codes: 0 OK, 1 Fehler (z. B. ungültiges oder doppeltes Kürzel), 2 falscher Aufruf.
- `invite-admin` (Notfallweg ohne API): neue Adresse → Konto `invited` + Link, offene Einladung
  (`invited`) → Link erneuern, anderes vorhandenes Konto oder gesperrte Stadt → Exit-Code 1. Mail
  über den konfigurierten Transport (`AdminInvitationMail`); Versandfehler → Hinweis auf stderr,
  Exit-Code 0. `--print-link` gibt den Link zusätzlich auf stdout aus (Warnung auf stderr); ohne
  die Option erscheint er nie in der Ausgabe. Tests übergeben `FakeMailer` und einen eigenen
  `ErrorReporter` (Konstruktor wie bei `UserCommand`).

## Benutzer und Anmeldung
- Anmeldung per Sitzungs-Cookie (`HttpOnly`, `Secure`, `SameSite=Strict`, Pfad
  `/firefighting/<kürzel>/` bzw. `/firefighting/service/`). Die Sitzung liegt in der Datenbank
  (`fe_sessions`); im Cookie steht nur ein zufälliges Token, gespeichert wird nur dessen Hash.
  Zur Sitzung wird nur eine grobe Gerätebezeichnung (`device_label`, z. B. "Chrome auf Android")
  gespeichert, beim Anmelden aus dem User-Agent abgeleitet; nie den vollständigen User-Agent.
- Ein Konto je Stadt; dieselbe E-Mail darf in mehreren Städten als getrennte Konten existieren.
  Der Super-Admin hat keine Stadt und meldet sich nur unter `/firefighting/service/` an.
  Interner Schlüssel ist immer die ID. E-Mail-Adressen werden kleingeschrieben gespeichert und
  binär verglichen (`utf8mb4_bin`; "strasse@…" und "straße@…" sind verschiedene Adressen).
- Rollen: `superadmin` (verwaltet Mandanten), `admin` (Geräte, Standorte, Benutzer der Stadt),
  `user` (bucht um, setzt Status).
- Status eines Kontos (`fe_users.status`): `invited` (erster Admin, Passwort noch nicht gesetzt)
  → `unverified` (registriert, E-Mail unbestätigt) → `pending` (wartet auf Freischaltung) →
  `active`; dazu `deleted` (anonymisiert). Sperren ist davon getrennt (`locked_at`).
- Super-Admins werden nie gelöscht/anonymisiert, nur gesperrt (CHECK
  `chk_fe_users_superadmin_not_deleted`, Migration `0017`, und `UserRepository::anonymize`). Immer
  bleibt mindestens ein aktiver, nicht gesperrter Super-Admin.
- Geschütztes Konto (`fe_users.protected`, Migration `0016`): Die Datenbank erzwingt per CHECK
  `chk_fe_users_protected` geschützt ⇒ Rolle `superadmin`, Status `active`, nicht gesperrt. Ein
  geschütztes Konto wird weder gesperrt noch gelöscht noch in Rolle oder Anzeigename geändert
  (`UserRuleViolationException::protectedAccount`, 409 über die API); erlaubt bleiben das eigene
  Passwort (`auth/password`, Passwort vergessen, `bin/user.php superadmin:password` als Notfallweg auf
  dem Server), `rehashPassword` und die eigene Benachrichtigung. Setzen und Entfernen NUR per
  Kommandozeile (`superadmin:protect`, `superadmin:unprotect --confirm=<gleiche Adresse wie
  --email>`), nie über die API (die API zeigt `protected` nur an). Keine E-Mail-Adresse eines echten
  Kontos im Code oder Repository.
- Benachrichtigung „Log“ (`fe_users.notify_device_log`, Migration `0018`, Standard 0): CHECK
  `chk_fe_users_notify_device_log` erlaubt 1 nur für `admin`/`superadmin` und nicht `deleted`;
  `changeRole` auf `user` und Anonymisieren setzen sie auf 0. Siehe "Benachrichtigung „Log“".
- Passwörter: mindestens 10, höchstens 128 Zeichen, keine Komplexitätspflicht, Sperrliste
  häufiger Passwörter, kein Bestandteil von E-Mail, Name oder Stadtname. Hash mit Argon2id
  (`PASSWORD_ARGON2ID`, Standardparameter; auf STRATO in CLI und Web verfügbar).
- Sitzungsdauer (Inaktivität / Höchstdauer): `user` 30 Tage / 90 Tage, `admin` 7 Tage / 30 Tage,
  `superadmin` 1 Stunde / 12 Stunden. Die Inaktivitätsgrenze wird zur Laufzeit aus Rolle und
  `last_seen_at` berechnet. Passwortänderung, Rollenwechsel, Sperren oder Löschen beenden alle
  Sitzungen des Kontos.
  - Präzisierung für das Ändern des eigenen Passworts (`POST auth/password`): Alle anderen
    Sitzungen werden beendet; die aktuelle wird durch eine neue ersetzt (neues Token, neues Cookie,
    gleiche Gerätebezeichnung). Angemeldet bleibt also nur das Gerät, auf dem geändert wurde. Ein
    gestohlenes Cookie ist danach in jedem Fall ungültig.
- Einladung: Admin erzeugt einen Code (Gültigkeit höchstens 90 Tage, maximale Nutzungen,
  widerrufbar) → Registrierung mit Code, E-Mail, Passwort und Name → Bestätigungslink per E-Mail
  → Admin schaltet frei und legt Anzeigenamen und Rolle fest. Einladungscodes legen nur Admins
  ihrer eigenen Stadt an, nicht der Super-Admin.
- Einladungscodes: 12 Zeichen aus `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (ohne 0/O/1/I/L), Anzeige
  als `XXXX-XXXX-XXXX`. Bei der Eingabe werden Groß-/Kleinschreibung, Leerzeichen und
  Bindestriche ignoriert; gespeichert wird nur der SHA-256 der normalisierten Form (12 Zeichen,
  Großbuchstaben).
- Erster Admin einer Stadt: Der Super-Admin legt ihn an (Status `invited`), dieser erhält einen
  Einmal-Link zum Setzen des Passworts und gibt dabei seinen Namen an; daraus werden `name` und
  `display_name`. Ebenso lädt der Super-Admin weitere Admins ein (siehe "Städte verwalten und
  Admins einladen"). Der Super-Admin selbst wird per Skript angelegt.
- Einmal-Links (`fe_user_tokens`): kurz gültig (Bestätigung/Passwort setzen 24 h, Passwort
  zurücksetzen 1 h), nur einmal nutzbar, nur als Hash gespeichert. Einlösen löscht die Zeile;
  je Benutzer und Zweck gibt es höchstens einen offenen Link, ein neuer ersetzt den alten.
- Tokens (Sitzung, Einmal-Links) haben mindestens 32 zufällige Bytes und werden als SHA-256
  (32 Byte) gespeichert; wegen der hohen Zufälligkeit genügt ein schneller Hash. Die Regel gilt
  nicht für Einladungscodes (siehe oben).
- Löschen = Anonymisieren: persönliche Daten (E-Mail, Namen, Passwort-Hash, Sitzungen, Tokens)
  werden entfernt, der Datensatz bleibt für die Historie ("Gelöschter Benutzer #ID"). Benutzer
  werden nie per `DELETE` entfernt; Fremdschlüssel auf `fe_users` kaskadieren nicht.
- Gebremst werden Anmeldung, Registrierung, Passwort zurücksetzen, E-Mail-Bestätigung und
  Einladung annehmen (`fe_auth_attempts`, `action` = `login`, `register`, `password_reset`,
  `reset_confirm`, `verify_email`, `verify_resend`, `invitation_accept`; `reset_confirm` seit
  Migration `0008`, `verify_resend` seit `0009`, mit E-Mail-Hash, `invitation_accept` seit `0010`).
  E-Mail nur als SHA-256 (bei `verify_email`, `reset_confirm` und `invitation_accept` ohne E-Mail möglich), IP-Adressen nur gekürzt
  (IPv4 /24, IPv6 /48, binär). Einträge werden nach 24 Stunden beim Aufruf gelöscht (kein Cron).
- E-Mail-Absender: `"Feuerwehr Geräteverwaltung" <feuerwehr-app@prodad.de>`.

## Benutzerverwaltung im Code
- Code in `src/User/`: `EmailAddress` (getrimmt, kleingeschrieben, mindestens so streng wie
  `chk_fe_users_email`, zusätzlich Domain mit Punkt), `PersonName` (für `name` und
  `display_name`, Regeln wie `TenantName`), Enums `UserRole` und `UserStatus` (Werte wie die
  CHECK-Regeln), `User` (bewusst ohne Passwort-Hash), `UserRepository`. Fachliche Fehler erben
  von `UserException` (deutsche Meldung für Benutzer).
- Passwörter (`src/User/Password/`): `PasswordPolicy::check()` mit `PasswordContext` (E-Mail,
  Namen, Stadt). Länge per `mb_strlen`, nicht getrimmt, keine Steuerzeichen. Verglichen wird in
  der Vergleichsform `PasswordPolicy::fold()` (zerlegte Umlaute zusammensetzen, Case-Folding, ß = ss;
  ohne intl keine vollständige Unicode-Normalisierung).
  - Sperrliste `resources/password-blocklist.txt`: ein Eintrag je Zeile, in Vergleichsform,
    `#` = Kommentar, ohne Duplikate, LF (per Test geprüft). Gesperrt ist ein exakter Treffer und ein
    Grundwort mit angehängten Ziffern/Sonderzeichen ("Feuerwehr2025!" → `feuerwehr`).
  - Persönliche Daten: Teile ab 4 Zeichen (`MIN_FRAGMENT_LENGTH`) aus dem lokalen Teil der E-Mail
    (ganz und in Wörtern), den Namen, dem Kürzel (ganz und in Wörtern) und dem Stadtnamen.
    Allgemeine Wörter aus Stadtname und Kürzel (`TENANT_STOPWORDS`, z. B. feuerwehr, stadt,
    löschzug) zählen nicht; Ortsname und vollständiges Kürzel bleiben gesperrt.
  - Meldungen enthalten nie das Passwort; Parameter mit Passwort oder Hash tragen
    `#[\SensitiveParameter]`. `PasswordHasher`: `hash()`, `verify()`, `needsRehash()`,
    `dummyHash()` (Argon2id; ohne Argumente Standardparameter, andere nur für Tests, siehe
    "Argon2id in Tests"). Anwendung und CLI reichen ihn weiter (`Application` →
    `RequestContext::$hasher`, `UserCommand`); Handler erzeugen keinen eigenen.
- `UserRepository`: ändernde Methoden (`lock`, `unlock`, `changeRole`, `setPassword`,
  `anonymize`, `register`, `verifyEmail`, `approve`, `reject`, `changeDisplayName`,
  `anonymizeStaleUnverified`, `createTenantWithInvitedAdmin`, `inviteAdmin`, `renewInvitation`,
  `acceptInvitation`, `protect`, `unprotect`, `setDeviceLogNotification`) öffnen eine eigene Transaktion (eine bereits offene ist ein Fehler) und sperren
  zuerst alle Konten derselben Stadt bzw. alle Super-Admins (`FOR UPDATE` über
  `FORCE INDEX (uq_fe_users_tenant_email)`, immer dieselbe Abfrage → gleiche Sperrreihenfolge,
  andere Städte unberührt). Danach Regeln prüfen, dann schreiben.
  - Der letzte aktive (`status = active`), nicht gesperrte Admin einer Stadt bzw. Super-Admin wird
    nicht gesperrt, herabgestuft oder anonymisiert (`UserRuleViolationException`).
  - Die Rolle `superadmin` wird per `changeRole` weder vergeben noch entzogen. `setPassword` nicht
    für `invited` (eigener Ablauf). Gelöschte Konten werden nicht mehr geändert.
  - Super-Admins: `anonymize` immer abgelehnt (`superAdminNotDeletable`). Geschützte Konten:
    `lock`, `changeRole`, `changeDisplayName` abgelehnt (`protectedAccount`); `protect` nur für
    aktive, nicht gesperrte Super-Admins (`notProtectable`); `protect`/`unprotect` wiederholbar
    (`bool`). `setDeviceLogNotification` nur für Admins und Super-Admins (`deviceLogNotAllowed`).
    `listDeviceLogRecipients(Tenant)`: aktiv, nicht gesperrt, Option an; Admins dieser Stadt, dann
    alle Super-Admins (nach ID).
  - Sperren, Rollenwechsel, Passwort setzen und Anonymisieren löschen alle `fe_sessions` des
    Kontos; Anonymisieren zusätzlich alle `fe_user_tokens`. Abgelehnte Änderungen lassen alles
    unverändert (Rollback).
  - Keine Methode zum `DELETE` von Benutzern hinzufügen.
  - Ausnahme ohne Sperre der Stadt: `rehashPassword` (nur nach erfolgreicher Anmeldung) schreibt
    den neuen Hash nur, wenn noch der alte gespeichert ist, und beendet keine Sitzungen.
    `passwordHash()` liefert den Hash nur für die Anmeldung; `User` enthält ihn weiterhin nicht.
- Kommandozeile `bin/user.php` (Code `src/Cli/UserCommand.php`): `superadmin:create --email=…
  --name=…`, `superadmin:list` (mit Spalte "Geschützt"), `superadmin:password --email=…`,
  `superadmin:lock --email=…`, `superadmin:unlock --email=…`, `superadmin:protect --email=…`,
  `superadmin:unprotect --email=… --confirm=…` (`--confirm` Pflicht und exakt gleich `--email`,
  bei anderen Befehlen verboten; sonst Exit-Code 2), `list --tenant=…`, `--config=PFAD`; Exit-Codes
  0/1/2. `superadmin:lock` auf ein geschütztes Konto: Hinweis auf `superadmin:unprotect`, Exit-Code 1.
  Auf dem Server: `cd ~/htdocs/apps/firefighting-equipment && php85 bin/user.php superadmin:list`.
  - Passwörter nie als Argument (`--password=…` wird abgelehnt, ohne den Wert auszugeben).
    Verdeckte Eingabe mit Wiederholung per `stty` (`TerminalPasswordPrompt`); unter Windows oder
    ohne Terminal Abbruch mit Hinweis auf `--password-stdin`. Keine PowerShell-Lösung.
  - `--password-stdin` nur für Tests und Skripte (entfernt genau ein Zeilenende).
  - Ausgaben enthalten nie Hashes. Tabellen über `TextTable` (auch `bin/tenant.php`).
  - `superadmin:password` sendet danach "Ihr Passwort wurde geändert" über den konfigurierten
    Transport; schlägt das fehl, Hinweis auf stderr, Exit-Code bleibt 0. Tests übergeben
    `FakeMailer` und einen eigenen `ErrorReporter`.

## Anmeldung und Sitzungen im Code
- Code in `src/Auth/`, Endpunkte in `src/Api/V1/Auth/` (alle unter `service` und unter jeder
  Stadt): `POST auth/login` und `POST auth/logout` (öffentlich; Logout wiederholbar, löscht das
  Cookie immer), `GET auth/me`, `GET auth/sessions`, `DELETE auth/sessions/{id}`,
  `POST auth/sessions/logout-others`, `POST auth/password` (angemeldet),
  `GET`/`POST auth/notifications` (eigene Benachrichtigung; unter Städten `Access::Admin`, unter
  `service` `Access::SuperAdmin`; Rechte in `NotificationSettingsTest`, da die Route in beiden
  Bereichen existiert und nicht in die Access-Tests passt),
  `POST auth/password-reset/request` und `POST auth/password-reset/confirm` (öffentlich, siehe
  "Passwort vergessen"). Nur unter Städten: `POST auth/register`, `POST auth/email/verify`,
  `POST auth/email/resend` (öffentlich, siehe "Einladung, Registrierung und E-Mail-Bestätigung"),
  `POST auth/invitation/accept` (öffentlich, siehe "Städte verwalten und Admins einladen").
- Token (`SessionToken`): 32 Zufallsbytes, im Cookie base64url ohne Auffüllung (43 Zeichen, nur
  kanonische Form), in `fe_sessions.token_hash` nur SHA-256. Cookie (`SessionCookie`):
  `ff_session`, `HttpOnly; Secure; SameSite=Strict`, `Path=/firefighting/<kürzel>/` bzw.
  `/firefighting/service/`, `Max-Age` = Höchstdauer der Rolle; Löschen mit `Max-Age=0`.
- Prüfung bei jeder Anfrage (`SessionManager::resume`): Höchstdauer (`expires_at`) und
  Inaktivität je Rolle (`SessionLifetime`), Konto `active` und nicht gesperrt, Konto passt zum
  Bereich (Super-Admin nur unter `service`, Stadt-Konto nur unter seiner Stadt; sonst 401, die
  Sitzung bleibt bestehen). Alle Zeitvergleiche rechnet die Datenbank mit `UTC_TIMESTAMP()`.
  Abgelaufene Sitzungen werden beim Antreffen gelöscht. `last_seen_at` wird höchstens alle
  5 Minuten geschrieben (`SessionLifetime::TOUCH_INTERVAL_SECONDS`).
- Aufräumen ohne Cron bei jedem Login: abgelaufene Sitzungen (`SessionRepository::deleteExpired`,
  Inaktivität je Rolle) und `fe_auth_attempts` älter als 24 Stunden (`LoginThrottle::deleteOld`).
- Anmeldung (`Authenticator::login`): Unter einer Stadt werden nur Konten dieser Stadt gesucht,
  unter `service` nur Super-Admins. Das Passwort wird immer mit Argon2id geprüft, ohne Konto oder
  ohne Hash (`invited`, `deleted`) gegen `PasswordHasher::dummyHash()`: immer mit den Parametern
  des verwendeten Hashers, bei Standardparametern die Konstante `DEFAULT_DUMMY_HASH` (per Test
  gesichert), sonst einmal berechnet, und zwar vor der Kontosuche: gleiche Meldung (401 `invalid_credentials`) und gleiche Laufzeit für unbekannte
  E-Mail und falsches Passwort. Erst bei richtigem Passwort, ohne Sitzung und in dieser
  Reihenfolge: gesperrt → 403 `account_locked`, `unverified` → 403 `email_not_verified`,
  `pending` → 403 `pending_approval`. `needsRehash` → `rehashPassword`. Eine ältere Sitzung
  desselben Browsers (mitgesendetes Cookie) wird beim Login ersetzt.
- Bremse (`LoginThrottle`, `action = login`, `tenant_id NULL` = `service`), Zeitfenster
  15 Minuten: 5 Fehlversuche je E-Mail-Hash und Stadt (nur Fehlversuche nach dem letzten Erfolg
  zählen) oder 30 Fehlversuche je IP-Präfix über alle Städte (Erfolg setzt nichts zurück) → 429
  `too_many_attempts`, `Retry-After` = Sekunden, bis der älteste zählende Fehlversuch aus dem
  Fenster fällt. Gezählt wird vor der Passwortprüfung; abgewiesene Anfragen werden nicht
  gespeichert. Falsche aktuelle Passwörter bei `auth/password` zählen in dieselbe Bremse.
  IP-Adresse nur aus `REMOTE_ADDR` (`IpPrefix`, IPv4 /24, IPv6 /48, IPv4 in IPv6 als IPv4).
- Gerätebezeichnung (`DeviceLabel`): nur feste Wörter (Browser, System), z. B. "Chrome auf
  Android", sonst "Unbekanntes Gerät"; nie Teile des User-Agents übernehmen.
- Eigene Sitzungen: `DELETE auth/sessions/{id}` nur für eigene (fremd oder unbekannt → 404
  `not_found`); die aktuelle zu beenden wirkt wie Logout (Cookie wird gelöscht).
- Passwort ändern: aktuelles Passwort prüfen (falsch → 403 `invalid_current_password`), dann
  `PasswordPolicy` mit Kontext E-Mail, Name, Anzeigename und Stadt (Verstoß → 422
  `weak_password` mit der Meldung der Regeln), dann `UserRepository::setPassword` (beendet alle
  Sitzungen) und neue Sitzung für das aktuelle Gerät, zuletzt Mail "Ihr Passwort wurde geändert".
- Tests: `tests/Integration/Auth/AuthTestCase.php` (zwei Städte, Konten mit echtem Hash, Anfragen
  über die ganze Anwendung, beides mit `TestPasswordHasher` außer bei `app(hasher: …)`, `ageSession()` zum Zurückdatieren von Sitzungen;
  Mails landen in `$this->mailer` (`FakeMailer`), Links beginnen mit `BASE_URL`, die Mindestdauer
  von `password-reset/request`, `register` und `email/resend` ist 0; `registeredAccount()` legt
  Konten im Status `unverified`/`pending` samt Einlader und Code an; `send()` verlangt eine
  JSON-Antwort, `sendAny()` liefert jede `Response`, `responseBody()` liest auch einen CSV-Download,
  und zwar vor der nächsten Datenbankabfrage, da er ungepuffert liest). Rechte aller Admin-Routen
  prüft `AdminRoutesAccessTest` (neue Admin-Routen dort eintragen), die der lesenden Routen unter
  einer Stadt (`Access::Authenticated`, z. B. Gerätetypen, Standorte, Prüffristen)
  `TenantReadRoutesAccessTest`
  (neue dort eintragen).

## E-Mail-Versand
- Code in `src/Mail/`: `MailMessage` (Empfänger, Name, Betreff, Text), Schnittstelle `Mailer`
  (`send()` liefert die Message-ID, Fehler als `MailException`), `SendmailMailer` (`mail()` mit
  `-ffeuerwehr-app@prodad.de`), `FileMailer` (lokal, `.eml`), `ConfiguredMailer` (laut Gruppe
  `mail`, lädt die Konfiguration erst beim ersten Versand), `MimeMessage` (Kopfzeilen, Kodierung),
  `MailTemplates` (alle Texte), `Notifier`. Tests verwenden `tests/Support/FakeMailer`, nie einen
  echten Transport.
- Nur Klartext (kein HTML) in Version 1, UTF-8, quoted-printable, CRLF. Absender immer
  `"Feuerwehr Geräteverwaltung" <feuerwehr-app@prodad.de>`. Betreff und Namen außerhalb von ASCII
  als RFC-2047-encoded-words (Base64, höchstens 75 Zeichen je Wort, nur an Zeichengrenzen
  geteilt). Kopfzeilen `Date` (UTC), `Message-ID` (`<32 Hex@prodad.de>`), `MIME-Version`,
  `Content-Type`, `Content-Transfer-Encoding` und in jeder Mail `Auto-Submitted: auto-generated`.
- Header-Injection: Steuerzeichen (CR, LF, NUL, Tab, U+2028/2029 …) in Empfänger, Name oder Betreff
  → `MailException`, es wird nichts gesendet. Betreff höchstens 200 Zeichen. Empfängeradressen
  nur ASCII (ohne intl kein IDN, kein SMTPUTF8); andere werden mit `MailException` abgelehnt.
- Vorlagen nur in `MailTemplates`: deutsch, Zeilen höchstens 76 Zeichen (außer Links, die am Stück
  bleiben), Zeiten in Europe/Berlin, Signatur mit `-- `. Neue Mails dort ergänzen, mit Test.
- Versandfehler erreichen den Aufrufer nie: Handler und CLI senden über `Notifier`
  (`$context->notifier()`), der Fehler als `mail_failed (<zweck>, Konto #ID)` über `ErrorReporter`
  protokolliert, nie mit Empfänger, Text, Link oder Token. Meldungen von `MailException` enthalten
  daher nie den Text der Mail.
- Absolute Links nur über `AppUrls` (`base_url` + `AppPath::PREFIX` + Kürzel bzw. `service`).
- `FileMailer`: `JJJJMMTT-HHMMSSZ-<zufall>.eml`, Rechte 600, Ordner bei Bedarf mit 700 angelegt;
  Standard `var/mail` (in `.gitignore`, liegt außerhalb von `public/`).
- `bin/mail-test.php --to=ADRESSE [--config=PFAD]` (Code `src/Cli/MailTestCommand.php`): Testmail
  über den konfigurierten Transport, zeigt Transport (bei SMTP mit Server), Message-ID und Dauer;
  Fehler werden hier angezeigt (bei SMTP Schritt und Antwort des Servers, dazu Transport, Server
  und Dauer, nie Zugangsdaten). Exit-Codes 0/1/2. Auf dem Server:
  `cd ~/htdocs/apps/firefighting-equipment && php85 bin/mail-test.php --to=…`.
- Mehrere Mails einer Anfrage: `Mailer::sendMany(messages, deadline)` liefert je Mail die
  Message-ID oder die `MailException`; nach `deadline` (wie `microtime(true)`) wird keine Mail mehr
  begonnen (`MailException::timeBudgetExceeded`). Transporte ohne Verbindung über den Trait
  `SendsEachMessage`; `SmtpMailer` verwendet EINE Verbindung mit einer Anmeldung (nach einer
  abgelehnten Mail `RSET`, dann die nächste; scheitert Verbindung, Anmeldung oder `RSET`, erhalten
  alle offenen Mails den Fehler; die Frist `timeout` gilt für die ganze Verbindung, höchstens bis
  `deadline`). `Notifier::sendMany(purpose, [[kontoId, aufbau], …], deadline)` baut jede Mail
  einzeln und protokolliert jeden Fehler wie `send()`.
- `MimeMessage::toString()` (für `.eml` und SMTP) faltet Kopfzeilen über 78 Zeichen an Leerzeichen
  (nie vor einem leeren Wort); `mail()` erhält `To`/`Subject` weiterhin ungefaltet.

### SMTP (`src/Mail/Smtp/`)
- Eigener Client ohne Bibliothek (nur `openssl`): `SmtpConfig`, `SmtpEncryption`, `SmtpMailer`
  (Protokoll), `SmtpConnection` (Socket, TLS, Frist), `SmtpReply` (Antwort, Bereinigung). Keine
  Bibliothek wie PHPMailer oder Symfony Mailer hinzufügen; `MimeMessage` bleibt die einzige Stelle,
  an der Mails gebaut werden.
- Konfiguration `mail.smtp`: `username` (Pflicht, genau `MimeMessage::FROM_ADDRESS`, sonst Fehler),
  `password` (Pflicht, nicht leer), `host` (Standard `smtp.strato.de`, Hostname klein, ohne Schema
  und Port), `port` (Standard 465, int), `encryption` (`ssl` Standard = implizites TLS, `starttls`;
  unverschlüsselt gibt es nicht), `timeout` (Standard 10, 1–60 s, Frist für den ganzen Versand).
  Unbekannte Schlüssel sind ein Fehler.
- Zertifikat immer prüfen: `verify_peer`, `verify_peer_name`, `peer_name` = Host, keine
  selbstsignierten, mindestens TLS 1.2. Es darf keine Einstellung geben, die das abschaltet (per
  Test gesichert). Der Konstruktorparameter `caFile` von `SmtpMailer` ist nur für Tests (Test-CA)
  und wird nie aus der Konfiguration gesetzt.
- Ablauf je Mail: Verbindung, ggf. TLS, `220`, `EHLO prodad.de`, bei `starttls` `STARTTLS` (fehlt
  es → Abbruch) und erneut `EHLO`, AUTH nur verschlüsselt (PLAIN, sonst LOGIN, sonst Abbruch),
  `MAIL FROM:<feuerwehr-app@prodad.de>`, `RCPT TO`, `DATA` mit Dot-Stuffing, `QUIT` (Fehler dabei
  zählen nicht mehr).
- Passwort: `#[\SensitiveParameter]` überall, wo es oder die AUTH-Zeile übergeben wird;
  `SmtpConfig::__debugInfo()` blendet es aus. Fehlermeldungen (`MailException`) enthalten nur
  Schritt, Server und bereinigte Antwort bzw. PHP-Warnung (`SmtpReply::sanitize`: Steuerzeichen
  entfernt, gekürzt, E-Mail-Adressen → `<Adresse>`, da sie ins `error_log` gelangen); nie gesendete
  Daten.
- Tests ohne echten Mailserver: `tests/Support/FakeSmtpServer` (startet
  `tests/Support/fake-smtp-server.php` als eigenen Prozess auf `127.0.0.1` mit Szenario) und
  `TestCertificates` (Test-CA zur Laufzeit, eigene minimale `openssl.cnf`; nichts committen).
  Optionaler Live-Test `tests/Integration/Mail/SmtpLiveTest.php` nur mit Umgebungsvariable
  `FF_SMTP_LIVE_TO` und `mail.transport = smtp` in `config/config.test.php`, sonst übersprungen.
- Antwortzeit von "Passwort vergessen": SMTP dauert länger als sendmail. Nach dem Umstellen auf dem
  Server mit `bin/mail-test.php` messen und `MIN_DURATION_SECONDS` anpassen; kein eigener kürzerer
  Timeout für `password-reset/request`.

## Passwort vergessen
- `POST auth/password-reset/request {email}` (öffentlich, unter `service` und jeder Stadt,
  `PasswordResetRequestHandler`): ungültige Schreibweise → 400 `invalid_request`; sonst immer 202
  mit derselben Meldung, ob ein Konto existiert oder nicht. Antwortzeit mindestens 1,5 s
  (`MinimumDuration`, `MIN_DURATION_SECONDS`; wird nach Messung auf STRATO ggf. angepasst); wird sie
  überschritten, steht `password_reset_slow` im Protokoll. Mail nur an aktive, nicht gesperrte
  Konten des Bereichs (Stadt: deren Konten, `service`: nur Super-Admins). Link
  `<base_url>/firefighting/<kürzel>/passwort-zuruecksetzen#token=…` (Token hinter `#`, nie im
  Pfad oder Query-String). Ein neuer Link ersetzt den alten (`UserTokenRepository::replace`).
- Bremse (`PasswordResetThrottle`): Anforderung (`password_reset`) zählt jede angenommene Anfrage,
  auch für unbekannte Adressen; höchstens 3 je E-Mail-Hash und Bereich und 20 je IP-Präfix (über
  alle Städte) pro Stunde. Einlösen (`reset_confirm`, ohne E-Mail): höchstens 10 Fehlversuche je
  IP-Präfix in 15 Minuten. Darüber 429 `too_many_attempts` mit `Retry-After`; abgewiesene Anfragen
  werden nicht gespeichert.
- `POST auth/password-reset/confirm {token, new_password}` (öffentlich,
  `PasswordResetConfirmHandler`): ungültig (falsches Format, unbekannt, anderer Zweck, abgelaufen,
  benutzt, Konto nicht aktiv oder gesperrt, anderer Bereich) → 400 `invalid_token`, bewusst nicht
  unterschieden, zählt in die Bremse. Danach `PasswordPolicy` (422 `weak_password`, der Link bleibt
  gültig). `UserRepository::resetPassword` löscht den Link und setzt den Hash in einer
  Transaktion (gleichzeitiges Einlösen: nur einer gewinnt) und beendet alle Sitzungen. Keine neue
  Sitzung; ein mitgesendetes Cookie wird gelöscht. Ein erfolgreicher `login`-Eintrag hebt die
  Bremse der Anmeldung für dieses Konto auf. Abgelaufene Links werden beim Aufruf gelöscht.
- Nach jeder Passwortänderung geht die Mail "Ihr Passwort wurde geändert" an das Konto:
  `POST auth/password`, `password-reset/confirm` und `bin/user.php superadmin:password`.

## Einladung, Registrierung und E-Mail-Bestätigung
- Nur unter Städten (unter `service` gibt es die Routen nicht, 404). Code in `src/Invitation/`
  (`InvitationCodeValue` Klartext/Normalisierung/Hash, `InvitationCodeLabel`, `InvitationCode`,
  `InvitationCodeStatus`, `InvitationCodeRepository`), Endpunkte in `src/Api/V1/Invitation/` und
  `src/Api/V1/Auth/` (`RegisterHandler`, `EmailVerifyHandler`, `EmailResendHandler`), Bremse
  `RegistrationThrottle`.
- Einladungscodes (Admin): `POST invitation-codes {label?, valid_days 1–90, max_uses 1–500}` → 201
  mit `code` (Klartext `XXXX-XXXX-XXXX`, nur in dieser Antwort; leere Bezeichnung = keine),
  `GET invitation-codes` (neueste zuerst, nie Klartext oder Hash), `POST invitation-codes/{id}/revoke
  {}` (wiederholbar; fremd/unbekannt 404). Status wird berechnet (Zeitvergleich in der Datenbank),
  Vorrang: `revoked`, `exhausted`, `expired`, `active`. Codes bleiben gültig, wenn ihr Ersteller
  gesperrt oder gelöscht wird.
- `POST auth/register {code, email, password, name}`: Reihenfolge Schreibweise (400), Bremse (429),
  Code (400 `invalid_invitation_code`, eine gemeinsame Meldung, zählt als Fehlversuch),
  `PasswordPolicy` mit E-Mail, Name und Stadt (422, wird nicht gezählt), dann
  `UserRepository::register` in einer Transaktion: Stadt sperren, Code `FOR UPDATE` erneut prüfen,
  bei schon vergebener E-Mail nichts ändern (keine Nutzung), sonst `use_count + 1` und Konto
  `unverified`/`user` anlegen. Neues Konto: Link `verify_email` (24 h), Mail
  `<base_url>/firefighting/<kürzel>/email-bestaetigen#token=…`. Vorhandene E-Mail: Hinweis-Mail an
  den Besitzer. Beides gleiche Antwort (202, Argon2id in beiden Fällen, je eine Mail, Mindestdauer
  1,5 s, Überschreitung als `registration_slow` im Protokoll).
- `POST auth/email/verify {token}`: ungültig (Format, unbekannt, anderer Zweck, abgelaufen, benutzt,
  Konto nicht `unverified` oder gesperrt, andere Stadt) → 400 `invalid_token`, zählt in die Bremse.
  `UserRepository::verifyEmail` löst den Link ein und setzt `pending` (eine Transaktion). Danach
  Mail "Neue Registrierung wartet auf Freischaltung" an jeden aktiven, nicht gesperrten Admin der
  Stadt (`listActiveAdmins`) mit Link auf die Benutzerverwaltung (`AppUrls::users`, `benutzer`).
- `POST auth/email/resend {email}`: immer 202 mit derselben Meldung (Mindestdauer 1,5 s); Mail nur an
  Konten der Stadt mit `unverified`, nicht gesperrt; neuer Link ersetzt den alten.
- Bremse (`RegistrationThrottle`): `register` höchstens 10 ungültige Codes je IP-Präfix in 15 Minuten,
  angenommene Registrierungen (auch mit vorhandener E-Mail) 3 je E-Mail-Hash und Stadt und 30 je
  IP-Präfix (alle Städte; Dienstabend im WLAN der Wache) pro Stunde. `verify_email` 10 Fehlversuche
  je IP-Präfix in 15 Minuten. `verify_resend` jede angenommene Anfrage, 3 je E-Mail-Hash und Stadt,
  20 je IP-Präfix pro Stunde. Abgewiesene Anfragen werden nicht gespeichert.
- Aufräumen ohne Cron: Konten, die `UserRepository::UNVERIFIED_RETENTION_DAYS` (7) Tage nach der
  Registrierung noch `unverified` sind, werden anonymisiert (`anonymizeStaleUnverified`, nur die
  aktuelle Stadt, erst ohne Sperre geprüft) bei `register`, `email/verify`, `email/resend` und
  `GET users`. Die Code-Nutzung wird nicht zurückgegeben. `pending` bleibt, bis ein Admin handelt.
- Mails zu Registrierung und Bestätigung (Bestätigungslink, Hinweis bei vorhandener Adresse,
  Freischaltung) enthalten den Hinweis, `feuerwehr-app@prodad.de` zu den Kontakten hinzuzufügen.

## Benutzerverwaltung durch den Admin
- Nur Admins, nur Konten der eigenen Stadt (`Access::Admin`; Admin einer anderen Stadt und
  Super-Admin unter einem Stadt-Pfad: 401, `user`: 403). Code: `UserAdministration` (Stadt-Bezug:
  fremde/unbekannte IDs → `UserNotFoundException` → 404; Selbstschutz) über `UserRepository` (alle
  Regeln dort gelten weiter), Endpunkte `src/Api/V1/Users/` (`UserListHandler`,
  `UserActionHandler` mit `UserAction`, `UserJson`).
- `GET users[?status=…]` (ohne Filter alle außer `deleted`; unbekannter Status 400),
  `GET users/{id}`, `POST users/{id}/approve {display_name, role}` (nur `pending` → `active`, Rolle
  `user`/`admin`; Mail "Ihr Konto wurde freigeschaltet"), `POST users/{id}/reject {}` (nur
  `unverified`/`pending`; anonymisiert, danach Mail "Ihre Registrierung wurde nicht freigegeben" an
  die vorher gespeicherte Adresse), `POST users/{id}/lock`/`unlock {}`, `POST users/{id}/role
  {role}`, `POST users/{id}/display-name {display_name}`, `DELETE users/{id} {}` (anonymisieren).
  Antwort immer `{"user": …}` mit dem Stand danach.
- Ein Admin darf sich nicht selbst sperren, herabstufen (eigene Rolle ändern) oder löschen →
  409 `rule_violation`; ebenso Regelverstöße des Repositorys (letzter Admin, falscher Status,
  gelöschtes Konto). Unveränderte Rolle und eigener Anzeigename sind erlaubt.

## Städte verwalten und Admins einladen
- Nur Super-Admins, nur unter `service` (`Access::SuperAdmin`; Konten einer Stadt haben dort keine
  passende Sitzung: 401; unter einem Stadt-Pfad gibt es die Routen nicht: 404). Rechte aller Routen
  prüft `SuperAdminRoutesAccessTest` (neue Super-Admin-Routen dort eintragen). Code:
  `TenantAdministration` (Städte per ID, unbekannt → `TenantNotFoundException` → 404; Konten nur
  der jeweiligen Stadt; keine Einladung in eine gesperrte Stadt) über `TenantRepository` und
  `UserRepository`, Endpunkte `src/Api/V1/Tenants/` (`TenantListHandler`, `TenantCreateHandler`,
  `TenantActionHandler` mit `TenantAction`, `AdminInvitationHandler`, `TenantUserListHandler`,
  `TenantJson`, `TenantFields`).
- Der Router kennt nur Ganzzahl-Platzhalter; Städte werden daher in der API über ihre ID
  angesprochen, das Kürzel steht nur in den Antworten.
- Super-Admins (`src/Api/V1/SuperAdmins/`, `SuperAdminListHandler`, `SuperAdminLockHandler`,
  `SuperAdminJson` = `UserJson` + `protected`): `GET superadmins` → `{"super_admins": [...]}` (nach
  ID), `POST superadmins/{id}/lock` bzw. `unlock {}` → `{"super_admin": …}` (wiederholbar; Sperren
  beendet die Sitzungen). Unbekannt oder Konto einer Stadt → 404; sich selbst sperren, geschütztes
  Konto, letzter aktiver Super-Admin → 409 `rule_violation`. Anlegen, Passwort und Schutz nur per
  Kommandozeile.
- `GET tenants/slug-available?slug=…` (`TenantSlugAvailabilityHandler`): immer 200 `{slug, valid,
  available, message, suggestion}`; `slug` = Eingabe unverändert. Ungültig → `valid`/`available`
  false, `message` = Meldung von `TenantSlug`, `suggestion` = Kleinschreibung oder `null`. Gültig →
  `available` (vergeben auch durch gesperrte Städte), `message` „Das Kürzel ist verfügbar.“ bzw.
  „Das Kürzel ist bereits vergeben.“, `suggestion` `null`. Der Vorschlag wird nicht auf
  Verfügbarkeit geprüft. Fehlt `slug` → 400. Nur Vorabprüfung, verbindlich bleibt `POST tenants`
  (409 `slug_taken`). Exakter Pfad, hat Vorrang vor `tenants/{id}`.
- `GET tenants` (nach Kürzel, mit `counts`: `users_active` = Status `active` und nicht gesperrt,
  `admins_active`, `admins_invited`), `GET tenants/{id}`, `POST tenants {slug, name, admin_email}`
  → 201 `{tenant, user, mail_sent}`, `POST tenants/{id}/rename {name}`, `POST tenants/{id}/lock`
  bzw. `unlock {}` (wiederholbar; Sperren beendet alle Sitzungen der Stadt), `POST
  tenants/{id}/admins {email}` → 201 `{user, mail_sent}`, `POST
  tenants/{id}/users/{user}/resend-invitation {}` → `{user, mail_sent}`, `GET
  tenants/{id}/users[?status=…]` (nur lesend, kein Aufräumen; Filter wie `GET users`).
- Anlegen: `UserRepository::createTenantWithInvitedAdmin` legt Stadt, Konto (`admin`, `invited`,
  ohne Name und Passwort) und Link `set_password` (24 h) in EINER Transaktion an; danach Mail mit
  `<base_url>/firefighting/<kürzel>/einladung-annehmen#token=…` (`AppUrls::acceptInvitation`).
  Einladen in eine bestehende Stadt (`UserRepository::inviteAdmin`) ebenso. Erneut senden
  (`renewInvitation`) nur für `invited` und nicht gesperrt; der neue Link ersetzt den alten.
- `mail_sent` ist die Rückgabe von `Notifier::send` (false: Versand gescheitert, erneut senden).
  Link und Token stehen nie in einer API-Antwort.
- Fehler: Kürzel/Name/E-Mail ungültig → 400 (Meldung der Regel, z. B. Vorschlag in
  Kleinbuchstaben); Kürzel vergeben → 409 `slug_taken`; Adresse hat in der Stadt schon ein Konto
  (jeder Status außer `deleted`, auch `invited`) → 409 `email_taken` mit verständlicher Meldung
  (der Aufrufer ist Super-Admin, es gibt nichts zu verbergen); gesperrte Stadt, keine offene
  Einladung, gesperrtes Konto → 409 `rule_violation`; Konto einer anderen Stadt → 404.
- `POST auth/invitation/accept {token, name, password}` (öffentlich, nur unter Städten,
  `InvitationAcceptHandler`): Reihenfolge Schreibweise (400), Bremse (429), Link prüfen (Zweck
  `set_password`, Konto der aktuellen Stadt, `invited`, nicht gesperrt; sonst 400 `invalid_token`,
  zählt in die Bremse), `PersonName` (400), `PasswordPolicy` mit E-Mail, Name und Stadt (422; Name
  und Passwort verbrauchen den Link nicht und zählen nicht), dann `UserRepository::acceptInvitation`
  (Transaktion: Link löschen, `name` = `display_name` = Name, Hash, `email_verified_at` = jetzt,
  `active`; gleichzeitiges Einlösen: nur einer gewinnt). Keine Sitzung (wie bei Passwort vergessen);
  ein erfolgreicher `login`-Eintrag hebt die Bremse der Anmeldung für das Konto auf. Gesperrte
  Stadt: 403 `tenant_inactive` (vor dem Handler, nicht gezählt). Danach Mail "Einladung
  angenommen" an alle aktiven, nicht gesperrten Super-Admins (`listActiveSuperAdmins`).
- Bremse (`InvitationAcceptThrottle`, `action = invitation_accept`, ohne E-Mail): höchstens 10
  Fehlversuche je IP-Präfix in 15 Minuten; abgewiesene Anfragen werden nicht gespeichert.
- Mails (`MailTemplates::adminInvitation`, `invitationAccepted`): Einladung mit Stadtname,
  Gültigkeit 24 h und Kontakt-Hinweis; Benachrichtigung mit Stadt, Name, E-Mail und Zeit
  (Europe/Berlin). Zwecke im Protokoll: `admin_invitation`, `invitation_accepted`.

## Standorte und Geräte
- Stand: Schema (Migrationen `0011`–`0015`, Scan-Code `0019`/`0020`, Offline-Buchungen und Hinweise
  `0023`–`0028`), Start-Gerätetypen neuer Städte, die API für
  Gerätetypen und Standorte (siehe "Gerätetypen und Standorte (API)") und für Geräte (anlegen,
  Stammdaten, Prüfdatum, Liste, Einzelansicht, Umbuchen/Status, Aussondern/Wiederaufnehmen,
  Historie; siehe "Geräte (API)"), Prüffristen und Zusammenfassung (siehe "Prüffristen und
  Zusammenfassung (API)"), CSV-Export und -Import (siehe "CSV-Export (API)" und "CSV-Import (API)"),
  Offline-Buchungen und Hinweise (siehe "Offline-Buchungen (API)" und "Hinweise (API)").
  Schema-Tests in
  `tests/Integration/Device/` (`DeviceSchemaTestCase`), Zeilen per SQL über
  den Trait `DeviceRows` (auch in API-Tests auf Basis von `AuthTestCase`).
- Rechte: `user` darf ansehen, umbuchen, Status setzen (einsatzbereit, in Wartung, defekt) und CSV
  exportieren. `admin` zusätzlich: aussondern und wieder aufnehmen, Prüfdatum ("nächste Prüfung
  fällig") ändern, Geräte anlegen und Stammdaten bearbeiten, Gerätetypen und Standorte verwalten,
  CSV importieren.
- Gerät (`fe_devices`): Pflicht Kennung, Gerätetyp, Standort, Status; optional Scan-Code,
  Seriennummer, Hersteller, Modell (zwei Felder), Beschaffungsdatum, Notiz (höchstens 1000 Zeichen), nächste
  Prüfung fällig. Datumsfelder sind `DATE` zwischen 1950-01-01 und 2099-12-31 (fängt Tippfehler wie
  "0202" ab). Leere Texte werden als `NULL` gespeichert. Typ und Standort nur aus derselben Stadt.
- Kennung: höchstens 30 Zeichen aus Buchstaben (alle Unicode-Buchstaben, zusammengesetzte Form),
  Ziffern `0-9`, Leerzeichen, `-`, `/`, `.`; keine Leerzeichen am Rand, mehrere Leerzeichen fasst der
  Code zu einem zusammen (die Datenbank lehnt doppelte ab). Angezeigt wie eingegeben, durch den Admin
  änderbar. Eindeutig je Stadt über alle Gerätetypen, nur unter nicht ausgesonderten Geräten
  (Wiederverwendung nach dem Aussondern erlaubt; Wiederaufnahme scheitert, wenn die Kennung
  inzwischen vergeben ist). Groß-/Kleinschreibung zählt nicht, aber ß ≠ ss und é ≠ e: Die gespeicherte
  Spalte `identifier_key` = `REPLACE(LOWER(identifier), 'ẞ', 'ß')` in `utf8mb4_bin`, bei
  ausgesonderten Geräten `NULL`, mit UNIQUE-Index (`LOWER()` kennt unter `utf8mb4_unicode_ci` das
  große ẞ nicht). Format per CHECK mit `REGEXP` und `\p{L}`.
- Scan-Code (`scan_code`, Migration `0019`, `ScanCode`): Inhalt eines Barcodes/QR-Codes/GS1-DataMatrix,
  höchstens 200 Zeichen, `utf8mb4_bin`, gespeichert wie eingegeben und exakt verglichen (Groß- und
  Kleinschreibung zählt, ß ≠ ss). Regeln (Code und CHECK `chk_fe_devices_scan_code` gemeinsam
  ändern): Leerzeichen, Tab, CR, LF an BEIDEN Enden entfernt (Scanner-Präfix/-Suffix), danach leer =
  `NULL`; im Inhalt erlaubt sind die Trennzeichen EOT (0x04), GS (0x1D) und RS (0x1E) (GS1,
  ISO/IEC 15434), alle anderen Steuerzeichen (`\p{Cc}`, auch Tab/CR/LF im Inhalt), `\p{Cf}` und
  U+2028/2029 werden abgelehnt; Leerzeichen im Inhalt bleiben unverändert. Lesbar in Meldungen und
  Mails über `ScanCode::display` (`[GS]`, `[RS]`, `[EOT]`; kein `<…>`, Mailtexte dürfen kein `<`
  enthalten). Eindeutig je Stadt nur unter nicht ausgesonderten Geräten über `scan_code_key` =
  `IF(status = 'retired', NULL, scan_code)` mit UNIQUE-Index `uq_fe_devices_tenant_scan_code`; der
  Scan-Code bleibt am ausgesonderten Gerät gespeichert. In der CHECK-Bedingung stehen die Zeichen als
  `'\\x{1D}'` (in SQL-Strings doppelter Backslash, sonst wird aus `\x` ein `x`).
- Status (`status`, `ascii_bin`): `ready` (einsatzbereit), `maintenance` (in Wartung), `defective`
  (defekt), `retired` (ausgesondert). Ausgesonderte Geräte sind aus normalen Listen und Prüffristen
  ausgeblendet, nicht umbuchbar; der Admin kann sie wieder aufnehmen. Geräte werden nie gelöscht
  (jedes hat ein Ereignis, der Fremdschlüssel verhindert das Löschen; einzige Ausnahme: "Gerätedaten
  zurücksetzen").
- Gleichzeitige Änderungen: Versionszähler `fe_devices.version` (ab 1, jede Änderung erhöht ihn); eine
  Änderung mit veralteter Version wird mit 409 `version_conflict` abgelehnt (siehe "Geräte (API)").
  Ausnahme: Offline-Buchungen mit Aktionszeit („der Letzte gewinnt“ über `state_changed_at` und
  `state_version`, siehe "Offline-Buchungen (API)").
- Gerätetypen (`fe_device_types`): je Stadt, Name je Stadt eindeutig (ohne Groß-/Kleinschreibung),
  vom Admin pflegbar, deaktivierbar (`active = 0`) statt löschbar. Start: Atemschutzmaske,
  Atemschutzgerät, Funkgerät, Wärmebildkamera (`DefaultDeviceTypes::NAMES`); bestehende Städte per
  Migration `0012`, neue in `TenantRepository::create` (eigene Transaktion oder die bereits offene
  von `createTenantWithInvitedAdmin`). Beide Listen immer gemeinsam ändern.
- Standorte (`fe_locations`): Art (`kind`) `vehicle` (Fahrzeug), `room` (Raum/Lager), `external`
  (Extern). Höchstens zwei Ebenen; ein Unterstandort hat immer dieselbe Art wie sein übergeordneter
  Standort. Die Datenbank erzwingt beides ohne Trigger über den Fremdschlüssel
  `(parent_id, tenant_id, parent_level, kind)` → `(id, tenant_id, level, kind)` mit
  `parent_level = 1` (CHECK mit `<=>`, sonst würde NULL die Prüfung umgehen). Folgen: kein
  Unterstandort eines Unterstandorts, keine andere Art, keine andere Stadt; Art und Ebene eines
  Standorts mit Unterstandorten sind nicht änderbar. Name je Stadt und übergeordnetem Standort
  eindeutig (`utf8mb4_unicode_ci`, also auch "Straße" = "Strasse"; `parent_key` 0 = Ebene 1). Nie
  gelöscht (Ausnahme: "Gerätedaten zurücksetzen"); sperrbar (`active = 0`) nur, wenn leer (keine nicht ausgesonderten Geräte, keine aktiven
  Unterstandorte; prüft der Code). Gesperrt = kein Ziel für Umbuchungen, die Historie bleibt.
- Historie (`fe_device_events`): unveränderlich, der Code schreibt nur `INSERT`, nie `UPDATE` oder
  `DELETE` (ohne Trigger nicht erzwingbar; kein Repository bekommt solche Methoden; einzige Ausnahme:
  "Gerätedaten zurücksetzen"). Wer (`user_id` aus derselben Stadt, Fremdschlüssel auf `fe_users`; anonymisierte Konten bleiben), Wann
  (`created_at`, UTC), optionaler Kommentar (höchstens 500 Zeichen). Ereignisse (`event_type`):
  `created` bzw. `imported` (CSV-Import; Ziel-Standort und -Status, nicht ausgesondert), `state_changed`
  (Umbuchung und/oder Statusänderung in EINEM Eintrag, Von/Nach je Paar, verschieden; auch Aussondern
  und Wiederaufnehmen), `due_changed` (Prüfdatum alt/neu, je auch leer), `field_changed` (Stammdaten:
  ein Eintrag je Feld `identifier`, `scan_code` (seit Migration `0020`), `device_type`,
  `serial_number`, `manufacturer`, `model`, `procured_on`, `note`, alter/neuer Wert als Text in `utf8mb4_bin`, beim Gerätetyp dessen Name zum
  Zeitpunkt der Änderung). Welche Spalten je Ereignis gefüllt sind, prüfen CHECK-Bedingungen.
  Seit Migration `0025`: `occurred_at` (Aktionszeit einer Offline-Buchung, nur `state_changed`; NULL =
  Serveraktion, Aktionszeit = `created_at` = Eingang), `applied` (0 = nicht übernommen, nur
  Historie; dann nur `to_*`, nie `retired`) und `not_applied_reason` (`older`, `retired`,
  `location_locked`; gesetzt genau bei `applied = 0`). Bestehende Einträge bleiben unverändert.
- Was nur der Code sichert (ohne Trigger nicht möglich): Standort nur leer sperren, gesperrter Standort
  und deaktivierter Gerätetyp sind kein Ziel, Ereignis passend zur Änderung am Gerät, Versionsprüfung.

### Gerätedaten zurücksetzen (`bin/tenant.php purge-device-data`)
- Bewusste, einzige Ausnahme von "Geräte werden nie gelöscht" und "Historie nur `INSERT`": nur für
  Städte ohne echte Daten (z. B. Testdaten vor der Erstbefüllung). **Agenten führen den Befehl NIE
  aus** (auch nicht lokal gegen `config.local.php`); nur der Nutzer, nur von Hand.
- Code: `src/Device/TenantDeviceDataPurge.php` (`count`, `purge`; eigene Klasse, kein Repository
  bekommt Lösch-Methoden), `DeviceDataCounts`, Befehl in `TenantCommand`. Sicherung über
  `src/Backup/DatabaseBackup` (derselbe Ablauf wie `bin/db-dump.php`, das ihn ebenfalls nutzt).
- Ohne `--execute`: Probelauf (Anzahl Geräte, Ereignisse, Standorte, Buchungen, Hinweise), ändert
  nichts, keine Sicherung.
  `--execute` nur mit `--confirm` exakt gleich `--slug` (sonst Exit-Code 2); `--confirm` ohne
  `--execute` oder bei anderen Befehlen ebenso. `service` ist ungültig (`TenantSlug`), unbekannte
  Stadt Exit-Code 1, gesperrte Stadt erlaubt.
- Mit `--execute`: nichts vorhanden → Hinweis, keine Sicherung, Exit-Code 0. Sonst zuerst Sicherung
  aller `fe_*` mit Zusatz `vor-purge-<kürzel>` (`TenantCommand::PURGE_BACKUP_SUFFIX_PREFIX`); Fehler
  (auch keine Tabellen) → Abbruch ohne Löschen, Exit-Code 1. Dann in EINER Transaktion (eine offene
  ist ein Fehler): Standorte der Stadt `FOR UPDATE`, `DELETE` von `fe_device_notice_actions`,
  `fe_device_notices`, `fe_device_bookings`, `fe_device_events`, `fe_devices`, `fe_locations` Ebene 2,
  dann Ebene 1, jeweils `WHERE tenant_id = ?`. Gerätetypen, Benutzer,
  Einladungscodes, Sitzungen und die Stadt bleiben. Ausgabe: gelöschte Anzahlen und Pfad der Sicherung.
- Tests (`tests/Integration/Tenant/TenantPurgeCommandTest`) übergeben eine Fake-Sicherung per
  Konstruktor (`backup`) bzw. einen temporären Ordner (`backupDir`); sie vergleichen alle Zeilen
  aller Tabellen `fe_*` vorher und nachher.

### Testdaten der Test-Stadt (`bin/seed-test-city.php`)
- `php bin/seed-test-city.php --slug=test [--devices=ANZAHL] [--execute] [--config=PFAD]` (Code
  `src/Cli/SeedTestCityCommand.php`, `src/Device/Seed/`: `TestCityData` Datenplan ohne Zufall,
  `TestCitySeeder` schreibt, `SeedDevice`, `SeedStep`, `TestCitySeedResult`,
  `TestCitySeedException`). Exit-Codes 0/1/2. README "Testdaten für die Test-Stadt".
- AUSSCHLIESSLICH das Kürzel `TestCityData::SLUG` = `test`: exakter Vergleich in `parseArguments`,
  vor dem Laden der Konfiguration (jedes andere Kürzel → Exit-Code 2). Fest im Code; es darf keine
  Option, Konfiguration oder Liste geben, die weitere Städte zulässt.
- Nur in eine Stadt ohne Geräte und Standorte (sonst Exit-Code 1 mit Hinweis auf
  `purge-device-data`); braucht einen aktiven, nicht gesperrten Admin (legt an mit Kommentar
  `Testdaten`, sondert aus); der Verlauf (`changeState`) reihum von allen aktiven, nicht gesperrten
  Konten. Ohne `--execute` Probelauf. Schreibt NUR über die Repositorys (Ereignisse, Versionen,
  Sperren wie im Betrieb), nie per SQL; keine Mails; Zeitpunkte = Zeit des Aufrufs (die Historie
  wird nicht zurückdatiert, sie ist nur `INSERT`).
- Inhalt: 15 Standorte, 60 ausgesuchte Geräte (`TestCityData::devices`) mit Scan-Codes (GS1 wie auf
  echten Etiketten über `TestCityData::gs1`: `01<GTIN>21<Serie>` ohne GS nach der GTIN fester Länge,
  einige `01<GTIN>10<Charge><GS>21<Serie>` mit GS nach der variablen Charge; `TestCityData::GTIN` mit
  gültiger Prüfziffer, per Test), Prüfdaten relativ zu heute (`Clock`, Europe/Berlin), Geräten auf dem ganzen Fahrzeug,
  Verlauf an 7 Geräten, M24 ausgesondert; `--devices=1…5000`: Geräte T0001 …, Verlauf an
  T0001–T0006, T0007 ausgesondert. Gerätetypen aus den Start-Gerätetypen (fehlt einer oder ist er
  deaktiviert → Abbruch).
- Agenten führen den Befehl nur in Tests gegen die Testdatenbank aus
  (`tests/Integration/Device/SeedTestCityCommandTest`, `FixedClock`; Unit-Tests
  `tests/Unit/Device/Seed/TestCityDataTest`, `tests/Unit/Cli/SeedTestCityScriptTest`), nie gegen
  `config.local.php`; live führt ihn nur der Nutzer aus.

## Gerätetypen und Standorte (API)
- Nur unter Städten (unter `service` gibt es die Routen nicht, 404). Lesen: `Access::Authenticated`
  (`user` und `admin` der Stadt), Ändern: `Access::Admin`. Code in `src/Device/` (`DeviceTypeName`
  höchstens 50 Zeichen, `LocationName` höchstens 100, beide über `DeviceNameRules` wie `TenantName`;
  `LocationKind` mit `label()`; `DeviceType`, `Location`; `DeviceTypeRepository`,
  `LocationRepository`; Fehler erben von `DeviceException`), Endpunkte in `src/Api/V1/DeviceTypes/`
  und `src/Api/V1/Locations/` (je List- und Action-Handler mit Enum, Json-Klasse), Fehlerumsetzung
  `src/Api/V1/DeviceErrors.php`: ungültiger Wert 400, fremde/unbekannte ID im Pfad 404 `not_found`,
  Name vergeben 409 `name_taken`, Regelverstoß 409 `rule_violation`. Felder werden vor der Suche
  gelesen (400 vor 404). Antworten tragen zu festen Werten deutsche Bezeichnungen (`kind_label`).
- Gerätetypen: `GET device-types[?include_inactive=1]` (nach Name, `device_count` = nicht
  ausgesonderte Geräte; deaktivierte nur mit Parameter), `POST device-types {name}` → 201,
  `POST device-types/{id}/rename {name}`, `POST device-types/{id}/deactivate` bzw. `activate {}`
  (wiederholbar). Antwort `{"device_type": …}`. Deaktiviert = kein Ziel für neue Geräte oder einen
  Typwechsel (prüfen Geräte-API und Import); bestehende Geräte behalten ihn.
- Standorte: `GET locations[?include_inactive=1]` → `{"locations": [...]}` als Baum (Ebene 1 nach
  Name, je `children` nach Name; ohne Parameter ohne gesperrte Standorte und gesperrte
  Unterstandorte), `GET locations/{id}` (Ebene 1 mit allen Unterstandorten, auch gesperrten),
  `POST locations {name, kind?, parent_id?}` → 201, `POST locations/{id}/rename {name}`,
  `POST locations/{id}/lock` bzw. `unlock {}` (wiederholbar). Antwort `{"location": …}` mit `id`,
  `name`, `kind`, `kind_label`, `level`, `parent_id`, `active`, `device_count` (nicht ausgesonderte
  Geräte direkt am Standort), nur bei Ebene 1 zusätzlich `device_count_total` (einschließlich aller
  Unterstandorte, auch nicht aufgeführter) und `children`.
- Anlegen: Ebene 1 braucht `kind` (`vehicle`, `room`, `external`, sonst 400). Mit `parent_id`
  (echte JSON-Ganzzahl): unbekannter oder fremder übergeordneter Standort → 400 (Feld im Body, nicht
  im Pfad); übergeordneter ist selbst Unterstandort → 409 (zwei Ebenen); gesperrt → 409; `kind`
  weglassen (übernimmt die Art) oder dieselbe, sonst 409. Namen eindeutig je Stadt und
  übergeordnetem Standort (Ebene 1 untereinander, Unterstandorte je übergeordnetem Standort; auch
  gegenüber gesperrten; ohne Groß-/Kleinschreibung, "Straße" = "Strasse") → sonst 409 `name_taken`.
  Ob ein Name vergeben ist, entscheidet der UNIQUE-Index (Fehler 1062), nicht eine Vorabfrage.
- Sperren nur leer: keine nicht ausgesonderten Geräte direkt am Standort und keine aktiven
  Unterstandorte, sonst 409 `rule_violation` mit Anzahl in der Meldung und
  `details: {"devices": n, "sublocations": m}`. Entsperren: Unterstandort nur unter aktivem
  übergeordnetem Standort (sonst 409); Entsperren der Ebene 1 lässt die Unterstandorte gesperrt.
  Damit gibt es nie einen aktiven Unterstandort unter einem gesperrten Standort.
- Nicht in Version 1: Standort verschieben, Art ändern, Ebene ändern (`LocationRepository` hat
  bewusst keine Methoden dafür; `parent_id` gilt daher als unveränderlich).
- Sperren in der Datenbank (MariaDB 10.11 kennt kein `FOR SHARE`, immer `LOCK IN SHARE MODE`):
  `create` (mit `parent_id`), `lock` und `unlock` öffnen eine eigene Transaktion (eine bereits offene
  ist ein Fehler). Wer einen Standort als übergeordneten Standort oder als Ziel benutzt, liest ihn
  mit `LOCK IN SHARE MODE` und prüft dann `active`; `lock` sperrt ihn mit `FOR UPDATE` und zählt erst
  danach (erste nicht sperrende Abfrage der Transaktion, sieht also den Stand nach der Sperre).
  Reihenfolge immer übergeordneter vor untergeordnetem Standort. Regel für die Geräte-API:
  Anlegen, Umbuchen und Wiederaufnehmen sowie der CSV-Import (per Test mit zweiter Verbindung
  gesichert: `DeviceLocationLockingTest`, `DeviceImportLockingTest`) lesen den Ziel-Standort (und den
  Gerätetyp) mit `LOCK IN SHARE MODE` und lehnen gesperrte bzw. deaktivierte ab, bevor das Gerät
  geschrieben wird. Aussondern liest keinen Standort (es leert ihn nur).

## Geräte (API)
- Nur unter Städten (unter `service` 404). Lesen `Access::Authenticated`, Ändern `Access::Admin`
  (Routen in `AdminRoutesAccessTest` bzw. `TenantReadRoutesAccessTest`); einzige Ausnahme:
  `POST devices/{id}/state` ist `Access::Authenticated` (`user` bucht um und setzt den Status;
  Rechte in `DeviceStateTest::testOnlyAccountsOfOwnTenant`). Code in `src/Device/`
  (`DeviceIdentifier`, `ScanCode`, `DeviceStatus` mit `label()`, `DeviceDate`, `DeviceText`, `DeviceField` mit
  `label()`, `Device`, `NewDevice`, `DeviceChanges`, `DeviceStateChange`, `DeviceReinstatement`,
  `DeviceFilter`, `DeviceSort`, `DevicePage`, `DeviceRepository`, `DeviceEventWriter`; Historie
  lesen: `DeviceEventRepository` (nur lesend), `DeviceEvent`, `DeviceEventType`,
  `DeviceEventLocation`, `DeviceEventPage`; Fehler `DeviceNotFoundException`,
  `DuplicateDeviceIdentifierException`, `DuplicateDeviceScanCodeException`,
  `DeviceVersionConflictException`, dazu
  `DeviceRuleViolationException`), Endpunkte in `src/Api/V1/Devices/` (`DeviceListHandler`,
  `DeviceActionHandler` mit `DeviceAction`, `DeviceFields` liest die Bodys, `DeviceJson`,
  `DeviceEventListHandler`, `DeviceEventJson`), Fehlerumsetzung in `DeviceErrors`. Tests in
  `tests/Integration/Device/` auf Basis von `DeviceApiTestCase` (Scan-Code: `DeviceScanCodeTest`,
  `DeviceScanCodeSchemaTest`).
- Fehler 1062 beim Schreiben eines Geräts: `DeviceRepository::duplicateIndex` unterscheidet am Namen
  des Index in der Meldung (`uq_fe_devices_tenant_scan_code` → Scan-Code, sonst Kennung). Verletzt
  eine Änderung beide Indizes, meldet MariaDB nur den ersten.
- `GET devices`: Parameter `device_type_id`, `location_id`, `include_sublocations=1` (nur mit
  `location_id`, sonst 400), `status` (kommagetrennt; nennt er `retired`, erscheinen ausgesonderte),
  `include_retired=1` (ohne `status`: alle Status), `q` (`LIKE` auf Kennung, Scan-Code (mit
  `COLLATE utf8mb4_unicode_ci`, also wie die anderen ohne Groß-/Kleinschreibung), Seriennummer,
  Hersteller, Modell; `%`, `_`, `\` maskiert; höchstens 100 Zeichen), `scan_code` (exakt
  `d.scan_code = ?`, Normalisierung wie `ScanCode`, leer oder ungültig 400; kombinierbar; ausgesonderte
  nur über `status`/`include_retired`), `sort` (`DeviceSort`:
  `identifier` Standard, `device_type`, `location`, `status`, `next_inspection_due`), `dir`
  (`SortDirection`: `asc` Standard, `desc`; beide nur exakt kleingeschrieben, sonst 400; gelesen von
  `DeviceListHandler::sort`), `limit` 1–200 (Standard 50), `offset` 0–1000000. Unbekannte oder fremde IDs im Filter → leere Liste. Antwort `{devices, total, limit,
  offset}`; je Gerät (`DeviceJson::listItem`) `id`, `identifier`, `scan_code`, `device_type {id, name}`,
  `location {id, name, path}` (Pfad „HLF 20 / G1“), `status`, `status_label`, `next_inspection_due`,
  `version` (= `DeviceJson::summary`, auch in `inspection-due`) sowie `serial_number`, `manufacturer`,
  `model` (für die lokale Suche der App; KONZEPT.md "User am Handy – Entscheidungen", 20) und
  `state_changed_at` (UTC `…Z`, Aktionszeit der letzten Änderung von Standort oder Status =
  `fe_devices.state_changed_at`; für „Zuletzt benutzt“ der App; Stammdaten und Prüfdatum ändern
  sie nicht). Antworten mit allen Feldern (`DeviceJson::device`) enthalten es ebenso.
- Sortierung (`DeviceRepository::orderBy`, nur feste Ausdrücke): Kennung `NATURAL_SORT_KEY(identifier)`,
  Gerätetyp `NATURAL_SORT_KEY(t.name)`, Standort `NATURAL_SORT_KEY` des angezeigten Pfads (derselbe
  Ausdruck wie beim Import), Status fachlich per `FIELD` (ready, maintenance, defective, retired),
  Prüfdatum mit `next_inspection_due IS NULL` zuerst (immer aufsteigend: ohne Datum in BEIDEN
  Richtungen am Ende). `NATURAL_SORT_KEY` behält die Kollation (`utf8mb4_unicode_ci`, also ohne
  Groß-/Kleinschreibung; per Test gesichert). Bei Gleichstand immer `NATURAL_SORT_KEY(identifier)`,
  dann `id`, beide aufsteigend (stabile Seiten). `NATURAL_SORT_KEY` gibt es ab MariaDB 10.7 (lokal
  und STRATO 10.11); ohne Index (für die Mengen einer Feuerwehr genügt das). Ein Index wäre später
  über eine generierte Spalte per Migration möglich.
- `GET devices/{id}`: alle Felder (zusätzlich `serial_number`, `manufacturer`, `model`,
  `procured_on`, `note`, `created_at`, `updated_at`), auch ausgesonderte. Antworten mit einem Gerät
  immer `{"device": …}` mit allen Feldern.
- `POST devices {identifier, scan_code?, device_type_id, location_id, status?, serial_number?,
  manufacturer?, model?, procured_on?, next_inspection_due?, note?, comment?}` → 201. Status Standard
  `ready`, `retired` → 400. Typ und Standort: unbekannt oder fremd → 400, deaktiviert bzw. gesperrt →
  409 `rule_violation`. Kennung vergeben (UNIQUE-Index, Fehler 1062) → 409 `identifier_taken`,
  Scan-Code vergeben → 409 `scan_code_taken`. Schreibt
  nur das Ereignis `created` (ein Prüfdatum steht nur am Gerät).
- `POST devices/{id}/fields {version, comment?, identifier?, scan_code?, device_type_id?,
  serial_number?, manufacturer?, model?, procured_on?, note?}` (`scan_code_taken` wie beim Anlegen): nur mitgesendete Felder (`null` leert ein optionales
  Feld; Kennung und Typ nicht leerbar); ohne Stammdaten 400. Je Feld mit binär anderem Wert ein
  `field_changed` (gemeinsamer Kommentar), Version einmal + 1. Ohne Unterschied: 200 ohne Ereignis
  und ohne neue Version.
- `POST devices/{id}/inspection-due {version, next_inspection_due, comment?}` (`null` = entfernen):
  `due_changed`, Version + 1; gleiches Datum wie oben ohne Änderung.
- `POST devices/{id}/state {version, location_id?, status?, comment?, client_ref?, occurred_at?,
  confirmed_stale?}` (angemeldet, `DeviceStateHandler`): mindestens eines von Standort und Status
  (sonst 400); `status` nur `ready`, `maintenance`, `defective` (`retired` → 400, dafür
  `retire`). Gleicher Standort bzw. Status wird ignoriert; ändert sich insgesamt nichts, 200 ohne
  Ereignis und ohne neue Version. Ziel-Standort nur bei einem Wechsel gelesen: unbekannt oder fremd
  → 400, gesperrt → 409. Ausgesondert → 409. Sonst EIN `state_changed` mit den Paaren, die sich
  ändern, Version + 1. Antwort `{"device": …, "result": …}`; Zusatzfelder und Offline-Regeln siehe
  "Offline-Buchungen (API)".
- `POST devices/{id}/retire {version, comment?}` (Admin): bereits ausgesondert → 409; sonst Status
  `retired`, `state_changed` (nur Status), Version + 1. Standort und Prüfdatum bleiben gespeichert,
  Kennung und Scan-Code werden frei (`identifier_key`, `scan_code_key` `NULL`).
- `POST devices/{id}/reinstate {version, status, location_id?, identifier?, scan_code?,
  device_type_id?, comment?}` (Admin): nur ausgesonderte (sonst 409); `status` Pflicht, nicht `retired`. Standort:
  angegeben oder der bisherige, `LOCK IN SHARE MODE`, gesperrt → 409 (beim bisherigen mit Hinweis auf
  `location_id`), unbekannt/fremd → 400. Gerätetyp: angegeben oder der bisherige, ebenso gelesen,
  deaktiviert → 409 (beim bisherigen mit Hinweis auf `device_type_id`). `identifier` (nicht leerbar)
  und `device_type_id` ändern Stammdaten nur hier ausnahmsweise bei einem ausgesonderten Gerät;
  gleiche Werte werden ignoriert. Je geändertem Feld ein `field_changed`, danach ein `state_changed`
  (gemeinsamer Kommentar, eine Transaktion), Version einmal + 1. Kennung inzwischen vergeben
  (UNIQUE-Index, Fehler 1062) → 409 `identifier_taken` mit Hinweis, eine neue Kennung anzugeben.
  Ebenso der Scan-Code: `scan_code` angegeben (auch `null` = entfernen, `DeviceReinstatement::
  $hasScanCode`) ersetzt den bisherigen (`field_changed`); sonst bleibt er, und ist er inzwischen
  vergeben → 409 `scan_code_taken` mit Hinweis, einen neuen anzugeben oder ihn mit `null` zu entfernen.
- Sammelaktionen (Admin, `DeviceBulkHandler` mit `DeviceBulkAction`, in `AdminRoutesAccessTest`;
  KONZEPT.md Entscheidung 31): `POST devices/bulk/inspection-due {devices: [{id, version,
  next_inspection_due}], comment?}` und `POST devices/bulk/state {devices: [{id, version,
  location_id?, status?, client_ref?}], comment?}` (ohne `occurred_at`/`confirmed_stale`: nur online).
  Exakte Pfade, Vorrang vor `devices/{id}`. 1 bis `DeviceBulkHandler::MAX_DEVICES` (50) Einträge
  (bei 16 KiB Body); Felder je Eintrag wie bei der einzelnen Route (`DeviceFields::bulkEntries`,
  `bulkInspectionDue`, `bulkStateChange`, `deviceId`; jeder Eintrag als eigener `RequestContext`),
  unbekannte Felder 400. ALLE Einträge werden vor der ersten Änderung gelesen: ungültig → 400 mit
  „Eintrag N in "devices": …“, Gerät doppelt → 400. Dann je Gerät eine eigene Transaktion
  (`setInspectionDue` bzw. über `DeviceBooker` wie `POST devices/{id}/state` ohne Aktionszeit, mit
  Wiederholung über `client_ref`), eigener Eintrag im Verlauf, gemeinsamer Kommentar; Teilerfolg
  erlaubt. Antwort immer 200 `{results: [{id, outcome (applied|unchanged|error), replayed, device,
  error}], summary {total, applied, unchanged, errors}}` in der Reihenfolge der Anfrage; `device` =
  Stand danach bzw. bei Fehler der aktuelle (`null` bei fremder/unbekannter ID), `error` = `{error,
  message, details?}` wie die Fehlerantwort der einzelnen Route (`DeviceErrors::http`). Danach EINE
  Sammelmail „Log“ (siehe "Benachrichtigung „Log“"). Tests: `tests/Integration/Device/DeviceBulkTest`.
- `GET devices/{id}/events` (angemeldet, auch ausgesonderte; fremd/unbekannt 404): neueste zuerst
  (nach `id`), `limit` 1–200 (Standard 50), `offset` 0–1000000; Antwort `{events, total, limit,
  offset}`. Je Eintrag immer `id`, `type`, `type_label`, `created_at`, `user {id, name}`
  (Anzeigename, sonst Name; anonymisiert „Gelöschter Benutzer #ID“; nie die E-Mail), `comment`,
  `location {from, to}` (je `{id, name, path}` oder `null`), `status {from, from_label, to,
  to_label}`, `inspection_due {from, to}`, `field {name, label, old, new}`; was die Art nicht kennt,
  ist `null`. Dazu `occurred_at` (Aktionszeit, sonst wie `created_at`), `received_at` (=
  `created_at`, Eingang), `applied`, `not_applied_reason`/`not_applied_label` und `client_ref` (aus
  der Quittung per LEFT JOIN). Reihenfolge bleibt nach Eingang (`id`); nicht übernommene Einträge
  heißen „Umbuchung nicht übernommen“, „Statusänderung nicht übernommen“ bzw. „Umbuchung und
  Statusänderung nicht übernommen“. `type_label` bei `state_changed` nach Inhalt: „Ausgesondert“, „Wieder aufgenommen“,
  „Umgebucht“, „Status geändert“, „Umgebucht und Status geändert“; sonst „Angelegt“, „Importiert“,
  „Prüfdatum geändert“, „Stammdaten geändert“. Standorte erscheinen mit ihrem aktuellen Namen und
  Pfad (die Historie speichert nur die ID; nach dem Umbenennen steht dort der neue Name; bewusst so
  in Version 1, Namen zum Zeitpunkt bräuchten eine Migration).
- `GET device-events` (Admin, `DeviceHistoryListHandler`, in `AdminRoutesAccessTest`; „Letzte
  Aktivitäten“ der Admin-Konsole): Historie der ganzen Stadt, alle Geräte (auch ausgesonderte),
  neueste zuerst nach `created_at`, dann `id` (wie der Export mit `order=newest`, Index
  `idx_fe_device_events_tenant`; `DeviceEventRepository::listForTenant`, `DeviceHistoryPage`),
  `limit` 1–200 (Standard 50), `offset` 0–1000000; Antwort `{events, total, limit, offset}`. Je
  Eintrag dieselben Felder wie bei `devices/{id}/events`, dazu `device {id, identifier, device_type
  {id, name}}` (aktueller Stand des Geräts, `DeviceHistoryEntry`). Keine Filter (dafür der Export).
  Tests: `DeviceHistoryListTest`.
- Unbekannte Felder im Body der ändernden Routen → 400 (z. B. `status` oder `location_id` bei
  `fields`, damit nichts still ignoriert wird). Felder werden vor der Suche gelesen (400 vor 404).
- Reihenfolge der Prüfung beim Ändern: Gerät `FOR UPDATE` (unbekannt/fremd 404) → Version
  (`DeviceRepository::lockForChange`; veraltet: 409 `version_conflict`, `details: {"device": …}` mit
  dem aktuellen Stand, nichts geschrieben; auch wenn sich nichts ändern würde) → ausgesondert (409
  `rule_violation`; bei `reinstate` umgekehrt: nicht ausgesondert) → ggf. Ziel-Standort → ggf.
  Gerätetyp, beide `LOCK IN SHARE MODE` und aktiv (bei `fields` darf ein schon zugeordneter
  deaktivierter Typ bleiben).
- Texte (`DeviceText`): getrimmt, leer → `NULL`, Seriennummer/Hersteller/Modell höchstens 100,
  Notiz 1000 (Zeilenumbrüche erlaubt, als LF gespeichert), Kommentar 500 (einzeilig). Keine
  Steuerzeichen. Datum (`DeviceDate`): genau `JJJJ-MM-TT`, gültiger Kalendertag, 1950-01-01 bis
  2099-12-31.
- Kennung (`DeviceIdentifier`): ohne intl werden nur zerlegte Umlaute zusammengesetzt; andere
  kombinierende Zeichen (`\p{M}`) werden mit Meldung abgelehnt.

## Offline-Buchungen (API)
- Nur `POST devices/{id}/state` (Umbuchen/Status, `Access::Authenticated`); alle anderen Routen
  lehnen die Felder als unbekannt ab (400). Code: `src/Api/V1/Devices/DeviceStateHandler.php` (eigener
  Handler mit `Clock` und `OfflineSettings` aus `Routes::tenant`), `DeviceBookingJson` (Feld
  `result`), `src/Device/Booking/` (`ClientRef`, `BookingRequest` mit `hash()`, `BookingOutcome`,
  `BookingReason`, `BookingResult`, `StoredBooking`, `DeviceStateSnapshot`,
  `DeviceBookingRepository`, `DeviceBooker` (Wiederholung über die `client_ref` und gleichzeitig
  vergebene `client_ref`, auch für `POST devices/bulk/state`), `ClientRefConflictException`,
  `DuplicateClientRefException`),
  `src/Offline/` (`ActionTime`, `OfflineSettings`), `DeviceRepository::book` (`changeState` ruft es
  ohne Zusatzangaben). Tests: `tests/Integration/Device/DeviceBookingClientRefTest`,
  `DeviceOfflineBookingTest`, `DeviceBookingSchemaTest`, `DeviceStateColumnsTest`,
  `DeviceStateColumnsMigrationTest`, Trait `OfflineBookings` (feste Uhr auf der Zeit der Datenbank).
- Felder (optional): `client_ref` (UUID v4, Groß-/Kleinschreibung egal, gespeichert klein),
  `occurred_at` (Aktionszeit auf dem Handy, genau `JJJJ-MM-TTTHH:MM:SS[.ffffff]Z`, ab 2000, auf Sekunden
  gekürzt; nur mit `client_ref`, sonst 400), `confirmed_stale` (nur echte JSON-Werte, nur mit
  `occurred_at`, sonst 400). Ohne `occurred_at` gilt die Serverzeit und alles wie bisher.
- Reihenfolge im Handler: Felder (400) → Wiederholung → Aktionszeit → `DeviceRepository::book`.
  - Wiederholung (`fe_device_bookings`, Migration `0026`): `client_ref` je Stadt eindeutig. Gibt es sie,
    und passen Konto, Gerät und `request_hash` (SHA-256 über Gerät, Version, Standort, Status,
    Kommentar, Aktionszeit; `confirmed_stale` zählt nicht), wird das gespeicherte Ergebnis mit
    `replayed: true` und dem aktuellen Gerätestand wiederholt (nichts geschrieben, keine Mail, auch
    nach den Zeitgrenzen); sonst 409 `client_ref_conflict`. Die Quittung entsteht in derselben
    Transaktion wie die Buchung; gleichzeitig dieselbe `client_ref` → Fehler 1062 auf
    `uq_fe_device_bookings_tenant_ref` → Rollback (`DuplicateClientRefException`) → Wiederholung.
    Abgelehnte Buchungen (Fehler) speichern keine Quittung.
  - Aktionszeit: mehr als `OfflineSettings::MAX_CLOCK_SKEW_SECONDS` (300) nach der Serverzeit → 422
    `clock_implausible` (`details {server_time, occurred_at, max_skew_seconds}`); älter als
    `offline.queue_max_age_days` ohne `confirmed_stale` → 409 `stale_action` (`details {occurred_at,
    queue_max_age_days, server_time}`). Genau auf der Grenze ist erlaubt (per Test). Mit
    `confirmed_stale` verarbeitet, `stale_confirmed` im Ergebnis und Hinweis.
- Gemeinsamer Stand (Migrationen `0023`/`0024`): `fe_devices.state_changed_at` = Aktionszeit der
  Änderung, die Standort und Status bestimmt; `state_version` = `version` nach der letzten Änderung
  von Standort oder Status (CHECK `1 ≤ state_version ≤ version`). Startwerte: `created_at` des neuesten
  Ereignisses `created`/`imported`/`state_changed` (nach `id`), sonst `fe_devices.created_at`;
  `state_version` = `version`. Alle Schreibwege (Anlegen, Import, Umbuchen/Status, Aussondern,
  Wiederaufnehmen) setzen beide (Serverzeit bzw. Aktionszeit; `state_version = version + 1` in der
  SET-Liste VOR `version = version + 1`); Stammdaten und Prüfdatum ändern sie nicht.
- Regeln mit `occurred_at` (`DeviceRepository::offline`), Gerät `FOR UPDATE`, keine Versionsprüfung:
  ausgesondert → `not_applied`/`retired`. Konflikt = Version veraltet UND der Stand hat sich seit der
  Version des Handys geändert (nicht `erwartet < version` mit `state_version ≤ erwartet`); bei
  Konflikt und Aktionszeit ≤ `state_changed_at` → `history_only`/`older`. Gleicher Standort und
  Status → `unchanged`. Neuer Standort `LOCK IN SHARE MODE`: unbekannt/fremd 400, gesperrt →
  `not_applied`/`location_locked`. Sonst übernehmen (`state_changed_at` = Aktionszeit), bei Konflikt
  `reason = overwritten`. „Nur Historie“ und „nicht übernommen“ schreiben ein `state_changed` mit
  `applied = 0` und nur dem Gewünschten (`to_*`), Gerät und Version bleiben.
- Antwort immer 200 `{"device": …, "result": {outcome, outcome_label, reason, reason_label,
  message, stale_confirmed, client_ref, replayed, notice_id}}`; `message` ist ein deutscher Satz.
- Konfiguration: Gruppe `offline` (`max_age_days`, `queue_max_age_days`, je 1–365, Standard 7;
  `OfflineConfig`, `ConfigLoader::loadOfflineConfig`), geladen erst bei Bedarf
  (`OfflineSettings::fromConfigFile` in `Application::create`; Tests: `OfflineSettings::fixed`).
  `max_age_days` prüft nur das Handy. `GET client-config` (`Access::Authenticated`,
  `ClientConfigHandler`, in `TenantReadRoutesAccessTest`): `{server_time (UTC, …Z), offline
  {max_age_days, queue_max_age_days}, max_clock_skew_seconds}`.

## Hinweise (API)
- Nur Admins der Stadt (`Access::Admin`, in `AdminRoutesAccessTest`): `GET notices[?status=open|
  resolved|all]` (Standard `open`, neueste zuerst, `limit` 1–200/50, `offset`; Antwort `{notices,
  total, open, limit, offset}`), `GET notices/{id}`, `POST notices/{id}/resolve {note?}` (Notiz
  höchstens 500 Zeichen, Zeilenumbrüche erlaubt, `DeviceText::noticeNote`), `POST notices/{id}/reopen
  {}`; Antwort `{"notice": …}`. Beide wiederholbar (Ziel-Status schon erreicht: nichts, kein Eintrag
  im Verlauf). Fremd/unbekannt 404, unbekannte Felder 400 (vor 404). Kein automatisches Erledigen.
  `booking.comment` = Kommentar der Buchung (aus `fe_device_events` über `event_id`, sonst `null`).
- Code: `src/Device/Notice/` (`NoticeKind` mit `forResult`, `NoticeStatus`, `NoticeChange`,
  `DeviceNotice`, `NewNotice`, `NoticeUser`, `NoticeHistoryEntry`, `DeviceNoticePage`,
  `DeviceNoticeRepository`, `NoticeNotFoundException`), Endpunkte `src/Api/V1/Notices/`
  (`NoticeListHandler`, `NoticeActionHandler` mit `NoticeAction`, `NoticeJson`). Tests:
  `tests/Integration/Device/DeviceNoticeTest`, `DeviceNoticeSchemaTest`,
  `tests/Integration/Notification/DeviceNoticeNotificationTest`.
- Tabellen: `fe_device_notices` (Migration `0027`; ein Hinweis je Quittung, `booking_id` eindeutig;
  `kind` `overwritten`, `history_only`, `not_applied_retired`, `not_applied_location_locked`,
  `stale_confirmed`; `stale_confirmed` auch bei den anderen Arten; Buchung: Konto, Aktionszeit,
  Wunsch; `other_*`: Stand beim Eingang, bei `overwritten` der überschriebene, mit Aktionszeit und
  Konto des letzten übernommenen `created`/`imported`/`state_changed`; `status` `open`/`resolved`)
  und `fe_device_notice_actions` (Migration `0028`, Verlauf `resolve`/`reopen` mit Konto, Zeit,
  Notiz nur bei `resolve`; nur `INSERT`, Notizen bleiben nach dem Wiederöffnen). Alle Bezüge mit
  Stadt (zusammengesetzte Fremdschlüssel). Angelegt nur in der Transaktion der Buchung, nur bei
  Offline-Buchungen mit Grund oder `stale_confirmed` (`NoticeKind::forResult`).
- Ausnahme zu „nie die E-Mail“: Hinweise zeigen Anzeigename UND E-Mail der beteiligten Konten (nur
  Admins sehen sie); anonymisiert „Gelöschter Benutzer #ID“ ohne E-Mail.
- `GET device-summary`: nur für Admins zusätzlich `notices {open}` (zweite Abfrage); bei `user` fehlt
  das Feld.
- Mail (`DeviceLogNotifier::notice`, Vorlage `MailTemplates::deviceNotice`, Zweck `device_notice`): an
  die Empfänger der Benachrichtigung „Log“, nie an den Auslöser, nach dem Commit, statt der Mail zum
  Vorgang (eine Mail je Anfrage). Betreff „Log <Stadt>: Hinweis – <Kennung> <Grund>“
  (`NoticeKind::subjectReason`). Text: Art, Gerät, Buchung (Wer mit E-Mail in Klammern, Aktionszeit,
  Eingang, Wunsch, Kommentar aus dem Ereignis), anderer Stand, ggf. „alte Buchung nach Rückfrage“.
  Link für Admins auf den Hinweis (`AppUrls::notice`, `protokoll/<id>`); Super-Admins ohne Link,
  stattdessen „Den Hinweis prüfen und erledigen die Admins der Stadt in der Anwendung.“

## Prüffristen und Zusammenfassung (API)
- Nur unter Städten (unter `service` 404), beide `Access::Authenticated` (in
  `TenantReadRoutesAccessTest`). Ausgesonderte Geräte sind nie enthalten, auch nicht über Parameter
  der Geräteliste (`status`, `include_retired`, `q` und `sort` werden wie alle unbekannten
  Query-Parameter ignoriert). Code: `src/Time/` (`Clock`, `SystemClock`, `LocalDate`),
  `src/Device/` (`InspectionWindow`, `InspectionSection`, `DeviceSummary`,
  `DeviceRepository::listInspections` und `::summary`), Endpunkte
  `src/Api/V1/Inspections/InspectionDueHandler.php` und `src/Api/V1/Devices/DeviceSummaryHandler.php`.
  Tests in `tests/Integration/Inspection/` (`InspectionApiTestCase` mit `FixedClock`).
- „Heute“ ist der Kalendertag in Europe/Berlin (`LocalDate::today`), nie UTC und nie `CURDATE()` oder
  `UTC_DATE()` in SQL (die Sitzung läuft in UTC): Der Code berechnet heute und das Ende des Fensters
  (`InspectionWindow`) und übergibt beides als Parameter. Tage werden ohne Zeitzone gezählt
  (`LocalDate::daysBetween`), die Zeitumstellung verschiebt nichts.
- Uhr: Handler erhalten `Clock` per Konstruktor über `Routes::tenant(?MinimumDuration, ?Clock)`
  (null = `SystemClock`); andere Uhren nur in Tests (`tests/Support/FixedClock`). Keine
  Einstellung in der Konfigurationsdatei.
- `GET inspection-due`: Parameter `days` 1–365 (Standard 30, `InspectionWindow::DEFAULT_DAYS`),
  `include_without_date=1`, `device_type_id`, `location_id`, `include_sublocations=1` (wie
  `GET devices`, über `DeviceFilter` ohne Status und Suche), `limit` 1–500 je Abschnitt (Standard
  200, kein `offset`). Antwort `{today, days, until, overdue, due_soon[, without_date]}`, je
  Abschnitt `{devices, total}`; `without_date` fehlt ohne Parameter. `overdue`: Prüfdatum < heute,
  je Gerät `days_overdue` (≥ 1). `due_soon`: heute ≤ Prüfdatum ≤ `until` (= heute + `days`), je Gerät
  `days_until_due` (0 = heute). Geräte sonst wie `DeviceJson::summary`. Sortierung nach Prüfdatum
  aufsteigend (am stärksten überfällig zuerst), dann `NATURAL_SORT_KEY(identifier)`, `id`;
  `without_date` nur nach Kennung.
- `GET device-summary` (Startseite, keine Parameter): EINE Abfrage (`GROUP BY status`) ohne
  Geräteliste (für Admins eine zweite für `notices {open}`, siehe "Hinweise (API)"). Antwort `{today, devices {total, by_status [{status, status_label, count}]},
  inspection {overdue, due_soon, due_soon_days, without_date}}`; `by_status` immer `ready`,
  `maintenance`, `defective` in dieser Reihenfolge, auch mit 0. Fenster fest 30 Tage, Grenzen wie bei
  `inspection-due` (per Test gleiche Zahlen).
- Indizes `idx_fe_devices_tenant_due` und `idx_fe_devices_tenant_status` (Migration `0014`) genügen;
  keine Migration.

## CSV-Export (API)
- Nur unter Städten (unter `service` 404), beide `Access::Authenticated` (in
  `TenantReadRoutesAccessTest`): `GET devices/export` und `GET device-events/export`. Code:
  `src/Csv/CsvFormat.php` (Format), `src/Http/CsvResponse.php` (Download),
  `src/Database/UnbufferedQuery.php`, `src/Time/GermanFormat.php`, `LocalDate::startOfDayUtc`,
  `src/Api/V1/Devices/` (`DeviceExportHandler`, `DeviceHistoryExportHandler`, `DeviceCsv` mit
  Spalten), `DeviceRepository::export`, `DeviceEventRepository::countHistory`/`exportHistory`
  (`DeviceHistoryFilter`, `DeviceHistoryEntry`). Tests in `tests/Integration/Export/`
  (`ExportApiTestCase` mit `FixedClock`).
- Format (`CsvFormat`; der Import liest es, siehe "CSV-Import (API)"): UTF-8 MIT BOM (Excel unter
  Windows),
  Semikolon, Zeilenende CRLF, Felder nach RFC 4180 nur bei Bedarf in Anführungszeichen (enthält
  `;`, `"`, CR oder LF; `"` → `""`); Zeilenumbrüche in Feldern (Notizen) bleiben LF. Deutsche
  Überschriften und Bezeichnungen (`label()`), Datum `TT.MM.JJJJ`, Zeitpunkte in Europe/Berlin als
  `TT.MM.JJJJ HH:MM` (`GermanFormat`), leere Werte als leeres Feld.
- Formelschutz: Werte, die mit `=`, `+`, `-`, `@`, Tab, CR oder `'` beginnen, erhalten ein
  vorangestelltes `'` (`CsvFormat::FORMULA_PREFIXES`). Der Import entfernt IMMER genau ein führendes
  `'` (`CsvFormat::removeFormulaGuard`); weil `'` selbst geschützt wird, kommt jeder Wert unverändert
  zurück. Beide Stellen gemeinsam ändern.
- Antwort: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment;
  filename="…"` (nur `a-z0-9.-`, Endung `.csv`), `Cache-Control: no-store`,
  `X-Content-Type-Options: nosniff`. Fehler vor dem Download (400, 401, 409 …) als JSON.
- Streaming: Die Repositorys lesen ungepuffert (`UnbufferedQuery`: `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY`
  aus, danach wiederhergestellt, auch wenn der Generator verworfen wird). Solange gelesen wird, keine
  andere Abfrage über die Verbindung; alles Nötige (Typ, Standortpfad, Benutzer, Gerät) per JOIN.
  `CsvResponse` startet den Generator im Konstruktor (Fehler der Abfrage noch im Handler → normale
  Fehlerantwort), schreibt beim Senden Zeile für Zeile nach `php://output`, leert vorher die
  PHP-Puffer und gibt nach je 500 Zeilen (`FLUSH_ROWS`) weiter. Fehler danach: `csv_export_failed`
  im Protokoll, letzte Zeile `Export abgebrochen – Datei unvollständig` (`CsvFormat::ABORTED_LINE`;
  der Import lehnt solche Dateien ab).
- Geräteliste: Filter wie `GET devices` (`DeviceListHandler::filter`: `device_type_id`, `location_id`,
  `include_sublocations`, `status`, `q`, `include_retired`, `scan_code`), Sortierung ebenso
  (`DeviceListHandler::sort`: `sort`, `dir`, ungültig 400; Standard Kennung aufsteigend), ohne
  Seitenbegrenzung; `limit`, `offset` werden ignoriert. Spalten
  (`DeviceCsv::DEVICE_HEADER`): Kennung, Scan-Code (unverändert, auch mit GS/RS/EOT; Formelschutz
  gilt), Gerätetyp, Standort, Status, Nächste Prüfung, Seriennummer, Hersteller, Modell,
  Beschaffungsdatum, Notiz. Dateiname
  `geraete-<kürzel>-<JJJJ-MM-TT>.csv` (heute in Europe/Berlin über `Clock`).
- Die Geräteliste ist das Format des Imports; Spalten nur gemeinsam mit `ImportColumn` ändern (per
  Test gleich): Kennung ist der Schlüssel, Gerätetyp über den Namen, Standort über den vollständigen
  Pfad „HLF 20 / G1“, Status über die deutsche Bezeichnung, Datum `TT.MM.JJJJ`.
- Historie: alle Ereignisse der Stadt, auch ausgesonderter Geräte. Parameter `from`/`to`
  (`JJJJ-MM-TT`, Kalendertage in Europe/Berlin, beide einschließlich; abgefragt halboffen
  `created_at >= Beginn(from)` und `< Beginn(to + 1)` in UTC über `LocalDate::startOfDayUtc`, Index
  `idx_fe_device_events_tenant`). Standard: die letzten 90 Tage bis heute
  (`DeviceHistoryExportHandler::DEFAULT_DAYS`); nur `to` → 90 Tage bis dahin, nur `from` → bis heute;
  höchstens 366 Tage (`MAX_DAYS`), `from` nach `to` oder mehr Tage → 400. `device_id`;
  `device_type_id` = AKTUELLER Typ des Geräts; `location_id` = Ereignisse, deren Von- ODER
  Nach-Standort dieser Standort ist, mit `include_sublocations=1` auch dessen Unterstandorte
  (Ereignisse ohne Standort wie `due_changed`/`field_changed` fallen dann heraus). Unbekannte oder
  fremde IDs → leere Datei. `order=newest` (Standard) bzw. `oldest`, sortiert nach `created_at`,
  dann `id`. Vor dem Download wird gezählt: mehr als 100.000 Einträge (`MAX_ROWS`; im Konstruktor
  nur für Tests änderbar) → 409 `rule_violation` mit `details: {"events": n, "max": 100000}`.
- Spalten der Historie (`DeviceCsv::EVENT_HEADER`): Aktionszeit, Eingang beim Server, Geräte-Nr. (ID; Kennungen können nach
  dem Aussondern neu vergeben werden), Kennung und Gerätetyp (aktueller Stand), Ereignis
  (`DeviceEvent::label()`), Wer (Anzeigename, anonymisiert „Gelöschter Benutzer #ID“, nie die
  E-Mail), Standort von/nach (aktueller Pfad), Status von/nach, Prüfdatum alt/neu, Feld (deutsch),
  Alter/Neuer Wert (Beschaffungsdatum als `TT.MM.JJJJ`), Kommentar, Übernommen (Ja/Nein), Grund (nicht
  übernommen), Buchungs-Kennung (`client_ref`). Zeitraum und Reihenfolge nach Eingang (`created_at`). Dateiname
  `historie-<kürzel>-<von>-bis-<bis>.csv`.

## CSV-Import (API)
- Nur unter Städten (unter `service` 404), beide `Access::Admin` (in `AdminRoutesAccessTest`):
  `POST devices/import/preview {file}` (prüfen, speichert nichts) und `POST devices/import {file,
  token, comment?}` (übernehmen). `file` = die Datei als Base64 (Whitespace erlaubt); Body-Grenze der
  Routen 3 MiB (`DeviceImportHandler::MAX_BODY_BYTES`), CSRF-Schutz wie überall (JSON, Origin).
  Code: `src/Csv/` (`CsvReader`, `CsvTable`, `CsvRecord`, `CsvException`), `src/Device/Import/`
  (`DeviceImportFile` Datei und Kopfzeile, `ImportRow` Werte je Zeile, `ImportColumn`, `ImportDate`,
  `ImportWarnings`, `DeviceImportPlanner` Abgleich mit der Datenbank, `DeviceImportPlan` mit
  Kennzeichen, `ImportRowPlan`, `ImportChange`, `ImportResult`, `DeviceImport` Vorschau und
  Übernehmen, Fehler `ImportFileException`, `ImportOutdatedException`),
  `DeviceRepository::findByIdentifierKeys` und `::findByScanCodes`, `DeviceEventWriter` (gepuffert,
  `imported`), Endpunkte
  `src/Api/V1/Devices/DeviceImportHandler.php` und `DeviceImportJson.php`. Tests in
  `tests/Integration/Import/` (`ImportApiTestCase`) sowie `tests/Unit/Csv/` und
  `tests/Unit/Device/Import/`.
- Hilfen zum Erfassen (KONZEPT.md Entscheidung 32; `Access::Admin`, CSV wie der Export über
  `CsvResponse`, `DeviceImportTemplateHandler`, Code `DeviceImportTemplate`, Tests
  `DeviceImportTemplateTest`): `GET devices/import/template` → Vorlage `geraete-vorlage-<kürzel>.csv`
  mit den Spalten des Imports (`DeviceImportTemplate::header()` = `DeviceCsv::DEVICE_HEADER`, per Test)
  und zwei Beispielzeilen `BEISPIEL-1`/`BEISPIEL-2` (`EXAMPLE_IDENTIFIERS`; aktive Gerätetypen und
  Standorte der Stadt, erster Standort der Ebene 1 bzw. erstes Fach, Prüfdatum heute + 365 Tage,
  ohne aktive leer); `GET devices/import/values` → `gueltige-werte-<kürzel>-<JJJJ-MM-TT>.csv`
  (`Spalte;Gültiger Wert`: Regel der Kennung, aktive Gerätetypen, aktive Standorte als Pfad, die drei
  Status, Datumsformat). Die Beispielzeilen stehen nur in der Vorlage: Kennungen aus
  `EXAMPLE_IDENTIFIERS` (ohne Groß-/Kleinschreibung, `isExample`) ergeben in der Vorschau bei neuen und
  bestehenden Geräten eine Warnung („… gehört zu den Beispielzeilen der Vorlage …“), keinen Fehler.
- „Meinten Sie …?“ (`ImportSuggestions`, `UnknownImportValue`): zu unbekannten Gerätetypen bzw.
  Standorten die ähnlichsten AKTIVEN Werte (Kandidaten erst gelesen, wenn es unbekannte gibt), in der
  Meldung der Zeile („Der Standort „HLF20“ ist nicht bekannt. Meinten Sie „HLF 20“?“) und im Feld
  `suggestions {device_types, locations}` der Vorschau (je `{value, suggestions}`, nur mit Vorschlag,
  Reihenfolge wie `unknown`). Vergleichsform `ImportSuggestions::key` (ohne Leerraum und Groß-/
  Kleinschreibung, ä/ö/ü/ß = ae/oe/ue/ss), Levenshtein über Unicode-Zeichen bis zu einem Viertel der
  Länge (mindestens 1, höchstens 3), kleinster Abstand gewinnt, höchstens `MAX_SUGGESTIONS` (3)
  natürlich sortiert, höchstens `MAX_VALUES` (100) gesuchte Werte je Art; Fächer auch über ihren Namen
  („G1“ → „HLF 20 / G1“). Nur ein Hinweis: verbindlich bleibt der Vergleich der Datenbank.
- Entscheidungen: Der Import legt neue Geräte an UND ändert bestehende. Schlüssel ist die Kennung,
  ohne Groß-/Kleinschreibung, nur unter nicht ausgesonderten Geräten (wie der UNIQUE-Index; ß ≠ ss).
  Unbekannte Gerätetypen (über den Namen) und Standorte (über den vollständigen Pfad wie im Export)
  sind Fehler; die Vorschau listet sie gesammelt auf (`unknown`). Nichts wird automatisch angelegt.
- Datei (`CsvReader`, `DeviceImportFile`): UTF-8 mit oder ohne BOM; kein gültiges UTF-8 → als
  Windows-1252 umgewandelt (dort nicht belegte Bytes 81/8D/8F/90/9D, NUL-Bytes und UTF-16 → Fehler).
  Trennzeichen `;`, `,` oder Tab, erkannt an der Kopfzeile (die meisten bekannten Überschriften, bei
  Gleichstand `;`). RFC 4180, Zeilenende CRLF/LF/CR, Zeilenumbrüche in Feldern. Zeilennummern wie in
  Excel (Kopfzeile = 1, Datensätze statt physischer Zeilen); völlig leere Zeilen (auch `;;;`) werden
  übergangen. Jede Zelle: genau ein führendes `'` entfernen (`CsvFormat::removeFormulaGuard`).
- Spalten wie im Export (`ImportColumn` = `DeviceCsv::DEVICE_HEADER`), Zuordnung über die Überschrift
  (Reihenfolge und Groß-/Kleinschreibung egal). Fehler der ganzen Datei → 400 `invalid_request`
  (ggf. `details` mit `line`, `columns`, `rows`/`max_rows`, `max_bytes`): unbekannte, doppelte oder
  leere Überschrift, Spalte Kennung fehlt, nicht lesbar (z. B. Anführungszeichen nicht geschlossen),
  Zeile `CsvFormat::ABORTED_LINE` (abgebrochener Export), keine Datenzeile, mehr als 5000 Zeilen
  (`DeviceImportFile::MAX_ROWS`) oder mehr als 2 MiB (`MAX_BYTES`).
- Werte (`ImportRow`): Spalte fehlt = Feld unverändert; Spalte vorhanden und Zelle leer = optionales
  Feld leeren. Gerätetyp, Standort, Status leer = nicht angegeben (bestehendes Gerät: unverändert;
  neues: Typ und Standort Pflicht, Status Standard einsatzbereit). Status über die deutsche
  Bezeichnung ohne Groß-/Kleinschreibung, nur einsatzbereit, in Wartung, defekt; „ausgesondert“ →
  Zeile übersprungen (kein Aussondern oder Wiederaufnehmen per Import; zählt nicht als doppelte
  Kennung). Datum `TT.MM.JJJJ`, `T.M.JJJJ` oder `JJJJ-MM-TT` (`ImportDate`; keine zweistelligen
  Jahre). Texte, Kennung und Scan-Code wie `DeviceText`, `DeviceIdentifier` bzw. `ScanCode`
  (`ImportRow::scanCode()`). Falsche Anzahl Felder → Fehler der Zeile (nie still als leer werten).
- Abgleich (`DeviceImportPlanner`), alle Vergleiche rechnet die Datenbank (wie die UNIQUE-Indizes):
  Schlüssel der Kennung mit der Formel von `identifier_key` über `JSON_TABLE`; Gerätetyp über den Namen
  und Standort über den Pfad (`IF(p.id IS NULL, l.name, CONCAT(p.name, ' / ', l.name))`, nie an
  „ / “ trennen) unter `utf8mb4_unicode_ci` („Straße“ = „Strasse“); mehrere passende Standorte →
  Fehler „nicht eindeutig“. Doppelte Kennung in der Datei → Fehler in allen betroffenen Zeilen.
  Neues bzw. geändertes Ziel: Standort gesperrt bzw. Gerätetyp deaktiviert → Fehler; ein schon
  zugeordneter darf bleiben. Kennung nur in anderer Schreibweise → `field_changed` (binär verglichen).
  Scan-Code exakt (binär, `scan_code_key`): doppelt in der Datei (ohne übersprungene Zeilen) → Fehler
  in allen betroffenen Zeilen; gehört er einem ANDEREN nicht ausgesonderten Gerät → Fehler, auch wenn
  jenes ihn in derselben Datei abgibt (kein Tausch oder Übertragen per Import; sonst scheiterten die
  UPDATEs zwischendurch am UNIQUE-Index). Gleiche Scan-Codes anderer Städte zählen nicht.
- Ergebnis je Zeile: `new`, `changed` (je Feld alt → neu in der Schreibweise der Datei),
  `unchanged`, `skipped`, `error` (verständliche Meldungen; die Zeilennummer steht in `line`).
  Warnungen (`warnings`, blockieren nicht; `ImportWarnings`): eine NEUE Kennung aus Ziffern, die ohne
  führende Nullen einem vorhandenen Gerät entspricht („7“ ↔ „007“); eine Kennung, die wie ein Datum
  aussieht (neu oder geänderte Schreibweise; „01.03.2026“, „1-Mar“, „01. Mrz“ …); ein neuer oder
  geänderter Scan-Code in wissenschaftlicher Schreibweise (`ImportWarnings::looksScientific`,
  „4,00638E+12“) oder aus Ziffern, der ohne führende Nullen einem LÄNGEREN vorhandenen Scan-Code
  (desselben oder eines anderen nicht ausgesonderten Geräts) entspricht („4006381333931“ ↔
  „04006381333931“). Mehr als 15 Ziffern rundet Excel auf Nullen; das ist nicht erkennbar (README).
  Zusammenfassung
  `summary {rows, new, changed, unchanged, skipped, errors, warnings}` (Warnungen = Zeilen mit
  Warnung).
- Kennzeichen (`token`, nur ohne Fehler): SHA-256 über den Hash der Datei und den Plan aller Zeilen
  (Ergebnis, Gerät mit Version, Änderungen samt Typ- und Standort-IDs). Kein Geheimnis nötig, da beim
  Übernehmen alles neu geprüft wird. Übernehmen (`DeviceImport::apply`, eigene Transaktion, eine offene
  ist ein Fehler): erneut prüfen MIT Sperren; Fehler oder anderes Kennzeichen → 409 `import_outdated`
  (`details.summary` der neuen Prüfung; Meldung: Vorschau neu erstellen), nichts geschrieben.
  Fehler 1062 (Kennung oder Scan-Code gleichzeitig vergeben) oder 1213 (Verklemmung) → ebenfalls
  `import_outdated`.
  Alles oder nichts.
- Sperren beim Übernehmen, Reihenfolge Gerät → Standort → Gerätetyp: Geräte `FOR UPDATE` über
  `identifier_key IN (…)` (sperrt auf dem UNIQUE-Index auch die Lücken neuer Kennungen), dann mit
  `LOCK IN SHARE MODE` gelesen (aktueller Stand, unabhängig vom Snapshot); danach ebenso über
  `scan_code_key IN (…)` (Besitzer der Scan-Codes und Lücken neuer); alle genannten Standorte,
  danach alle genannten Gerätetypen `LOCK IN SHARE MODE`, `active` aus diesen Zeilen.
- Schreiben wie bei der Einzelbearbeitung: neues Gerät Version 1 und Ereignis `imported`; bestehendes
  ein `UPDATE` (Version einmal + 1) und Ereignisse `field_changed` (je Feld, Reihenfolge von
  `DeviceField`), `due_changed`, `state_changed` (Standort und Status in EINEM Eintrag), alle mit dem
  gemeinsamen Kommentar. Neue Geräte (500 je INSERT, IDs danach über `identifier_key`) und Ereignisse
  (`DeviceEventWriter` gepuffert, 500 je INSERT) werden gesammelt geschrieben. Unveränderte und
  übersprungene Zeilen schreiben nichts.
- Laufzeit: lokal 5000 Zeilen Vorschau unter 0,5 s, Übernehmen 0,8 s (neu) bis 2 s (geändert),
  Spitzenspeicher rund 45 MiB; Schätzung für STRATO und Messung siehe README ("CSV-Import").

## Benachrichtigung „Log“
- Eigene Einstellung über `GET`/`POST auth/notifications {device_log: bool}` (siehe "Anmeldung und
  Sitzungen im Code", `NotificationSettingsHandler`), auch in `auth/me` (`notifications`). Standard aus.
- Auslöser: jeder Vorgang, der in der Geräte-Historie protokolliert wird (Anlegen, CSV-Import,
  Umbuchen/Status, Aussondern, Wiederaufnehmen, Prüfdatum, Stammdaten). Ohne neues Ereignis (nichts
  geändert, Fehler, Versionskonflikt) keine Mail.
- Code: `src/Notification/DeviceLogNotifier.php` (`deviceChanged`, `bulkChanged`, `imported`;
  `fromContext()`),
  `DeviceEventRecorder` (IDs der Ereignisse der letzten erfolgreichen Transaktion von
  `DeviceRepository`; nach Rollback leer; nur der ungepufferte `DeviceEventWriter` meldet IDs),
  `DeviceEventRepository::findForDevice`, Vorlagen `MailTemplates::deviceLog`, `deviceBulkLog` und
  `deviceImportLog`. Aufruf in `DeviceActionHandler`, `DeviceStateHandler`, `DeviceBulkHandler` und
  `DeviceImportHandler` NACH der Rückkehr des Repositorys (also nach dem Commit). Tests: `tests/Integration/Notification/DeviceLogNotificationTest`.
- Empfänger: `UserRepository::listDeviceLogRecipients` (Admins nur der Stadt des Vorgangs,
  Super-Admins aller Städte; aktiv, nicht gesperrt, Option an), nie der Auslöser. Eine Mail je
  Empfänger und Anfrage; ein CSV-Import ergibt EINE Sammelmail (Zusammenfassung, höchstens
  `MAX_IMPORT_LINES` = 50 Zeilen mit Einzelheiten, danach „… und N weitere“; ohne neue oder geänderte
  Geräte keine Mail), ebenso eine Sammelaktion (`bulkChanged`: alle geänderten Geräte der Anfrage, je
  Gerät „Kennung (Typ)“ und eingerückt Von/Nach; Betreff „12 Geräte – Prüfdatum geändert“ bzw.
  „… – umgebucht, Status defekt“, bei verschiedenen Zielstatus „Status geändert“; nur ein geändertes
  Gerät → die gewöhnliche Mail `deviceChanged`; Link für Admins auf die Geräteliste).
- Betreff „Log <Stadtname>: <Grund>“ (z. B. „MA-01 umgebucht, Status defekt“, „CSV-Import: 2 neu,
  1 geändert“, höchstens 200 Zeichen). Text: Vorgang, Gerät (Kennung, Typ), Wer (Anzeigename), Wann
  (Europe/Berlin), Von/Nach je Ereignis, Kommentar, Link (KONZEPT.md "Admin-Konsole –
  Entscheidungen", 36; `DeviceLogNotifier::linkFor`): für Admins auf das Gerätedetail
  (`AppUrls::deviceDetails`, `geraete/<id>/details`), beim CSV-Import auf die Geräteliste
  (`AppUrls::devices`, `geraete`); Super-Admins sehen keine Geräte und erhalten keinen Link (der
  Absatz entfällt, `MailTemplates` mit `null`). Werte einzeilig, höchstens 200 Zeichen, Trennzeichen
  eines Scan-Codes sichtbar (`ScanCode::display`).
- Versand über `Notifier::sendMany` (Zweck `device_log`; Fehler nur im Protokoll, der Vorgang ist
  bereits gespeichert; Hinweis-Mails mit Zweck `device_notice`, siehe "Hinweise (API)"). Begrenzung:
  höchstens `MAX_RECIPIENTS` = 25 Empfänger (zuerst Admins der
  Stadt; Überschreitung als `device_log_recipients_capped`) und `TIME_BUDGET_SECONDS` = 10 s je
  Anfrage (danach `mail_failed` mit „Zeitbudget … überschritten“). Eine SMTP-Verbindung für alle
  Mails (auf STRATO gemessen rund 0,22 s je Mail). Kein Cron, keine Warteschlange.

## Erscheinungsbild (API)
- Nur unter Städten (unter `service` 404); einzige Ausnahme: `GET api/v1/app-icon/{size}` (Standard-Symbol)
  gibt es auch unter `service` (Symbol der App-Seite des Super-Admins). Ändern `Access::Admin` (in
  `AdminRoutesAccessTest`), der Super-Admin pflegt das Erscheinungsbild in Version 1 nicht. Lesen öffentlich (Anmeldeseite,
  App-Symbol); gesperrte Stadt 403 `tenant_inactive` wie überall. Code: `src/Tenant/` (`AccentColor`,
  `InvalidAccentColorException`, `TenantAppearance`, `TenantAppearanceRepository`),
  `src/Tenant/Logo/` (`PngInspector`, `LogoRenderer`, `LogoImages`, `LogoSize`, `DefaultAppIcon`,
  `InvalidLogoException`), `src/Http/CacheableResponse.php` und `CachePolicy.php`, Endpunkte
  `src/Api/V1/Appearance/` (`AccentColorHandler`, `TenantLogoHandler`, `ImageHandler`,
  `ManifestHandler`, `AppearanceJson`) und `src/Api/V1/TenantHandler.php`. Tests:
  `tests/Integration/Appearance/AppearanceApiTest`, `tests/Integration/Tenant/TenantAppearanceSchemaTest`,
  `tests/Unit/Tenant/AccentColorTest`, `tests/Unit/Tenant/Logo/`, `tests/Unit/Http/CacheableResponseTest`;
  Test-PNGs zur Laufzeit über `tests/Support/TestPng` (nichts committen).
- `GET api/v1/tenant` (öffentlich): `{slug, name, accent_color, accent_color_is_default, logo_url,
  logo_urls}`; `accent_color` immer ein Wert (eigene Farbe oder Standard), `logo_url` = 512 px,
  `logo_urls` = `{"512", "192", "180"}`, ohne Logo beide `null`. Adressen pfadrelativ
  (`/firefighting/<kürzel>/api/v1/tenant/logo/<größe>?v=<version>`, ohne `base_url`). Die drei
  ändernden Routen antworten mit genau diesem Objekt.
- Akzentfarbe: `POST tenant/appearance {accent_color}` (Feld Pflicht, `null` = Standardfarbe,
  andere Felder 400). `AccentColor::fromString`: Leerraum am Rand weg, `#` Pflicht, 6 oder 3
  Hex-Ziffern (Groß-/Kleinschreibung egal, `#a33` = `#aa3333`), gespeichert als `#rrggbb` klein
  (`fe_tenants.accent_color`, CHECK in Migration `0021`). Kontrast weißer Schrift nach WCAG 2.x
  (sRGB linearisiert, Schwelle 0,04045; `1,05 / (L + 0,05)`), ungerundet ≥ 4,5
  (`AccentColor::MIN_CONTRAST`), sonst 400 `invalid_request` mit Meldung und `details: {"contrast",
  "min_contrast"}`; der Wert wird auf zwei Stellen ABGERUNDET (nie „4,50“ bei Ablehnung). Grenzfälle
  (per Test): `#ec1400` 4,50003 angenommen, `#ea1c05` 4,49989 abgelehnt. Standard
  `AccentColor::DEFAULT` = `#b71c1c` (6,57:1, per Test). Gespeicherte Farben werden beim Lesen nicht
  erneut geprüft (`AccentColor::stored`).
- Logo hochladen: `POST tenant/logo {file}` (PNG als Base64 wie beim Import, Whitespace erlaubt,
  andere Felder 400; Body-Grenze 720 KiB nur für diese Route). Prüfung (`PngInspector`, vor dem
  Dekodieren, Meldungen deutsch, 400 `invalid_request`, ggf. `details`): nicht leer, höchstens
  500 KB (`MAX_BYTES` = 512 000 Byte, `details {bytes, max_bytes}`), PNG-Signatur, alle Chunks
  (Länge, CRC, gültiger Typ), `IHDR` zuerst und nur einmal mit gültigen Werten, mindestens ein
  `IDAT`, `IEND` vorhanden und DANACH NICHTS (angehängte Daten werden abgelehnt, nicht entfernt),
  kein `acTL` (animiert), quadratisch (`details {width, height}`), Kantenlänge `MIN_EDGE` bis
  `MAX_EDGE`, beide 512 (also genau 512 × 512 px; `details {width, height, min_edge, max_edge}`).
  Die Maße stammen aus `IHDR`, große Bilder werden nie dekodiert (Dekompressionsbombe).
- Neu erzeugen (`LogoRenderer`): `imagecreatefromstring` (Warnungen von libpng abgefangen, nicht
  protokolliert), Palette → Truecolor, je Größe aus `LogoSize` ein neues Bild per
  `imagecopyresampled` und `imagepng` (Stufe 9). 512 und 192 transparent (Alpha bleibt erhalten,
  auch `tRNS`), 180 (apple-touch-icon) auf weißem Hintergrund ohne Alpha (iOS füllt Transparenz
  schwarz). GD schreibt nur `IHDR`, `pHYs`, `IDAT`, `IEND` (per Test gegen eine Erlaubt-Liste):
  Text, EXIF, Farbprofile, private Chunks und versteckte Inhalte fallen weg. Gespeichert werden NUR
  die neuen Dateien, nie die hochgeladene.
- Speicherung `fe_tenant_logos` (Migration `0022`): `tenant_id` (Primärschlüssel, höchstens ein Logo je
  Stadt), `version` (die ersten 16 Hex-Zeichen von SHA-256 über die drei Dateien, `LogoImages::version`),
  `png_512`, `png_192`, `png_180` (`MEDIUMBLOB`, CHECK auf die PNG-Signatur), `updated_by`
  (Fremdschlüssel `(updated_by, tenant_id)` → `fe_users (id, tenant_id)`, also ein Konto derselben
  Stadt), `updated_at`. Ersetzen per `INSERT … ON DUPLICATE KEY UPDATE`; `DELETE tenant/logo {}`
  (wiederholbar) löscht die Zeile: bewusst ein echtes `DELETE`, das Logo ist keine Historie.
  Ändernde Methoden öffnen eine eigene Transaktion und setzen `fe_tenants.updated_at`. Lesen nur
  der angefragten Spalte (`LogoSize::column`, feste Werte). `bin/tenant.php purge-device-data`
  lässt das Erscheinungsbild unberührt.
- Auslieferung (öffentlich, siehe "HTTP und REST-API" zu den Cache-Ausnahmen):
  `GET api/v1/tenant/logo/{size}` (ohne Logo 404 `not_found`) und `GET api/v1/app-icon/{size}`
  (Standard-Symbol), `{size}` nur 512, 192, 180 (sonst 404). `Content-Type: image/png`,
  `Content-Length`, `nosniff`, `ETag` `"<version>-<größe>"`; `?v=` gleich der aktuellen Version →
  `Immutable`, sonst die aktuelle Datei mit `Revalidate` (kein Fehler: alte Manifeste verweisen
  auf alte Versionen).
- Standard-Symbol (`DefaultAppIcon`): `resources/app-icon/app-icon-<größe>.png`, Platzhalter
  (Flamme in Weiß auf `#b71c1c`), erzeugt mit `php scripts/generate-default-app-icon.php` (über
  `LogoRenderer`, Dateien werden committet; `*.png binary` in `.gitattributes`). Version wie beim
  Logo aus dem Inhalt der Dateien.
- Web-App-Manifest: `GET /firefighting/<kürzel>/manifest.webmanifest` (`ManifestHandler`, Route
  `manifest.webmanifest` außerhalb von `api/v1`), `application/manifest+json; charset=utf-8`,
  `Revalidate` mit ETag über den Inhalt. Felder: `id`, `start_url`, `scope` = `/firefighting/<kürzel>/`,
  `name` = „<Stadtname> – Geräteverwaltung“, `short_name` = „FF-Geräte“, `lang` `de`, `dir` `ltr`,
  `display` `standalone`, `theme_color` = Akzentfarbe (immer ein Wert), `background_color` `#f3f4f6`,
  `icons` = 192 und 512 px (Logo, sonst Standard-Symbol, versionierte Adressen, `purpose` `any`, kein
  `maskable`, weil ein Logo sonst beschnitten werden kann).
- Entscheidungen mit dem Frontend (Vue, siehe "Frontend"):
  - `start_url` = `scope` = `/firefighting/<kürzel>/`: die Startseite der Stadt im Frontend (die App
    leitet von dort selbst weiter).
  - `background_color` (Ladebildschirm, `ManifestHandler::BACKGROUND_COLOR`) = `--bg` hell aus
    `frontend/src/styles/tokens.css` (`#f3f4f6`, per Test `FrontendAssetsTest` gleich). Das Manifest
    kennt nur einen Wert, daher hell.
  - Standard-Symbol bleibt eine Datei des Backends (`DefaultAppIcon`, versioniert, auch im
    Manifest), keine statische Datei des Frontends.
  - `index.html` verweist relativ (zu `<base href>`) auf `api/v1/app-icon/192` (`icon`) und
    `api/v1/app-icon/180` (`apple-touch-icon`), ohne `?v=` (also `Revalidate`). Den Link auf
    `manifest.webmanifest` setzt die App beim Start nur unter Städten (`linkManifest` in
    `frontend/src/runtime/appearance.ts`; unter `service` gibt es kein Manifest).
  - Nach `GET api/v1/tenant` (`applyTenantAppearance`): `--accent` und
    `<meta name="theme-color">` = `accent_color`, Titel „<Stadtname> – Geräteverwaltung“ (wie
    `NAME_FORMAT`), mit Logo `icon` = `logo_urls["192"]`, `apple-touch-icon` = `logo_urls["180"]`
    (versioniert), ohne Logo das Standard-Symbol wie in `index.html` (auch nach dem Entfernen in
    den Einstellungen); bei einem Fehler (z. B. unbekannte Stadt) bleiben alle Standardwerte.

## Fachliche Entscheidungen (Version 1)
- Rollen, Anmeldung, Registrierung und Löschen von Benutzern: siehe "Benutzer und Anmeldung".
- Neue Stadt: Super-Admin legt Name, Kürzel und E-Mail des ersten Admins an (siehe
  "Benutzer und Anmeldung").
- Geräte, Gerätetypen, Status, Standorte und Historie: siehe "Standorte und Geräte".
- Übersicht "überfällig" und "in 30 Tagen fällig" (umgesetzt, siehe "Prüffristen und
  Zusammenfassung (API)").
- CSV-Export und CSV-Import mit Vorschau und Fehleranzeige (umgesetzt, siehe "CSV-Export (API)" und
  "CSV-Import (API)"). Der Import legt neue Geräte an und ändert bestehende; er legt nie Gerätetypen
  oder Standorte an und sondert weder aus noch nimmt er wieder auf.
- Erscheinungsbild je Stadt (Akzentfarbe, Logo, Web-App-Manifest; umgesetzt, siehe
  "Erscheinungsbild (API)"), gepflegt vom Admin der Stadt.
- Offline-Buchungen (nur Umbuchen und Status, keine Admin-Aktionen): Aktionszeit vom Handy,
  `client_ref` gegen doppelte Wirkung, „der Letzte gewinnt“ nach dem gemeinsamen Stand von Standort
  und Status, nicht übernommene Buchungen nur in der Historie, Zeitlimits in der Konfiguration;
  besondere Ergebnisse als Hinweis an die Admins mit Mail (umgesetzt, siehe "Offline-Buchungen
  (API)" und "Hinweise (API)").
- Frontend: Vue 3 mit Vite, TypeScript, Reka UI, als PWA; ein Build für alle Städte, ausgeliefert
  von PHP (siehe "Frontend" und "Frontend ausliefern"); Konzept in `docs/frontend/KONZEPT.md`.
- Anmeldung und Registrierung im Frontend (umgesetzt: anmelden, registrieren, email-bestaetigen,
  passwort-vergessen, passwort-zuruecksetzen, einladung-annehmen, Abmelden; Updates der App; siehe
  "Frontend" und KONZEPT.md "Anmeldung und Registrierung", "Updates der App").
- User am Handy (abgeschlossen am 30.10.2026: suchen mit Code scannen und „Zuletzt benutzt“,
  Umbuchen und Status, Standortwahl, Details und Verlauf, Prüffristen, Profil, komprimierte
  Auslieferung; siehe "Frontend" und KONZEPT.md "User am Handy"). Wünsche für später in
  `docs/TODO-SPAETER.md`.
- Admin-Konsole (abgeschlossen am 31.10.2026, Schritte 1–13: Menü und Dashboard, Standorte und
  Gerätetypen, Geräteliste mit Sammelaktionen, Gerätedetail, neues Gerät, CSV-Import, Benutzer und
  Einladungscodes, Protokoll, Einstellungen, Live-Tests; siehe "Frontend" und KONZEPT.md
  "Admin-Konsole"). Der abschließende Durchlauf nach `docs/frontend/LIVETEST-ADMIN-KONSOLE.md` lief
  am 31.10.2026 im Browser (Ergebnisse dort und kurz in KONZEPT.md); offen sind nur Punkte für den
  Nutzer (iPhone, schmale Breiten, Handscanner, Mails, Anmelden/Abmelden).
- Nicht in Version 1: Mengenartikel, Etiketten mit QR-/Barcodes erzeugen oder drucken,
  Prüfprotokolle.

## GitHub Actions (CI)
- Workflow `.github/workflows/ci.yml` bei jedem Push auf `main`, bei Pull Requests, nachts
  (`schedule`, `17 2 * * *` UTC) und von Hand (`workflow_dispatch`). Standardrecht `contents: read`;
  nur der Job `deploy` hat `contents: write`. Der nächtliche Lauf hat eine eigene
  `concurrency`-Gruppe (`ci-nightly`), hält also keinen Push auf.
- Ändert ein Push bzw. Pull Request nur `.md`-Dateien (`paths-ignore: '**/*.md'`), läuft kein
  Workflow; `deploy` bleibt dann unverändert, der nächste Lauf mit Code nimmt die Dokumentation mit.
  Tests, die `.md`-Dateien lesen (`SlugRulesDocumentationTest`), laufen daher erst mit dem nächsten
  Push, der die PHP-Tests auslöst, spätestens nachts (siehe "Arbeitsweise").
- Jobs (parallel, Ziel unter 6 Minuten je Lauf): `changes` (betroffene Bereiche), `frontend-checks`
  (Lint, Vitest) und `frontend-build` (Typprüfung und Build) immer; nach `changes` `php` und nach dem
  Build `e2e` (Browser-Tests in bis zu acht Teilen), beide nur, wenn betroffen; danach `e2e-report`
  (Bericht) und `deploy`.
  Gemessen (vollständiger Lauf, 31.10.2026): 5:50 min; `changes` 1:00, `frontend-build` 46 s,
  `frontend-checks` 1:31, `php` 1:28, `e2e` je Teil 2:22–3:41, `e2e-report` 58 s, `deploy` 11 s.
- Bereiche (seit 31.10.2026, Regeln NUR in `frontend/build/ciScope.ts`, Tests
  `tests/unit/ciScope.spec.ts`): Job `changes` (ganze Historie, `npm ci`) bestimmt die Basis: Push
  auf `main` = `git merge-base HEAD origin/deploy` (der letzte main-Stand in `deploy`; nach einem
  roten Lauf bleibt `deploy` stehen, der nächste Push prüft dessen Änderungen mit), Pull Request =
  seine Basis, nachts, von Hand oder ohne `deploy` = `all`. Dann `node build/ciScope.ts <basis>`
  über `git diff --name-only --no-renames <basis> HEAD`:
  - Alles (`EVERYTHING`): `.github/workflows/`, `playwright.config.ts`, `playwright.all.config.ts`,
    `build/ciScope.ts`.
  - PHP-Tests (`PHP`): `src/`, `bin/`, `migrations/`, `tests/` (auch `tests/Fixtures/`),
    `scripts/`, `config/`, `resources/`, `public/`, `composer.*`, `phpunit.xml`, `.htaccess`,
    `.gitattributes` und die Dateien des Frontends, die PHP-Tests abgleichen (`index.html`,
    `build/appBase.ts`, `buildInfo.ts`, `compress.ts`, `src/styles/tokens.css`; per Test aus
    `self::FRONTEND . '/…'` in den PHP-Tests). README/CLAUDE.md nie (siehe "Arbeitsweise").
  - Alle Browser-Tests (`E2E_ALL`): `src/Http/`, `public/`, `composer.*`, `tests/Fixtures/*.json`
    und alles in `frontend/` außer `tests/unit/`, `vitest.config.ts`, `eslint.config.js` und
    Prettier (`E2E_NONE`, keine Browser-Tests) und `tests/e2e/`.
  - Nur betroffene Testdateien: TypeScript in `frontend/tests/e2e/` (außer `globalSetup.ts`; dieses
    und `support/update-server.php` → alle, `E2E_NOT_IMPORTED`) über `playwright test --list
    --only-changed=<basis>`. `--only-changed` kennt nur Testdateien und was sie importieren, nicht
    den Code der App (die Tests laden den Build); deshalb prüft jede Änderung an der App alle.
  - Ausgaben `php`, `e2e` (`true`/`false`), `e2e_files` (Testdateien, leer = alle) und
    `e2e_shards` (Matrix): alle Tests `E2E_SHARDS` (8) Teile, sonst `shardCount` = so viele, dass
    ein Teil höchstens so viele Tests hat wie beim vollständigen Lauf (mindestens 1).
- Nächtlicher Lauf: alle Jobs und alle Browser-Tests, `deploy` läuft nie (nur bei Push). Rot →
  vor dem nächsten „✅ FERTIG“ untersuchen (siehe "Arbeitsweise"). GitHub schaltet `schedule` nach
  60 Tagen ohne Aktivität im Repository ab (dann in den Actions wieder einschalten).
- Job `php`: Ubuntu 24.04, PHP 8.5 mit den Erweiterungen von STRATO (ohne intl/sodium),
  Service-Container `mariadb:10.11` mit Datenbank `firefighting_test`; die Workflow-Datei schreibt
  `config/config.test.php` mit Wegwerf-Zugangsdaten nur für diesen Container (keine echten
  Zugangsdaten, keine Secrets). `innodb_flush_log_at_trx_commit = 2` nur dort. `mariadb-client`
  (mysqldump wie auf dem Server), dann `composer validate --strict`, `composer install`,
  `composer test`. Unter Linux laufen auch die Tests der Unix-Dateirechte und mit mysqldump.
  `composer test` läuft bewusst in EINEM Prozess (kein paratest): Getrennte Testdatenbanken je
  Prozess bräuchten das Recht `CREATE DATABASE` und für die Tests der Kommandozeile (sie erhalten
  den Pfad von `config/config.test.php`) je Prozess eine Kopie der Zugangsdaten auf der Platte; der
  Job liegt zudem nicht auf dem längsten Weg des Laufs. Neu bewerten, wenn er der längste Job wird.
- Job `frontend-checks`: Node aus `frontend/.nvmrc`, `npm ci`, `npm run lint`, `npm run test:unit`.
- Job `frontend-build`: `npm ci`, `npm run build` (mit `vue-tsc`); lädt den Build als Artefakt
  `frontend-dist` hoch. Genau dieser Build wird in `e2e` getestet und kommt nach `deploy`.
- Job `e2e` (Matrix `shard` aus `changes.outputs.e2e_shards`, `fail-fast: false`, braucht `changes`
  und `frontend-build`, nur bei `e2e == 'true'`): PHP (für `php -S`), `composer install --no-dev`,
  `npm ci`, Artefakt `frontend-dist` nach `frontend/dist`, dann `npm run test:e2e --
  --shard=<n>/<anzahl> <e2e_files>` (alle fünf Profile, weil `CI` gesetzt ist; Anzahl aus
  `strategy.job-total`, mehr Teile nur über `E2E_SHARDS`). `globalSetup` baut die zweite
  Version für die Update-Tests in jedem Teil selbst (rund 7 s). Reporter in CI `github` und `blob`
  (`playwright.config.ts`): Teilbericht als Artefakt `blob-report-<n>` (1 Tag), bei Fehlern
  zusätzlich `test-results/` als `playwright-traces-<n>` (7 Tage, Traces gescheiterter Tests:
  `trace: 'retain-on-failure'`).
- Job `e2e-report` (nach `e2e`, auch wenn Tests scheitern, nicht nach Abbruch): führt die
  Teilberichte mit `npx playwright merge-reports --reporter html` zu einem Bericht zusammen,
  Artefakt `playwright-report` (7 Tage, mit Traces gescheiterter Tests).
- Caches: npm über `actions/setup-node` (`cache: npm`); Composer-Downloads über `actions/cache`
  (Schlüssel `composer.lock`, angelegt im Job `php`, `e2e` liest nur); Playwright-Browser
  (`~/.cache/ms-playwright`, Schlüssel = Playwright-Version, gespeichert gleich nach dem
  Installieren nur von Teil 1). Bei Treffer installiert `npx playwright install-deps` nur die
  Systempakete.
- Rote CI-Läufe werden nicht neu gestartet, sondern untersucht. Einzige Ausnahme: Ein Lauf, der
  ausschließlich am bekannten Firefox-Fehler beim Schließen des Browser-Kontexts scheitert
  (`_maybeDontRestoreTabs`, [microsoft/playwright#43413](https://github.com/microsoft/playwright/issues/43413),
  Playwright 1.64 mit Firefox 157, als „not planned“ geschlossen), darf EINMAL neu gestartet werden.
  Scheitert auch der neue Lauf, wird nicht noch einmal neu gestartet, sondern untersucht.
- Job `deploy`: nur bei Push auf `main` und nur, wenn `changes`, `frontend-checks` und
  `frontend-build` grün sind und `php` sowie alle Teile von `e2e` grün oder übersprungen (Bereich
  nicht betroffen; `!cancelled()` statt der Standardbedingung, die übersprungene als Fehler wertet);
  nie parallel
  (`concurrency: deploy-branch`). Lädt das Artefakt und ruft
  `bash scripts/publish-deploy-branch.sh <main-commit> <dist-ordner>` auf.
- `scripts/publish-deploy-branch.sh`: baut mit eigenem temporärem Index den Baum von `main` plus den
  Build als `frontend/dist` und legt einen Commit in `deploy` an. Eltern: der bisherige
  `deploy`-Stand (falls vorhanden) und der `main`-Commit, damit jeder Stand per Fast-Forward
  erreichbar ist (auch vom alten `main` aus). Kein `--force`; gleicher Baum → kein neuer Commit.
  Bricht ab, wenn `index.html` oder `sw.js` fehlen, der Platzhalter nicht genau einmal vorkommt oder
  `frontend/dist` auf `main` versioniert ist (Exit-Code 1; falscher Aufruf 2). Tests:
  `tests/Unit/Scripts/PublishDeployBranchTest` (temporäre Repositorys, eigene leere Git-Konfiguration).
- Nur der Workflow schreibt in `deploy`; Agenten und Menschen ändern den Branch nie von Hand.
- Ruleset auf GitHub für `deploy` (README "Branch deploy schützen"): nur "Restrict deletions" und
  "Block force pushes". Nicht "Restrict updates" (dann dürften nur Bypass-Akteure pushen, und der
  `GITHUB_TOKEN` des Workflows lässt sich nicht als Bypass eintragen) und nicht "Require linear
  history" (die deploy-Commits haben zwei Eltern). Ein strengerer Schutz bräuchte eine eigene
  GitHub App mit Token im Workflow (bisher nicht vorgesehen).
- Versionen der Actions (Stand: checkout v7, setup-node v7, upload-artifact v7, download-artifact v8,
  cache v6 (auch `cache/restore`, `cache/save`), setup-php v2; alle mit `node24`) hält Dependabot aktuell (`.github/dependabot.yml`, wöchentlich
  montags, alle Actions in einem Pull Request mit Präfix `CI`). Neue Versionen vor dem Eintragen
  prüfen (Release und `runs.using` im `action.yml` des Tags), nie raten; upload- und
  download-artifact müssen zueinander passen.

## Deployment
- Ablauf: lokal prüfen (siehe "Arbeitsweise"), committen, nach `main` pushen → GitHub Actions testet und legt Code plus Frontend-Build im Branch
  `deploy` ab (siehe "GitHub Actions (CI)") → auf dem Server `scripts/deploy.sh`: durch den Agenten
  per `ssh -T strato-ff` (siehe "Autonomer Ablauf"), von Hand per SSH mit
  `cd ~/htdocs/apps/firefighting-equipment && bash scripts/deploy.sh`. Erst deployen, wenn der
  CI-Lauf grün ist und `deploy` den gewünschten Stand hat.
- Der Server steht auf dem Branch `deploy` (nie `main`: dort fehlt der Frontend-Build; auf STRATO
  gibt es kein Node.js). Der Umstieg ist erledigt (27.10.2026, erster Stand `0912203`); der lokale
  Branch `main` bleibt auf dem Server vorerst für einen Rücksprung bestehen. Die Anleitung (README
  "Umstieg des Servers auf den Branch deploy") gilt nur noch für einen neu eingerichteten Server.
  Auf `main` bricht das Skript mit Hinweis darauf ab.
- Das Skript bricht bei lokal geänderten Dateien ab, legt eine Sicherung an, holt per
  `git fetch` + `git merge --ff-only origin/deploy`, prüft, dass `frontend/dist/index.html`
  vorhanden ist (sonst Abbruch vor Composer), führt `composer install` aus, prüft mit
  `php85 bin/migrate.php check` auf offene Migrationen, prüft den Health-Endpunkt und zum Schluss
  die App-Seite `https://www.prodad.de/firefighting/service/` (HTTP 200, `text/html; charset=utf-8`,
  `<base href="/firefighting/service/" />`; ohne Stadt und ohne Datenbank). Bei Fehlern springt es
  nicht automatisch zurück, sondern zeigt die Befehle für den Rücksprung an.
- Nur für Tests (`tests/Unit/Scripts/DeployScriptTest`, nachgebaute Server-Umgebung mit
  Platzhaltern für `php85` und `curl`): `FF_DEPLOY_COMPOSER_BIN` und `FF_DEPLOY_BASE_URL`; auf dem
  Server nie setzen.
- Selbst-Neustart: Hat `git merge` `scripts/deploy.sh` geändert, startet sich das Skript direkt
  nach dem Merge per `exec` mit der neuen Fassung neu. Alter Commit und Pfad der Sicherung gehen
  über `FF_DEPLOY_RESUME_OLD_COMMIT` und `FF_DEPLOY_RESUME_BACKUP_FILE` mit; die neue Fassung
  überspringt Sicherung und Merge. Alles bis einschließlich Merge läuft beim nächsten Deployment
  noch in der alten Fassung.
- Sind Migrationen offen: zuerst Datenbanksicherung mit
  `php85 bin/db-dump.php --suffix=vor-migration-<alter Commit>`; schlägt sie fehl, bricht das
  Skript ab, ohne zu migrieren. Exit-Code 4 (keine Tabellen `fe_*`, nichts zu sichern) gilt als
  OK: Hinweis, dann Migration ohne Sicherung. Danach `php85 bin/migrate.php run`. Schlägt die Migration
  fehl, zeigt das Skript den Pfad der Sicherung und die Anleitung zum Zurückspielen
  (README, "Sicherung zurückspielen"). Eine Sicherung wird nie automatisch zurückgespielt.
- Auf dem Server ist das Kommandozeilen-`php` Version 8.4; immer `php85` verwenden
  (z. B. `php85 /usr/bin/composer install --no-dev --optimize-autoloader --no-cache`).
- Der Server holt nur von GitHub (nie `git push` vom Server). Änderungen nie direkt auf
  dem Server machen.
- Sicherungen nur unter `~/htdocs/apps/_backup/` (gesperrt per `.htaccess`); das Skript
  behält die 5 neuesten `ff-deploy-*.tar.gz` (`scripts/prune-deploy-backups.sh`). Der Bereich
  außerhalb von `~/htdocs/apps/` wird nicht verwendet.
- Zeitstempel in Namen von Sicherungen immer in UTC mit Kennzeichen `Z`
  (`JJJJMMTT-HHMMSSZ`, in Bash `date -u +%Y%m%d-%H%M%SZ`). Die Aufräumregel für
  `ff-deploy-*` erkennt zusätzlich ältere Namen ohne `Z` (Ortszeit).
- Aufräumen (`ff-deploy-*` und `ff-db-*`) sortiert nach dem Änderungsdatum der Datei (bei
  Gleichstand nach Namen), nie nach dem Zeitstempel im Namen (alte Namen: Ortszeit, neue: UTC).
  Die gerade erzeugte Sicherung wird nie gelöscht und zählt zu den behaltenen.

## Datenbanksicherung
- `bin/db-dump.php` (nur CLI, Code in `src/Backup/` und `src/Cli/DbDumpCommand.php`):
  `[--config=PFAD] [--target-dir=PFAD] [--suffix=ZUSATZ] [--mysqldump=PROGRAMM]`.
  Standard: `config/config.local.php`, Zielordner `~/htdocs/apps/_backup` (neben dem
  Projektordner), `mysqldump` aus dem PATH. Exit-Codes 0/1/2 wie bei den anderen CLIs, dazu
  4 = nichts zu sichern (`DbDumpCommand::EXIT_NOTHING_TO_DUMP`).
  Bei Erfolg steht auf stdout nur der Pfad der Sicherung, alles andere auf stderr.
- Gesichert werden nur Tabellen `fe_*` (Liste per PDO aus `information_schema`), ohne
  `CREATE DATABASE`. Ist keine Tabelle `fe_*` vorhanden, endet der Aufruf mit Exit-Code 4,
  ohne Datei, ohne Aufräumen und ohne mysqldump aufzurufen (ohne Tabellenliste würde
  mysqldump die ganze Datenbank sichern). `DatabaseDumper` lehnt eine leere Liste weiterhin ab.
- Datei: `ff-db-JJJJMMTT-HHMMSSZ[-zusatz].sql.gz`, gzip, Rechte 600. Zusatz nur `a-z`, `0-9`,
  Bindestrich (nicht am Anfang/Ende, nicht doppelt), höchstens 60 Zeichen.
- Der Zielordner muss existieren und eine `.htaccess` enthalten, sonst Abbruch.
- Zugangsdaten nie in der Befehlszeile: kurzlebige `--defaults-extra-file` (`.ff-db-*.cnf`,
  Rechte 600) im Zielordner, wird in jedem Fall gelöscht. Fehlermeldungen werden maskiert
  (Passwort, Benutzer, Host).
- mysqldump-Optionen: `--single-transaction --skip-lock-tables --no-tablespaces
  --default-character-set=utf8mb4 --quick --hex-blob`. Auf dem Server ist mysqldump ein
  MariaDB-Client (10.5); kein `--column-statistics` verwenden.
- Geschrieben wird zuerst nach `*.part`. Vollständig ist eine Sicherung nur bei Exit-Code 0
  und der Abschlusszeile `-- Dump completed`; sonst wird die Datei gelöscht.
- Nach einer erfolgreichen Sicherung bleiben die 10 neuesten `ff-db-*.sql.gz` (nach
  Änderungsdatum, die neue nie gelöscht); ältere werden gelöscht. Andere Dateien (auch `ff-deploy-*`) werden nie angefasst.
- Zurückspielen nur von Hand (README, "Sicherung zurückspielen"), nie automatisch.

## Lokale Entwicklungsumgebung
- Windows 10, PowerShell, PHP 8.5 CLI, Composer, Git, MariaDB 10.11 (Port 3306).
- Node.js 24 LTS (v24.22.1) mit npm 11 (11.19.1); nur lokal und in GitHub Actions, auf STRATO gibt es
  kein Node.js.

## Noch offen
- Datenschutz (Verantwortlicher, Datenschutzerklärung, Löschfristen).