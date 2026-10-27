# Firefighting-Equipment – Regeln für KI-Agenten

## Zweck
Webanwendung für Feuerwehren: Geräte (z. B. Atemschutzmasken) werden Standorten
(Fahrzeuge, Räume/Lager, Extern) zugeordnet, ihr Status wird erfasst, jede
Änderung wird protokolliert. Mehrere Städte (Mandanten) nutzen denselben Code.

## Arbeitsweise
- In kleinen Schritten arbeiten. Vor größeren Änderungen erst einen Plan vorlegen.
- Bestehende Funktionen dürfen nicht kaputtgehen: Jede Änderung bekommt Tests,
  vor jedem Commit müssen alle Tests grün sein.
- Agenten committen und pushen nie; das macht der Nutzer.
- Das Repository ist öffentlich: niemals Passwörter, Zugangsdaten oder
  `config/config.local.php` committen.
- Code und Bezeichner auf Englisch, Dokumentation und Oberflächentexte auf Deutsch.
- Antworten an den Nutzer immer auf Deutsch.
- Live-Tests (gegen den Server www.prodad.de) nur in der Test-Stadt `/firefighting/test/`, nie in
  echten Städten.

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
- Präfix `/firefighting` nur in `AppPath::PREFIX`; lokal gilt dasselbe Schema mit
  `php -S localhost:8000 public/index.php` (`localhost`, nicht `127.0.0.1`: nur dort nehmen
  Browser und curl das `Secure`-Cookie über `http://` an).
- Adresse `/firefighting/<bereich>/<rest>` (`AppPath::parse`): Query-String wird ignoriert, ein
  abschließender `/` toleriert; leere Segmente, fehlendes Präfix oder ein Bereich außerhalb
  `[a-z0-9-]+` → 404. Der Pfad wird nicht URL-dekodiert.
- Reihenfolge in `Application::handle` (nicht ändern): Adresse zerlegen → Route suchen
  (404/405) → ändernde Anfrage prüfen (`RequestGuard`, ohne Datenbank; 415/403/400) → erst dann
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
  `Retry-After`), `database_unavailable` 503, `internal_error` 500. Neue Codes nur dort. Ein Frontend meldet bei 401 ab; daher ist ein falsches aktuelles
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
  (`CacheableResponse`, siehe "Erscheinungsbild (API)").
- Ausnahmen von `Cache-Control: no-store` (eng begrenzt): NUR `CacheableResponse`, NUR für die drei
  öffentlichen Routen `GET api/v1/tenant/logo/{size}`, `GET api/v1/app-icon/{size}` und
  `GET manifest.webmanifest` (ohne personenbezogene Daten, `Access::Public`, also nie eine Sitzung
  und nie `Set-Cookie`). `CacheableResponse` erlaubt nur die Content-Types `image/png` und
  `application/manifest+json; charset=utf-8` und nur die beiden Richtlinien aus `CachePolicy`:
  `Immutable` (`public, max-age=31536000, immutable`, nur wenn `?v=` gleich der aktuellen
  Versionskennung ist) und `Revalidate` (`no-cache`: ohne oder mit veralteter Kennung und beim
  Manifest). Immer mit starkem `ETag` und `nosniff`; `If-None-Match` (auch `W/`, Liste, `*`) → 304
  ohne Body. Fehler dieser Routen bleiben JSON mit `no-store`. Keine weiteren Ausnahmen ohne
  Rückfrage; neue nur über `CacheableResponse`/`CachePolicy` und hier dokumentiert.
- Antworten implementieren `Http\Response` (`statusCode()`, `headers()`, `send()`): `JsonResponse`
  (Regelfall), `CsvResponse` oder `CacheableResponse`. `Application` erzeugt nur JSON vorab
  (Kodierfehler → 500); ein CSV-Download wird erst beim Senden geschrieben.

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
  `composer test:integration`). Die ganze Suite dauert lokal unter einer Minute;
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
  Stadt (`listActiveAdmins`).
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
  offset}`; je Gerät `id`, `identifier`, `scan_code`, `device_type {id, name}`, `location {id, name, path}`
  (Pfad „HLF 20 / G1“), `status`, `status_label`, `next_inspection_due`, `version`.
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
  `DeviceBookingRepository`, `ClientRefConflictException`, `DuplicateClientRefException`),
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
- Code: `src/Notification/DeviceLogNotifier.php` (`deviceChanged`, `imported`; `fromContext()`),
  `DeviceEventRecorder` (IDs der Ereignisse der letzten erfolgreichen Transaktion von
  `DeviceRepository`; nach Rollback leer; nur der ungepufferte `DeviceEventWriter` meldet IDs),
  `DeviceEventRepository::findForDevice`, Vorlagen `MailTemplates::deviceLog` und
  `deviceImportLog`. Aufruf in `DeviceActionHandler` und `DeviceImportHandler` NACH der Rückkehr des
  Repositorys (also nach dem Commit). Tests: `tests/Integration/Notification/DeviceLogNotificationTest`.
- Empfänger: `UserRepository::listDeviceLogRecipients` (Admins nur der Stadt des Vorgangs,
  Super-Admins aller Städte; aktiv, nicht gesperrt, Option an), nie der Auslöser. Eine Mail je
  Empfänger und Anfrage; ein CSV-Import ergibt EINE Sammelmail (Zusammenfassung, höchstens
  `MAX_IMPORT_LINES` = 50 Zeilen mit Einzelheiten, danach „… und N weitere“; ohne neue oder geänderte
  Geräte keine Mail).
- Betreff „Log <Stadtname>: <Grund>“ (z. B. „MA-01 umgebucht, Status defekt“, „CSV-Import: 2 neu,
  1 geändert“, höchstens 200 Zeichen). Text: Vorgang, Gerät (Kennung, Typ), Wer (Anzeigename), Wann
  (Europe/Berlin), Von/Nach je Ereignis, Kommentar, Link `AppUrls::area` (Startseite der Stadt, bis
  das Frontend Geräteseiten hat). Werte einzeilig, höchstens 200 Zeichen, Trennzeichen eines
  Scan-Codes sichtbar (`ScanCode::display`).
- Versand über `Notifier::sendMany` (Zweck `device_log`; Fehler nur im Protokoll, der Vorgang ist
  bereits gespeichert; Hinweis-Mails mit Zweck `device_notice`, siehe "Hinweise (API)"). Begrenzung:
  höchstens `MAX_RECIPIENTS` = 25 Empfänger (zuerst Admins der
  Stadt; Überschreitung als `device_log_recipients_capped`) und `TIME_BUDGET_SECONDS` = 10 s je
  Anfrage (danach `mail_failed` mit „Zeitbudget … überschritten“). Eine SMTP-Verbindung für alle
  Mails (auf STRATO gemessen rund 0,22 s je Mail). Kein Cron, keine Warteschlange.

## Erscheinungsbild (API)
- Nur unter Städten (unter `service` 404). Ändern `Access::Admin` (in `AdminRoutesAccessTest`),
  der Super-Admin pflegt das Erscheinungsbild in Version 1 nicht. Lesen öffentlich (Anmeldeseite,
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
  `display` `standalone`, `theme_color` = Akzentfarbe (immer ein Wert), `background_color` `#ffffff`,
  `icons` = 192 und 512 px (Logo, sonst Standard-Symbol, versionierte Adressen, `purpose` `any`, kein
  `maskable`, weil ein Logo sonst beschnitten werden kann).
- Abhängig von der noch offenen Frontend-Technik (bei deren Festlegung prüfen, siehe "Noch offen"):
  `start_url` (Startseite der Stadt), `background_color` (Ladebildschirm,
  `ManifestHandler::BACKGROUND_COLOR`), das Standard-Symbol (Gestaltung und ob es eine statische
  Datei des Frontends wird) und die Ablage statischer Dateien (Manifest-Link, `apple-touch-icon` im
  HTML des Frontends). Unabhängig davon: `scope`, `id`, die API-Adressen der Bilder.

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
- Nicht in Version 1: Mengenartikel, QR-/Barcodes, Prüfprotokolle.

## Deployment
- Ablauf: lokal testen (alle Tests grün), committen, zu GitHub synchronisieren, dann auf
  dem Server per SSH: `cd ~/htdocs/apps/firefighting-equipment && bash scripts/deploy.sh`.
- Das Skript bricht bei lokal geänderten Dateien ab, legt eine Sicherung an, holt per
  `git fetch` + `git merge --ff-only origin/main`, führt `composer install` aus, prüft mit
  `php85 bin/migrate.php check` auf offene Migrationen und prüft zum Schluss den
  Health-Endpunkt. Bei Fehlern springt es nicht automatisch zurück, sondern zeigt die
  Befehle für den Rücksprung an.
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
- Node.js ist noch nicht installiert.

## Noch offen
- Frontend-Technik (Kandidat: Vue 3 mit Vite) – wird festgelegt, wenn die REST-API steht. Danach
  im Manifest prüfen: `start_url`, `background_color`, Standard-Symbol, Ablage statischer Dateien
  (siehe "Erscheinungsbild (API)").
- Datenschutz (Verantwortlicher, Datenschutzerklärung, Löschfristen).