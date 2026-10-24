# Firefighting-Equipment – Regeln für KI-Agenten

## Zweck
Webanwendung für Feuerwehren: Geräte (z. B. Atemschutzmasken) werden Standorten
(Fahrzeuge, Räume/Lager, Extern) zugeordnet, ihr Status wird erfasst, jede
Änderung wird protokolliert. Mehrere Städte (Mandanten) nutzen denselben Code.

## Arbeitsweise
- In kleinen Schritten arbeiten. Vor größeren Änderungen erst einen Plan vorlegen.
- Bestehende Funktionen dürfen nicht kaputtgehen: Jede Änderung bekommt Tests,
  vor jedem Commit müssen alle Tests grün sein.
- Das Repository ist öffentlich: niemals Passwörter, Zugangsdaten oder
  `config/config.local.php` committen.
- Code und Bezeichner auf Englisch, Dokumentation und Oberflächentexte auf Deutsch.

## Zielumgebung (STRATO, Shared Hosting)
- PHP 8.5 (CGI), MariaDB 10.11, Apache mit `.htaccess` und mod_rewrite.
- Rewrite-Regeln mit `[L]`, nicht mit `[END]` (sonst greift die PHP-Konfiguration nicht).
- Verfügbare PHP-Erweiterungen: PDO_MySQL, MySQLi, mbstring, GD, BCMath, DOM, fileinfo,
  zip, curl, openssl. Nicht verwenden: intl, sodium, xmlreader, xmlwriter, SQLite.
- Keine Cronjobs: Fälligkeiten u. Ä. werden beim Aufruf berechnet.
- Composer ist auf dem Server vorhanden.
- E-Mail-Versand über `mail()` (sendmail).

## Aufbau und URLs
- Projektordner auf dem Server: `/apps/firefighting-equipment`
  - `public/` ist der einzige öffentlich erreichbare Ordner (Front-Controller `index.php`).
  - `config/` liegt außerhalb von `public/` und ist per `.htaccess` gesperrt.
    Zugangsdaten stehen in `config/config.local.php` (nicht im Repository).
- URL-Schema: `https://www.prodad.de/firefighting/<stadt>/`
  - REST-API: `/firefighting/<stadt>/api/v1/...`
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
  `php -S 127.0.0.1:8000 public/index.php`.
- Adresse `/firefighting/<bereich>/<rest>` (`AppPath::parse`): Query-String wird ignoriert, ein
  abschließender `/` toleriert; leere Segmente, fehlendes Präfix oder ein Bereich außerhalb
  `[a-z0-9-]+` → 404. Der Pfad wird nicht URL-dekodiert.
- Reihenfolge in `Application::handle` (nicht ändern): Adresse zerlegen → Route suchen
  (404/405) → erst dann die Stadt laden → Handler. Unbekannte Adressen verbinden sich so nie mit
  der Datenbank.
- Bereich `service` (`AppPath::SERVICE_SCOPE`): Super-Admin, keine Stadt, eigene Routen.
  Sonst Kürzel per `TenantSlug` (ungültig → 404 `not_found`), Stadt per `TenantRepository`
  (unbekannt → 404 `tenant_not_found`, gesperrt → 403 `tenant_inactive`, Datenbankstörung →
  503 `database_unavailable`), alles in `TenantResolver`.
- Router (`Router`): exakte Pfade relativ zum Bereich, ohne Slash am Anfang/Ende, keine
  Platzhalter. Falsche Methode → 405 mit `Allow` (sortiert). `HEAD` wird nicht wie `GET`
  behandelt. Alle Routen stehen in `Routes::service()` und `Routes::tenant()`.
- Handler implementieren `RequestHandler` und erhalten `RequestContext` (Anfrage, Stadt oder
  `null` unter `service`, `LazyConnection`, `ErrorReporter`). Die Datenbankverbindung (und das
  Laden der Konfiguration) erfolgt erst beim ersten `$context->db->get()`.
- Fehlerformat immer `{"error":"code","message":"deutscher Text"}`, erzeugt über die Fabriken in
  `HttpException`; Codes: `not_found` 404, `tenant_not_found` 404, `tenant_inactive` 403,
  `method_not_allowed` 405, `database_unavailable` 503, `internal_error` 500. Neue Codes nur dort.
- Einzige Ausnahme vom Fehlerformat: `GET api/v1/health` liefert `{"status":"ok","database":"ok"}`
  (200) bzw. `{"status":"error","database":"error"}` (503). Unter einer Stadt gilt vorher die
  Stadtprüfung (403/404/503 im Fehlerformat).
- Unerwartete Fehler → 500 `internal_error` mit allgemeiner Meldung. Details nur über
  `ErrorReporter` ins `error_log`: maskiert (`DatabaseError`), ohne Stack-Trace (enthält
  Argumente wie Zugangsdaten). Nie Details oder Zugangsdaten in die Antwort.
- Jede API-Antwort trägt `Content-Type: application/json; charset=utf-8`,
  `Cache-Control: no-store` und `X-Content-Type-Options: nosniff` (`JsonResponse::DEFAULT_HEADERS`,
  nicht überschreibbar).

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
  "ß"/"ss" oder "é"/"e" nicht als gleich gelten (Kleinschreibung erzwingt ein CHECK); feste
  Werte (`role`, `status`, `purpose`, `action`) und `password_hash` sind `ascii`/`ascii_bin`,
  damit `IN (...)` bzw. `LIKE` Groß- und Kleinschreibung unterscheiden.
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
  sind ein Fehler, ein leeres `pass` ist erlaubt.
- Tests: Testsuiten `unit` und `integration` (`composer test`, `composer test:unit`,
  `composer test:integration`). Integrationstests werden übersprungen, wenn
  `config/config.test.php` fehlt. Dauerhafte Tabellen legen sie nur mit Präfix `fe_test_`
  an und löschen sie wieder; aufgeräumt wird ausschließlich `fe_test_*` und nur in einer
  Datenbank mit Endung `_test` (`TestDatabase::dropTestTables`).
- Fachliche Integrationstests erben von `tests/Integration/DomainTestCase.php`: Diese führt
  einmal pro Testlauf die echten Migrationen aus `migrations/` in der Testdatenbank aus
  (Protokoll in deren `fe_schema_migrations`) und leert vor jedem Test alle Tabellen `fe_*`
  außer `fe_schema_migrations` und `fe_test_*` (`TestDatabase::truncateDomainTables`, nur in
  einer Datenbank mit Endung `_test`).
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
  `updated_at` (UTC). Code in `src/Tenant/` (`TenantSlug`, `TenantName`, `Tenant`,
  `TenantRepository`).
- Kürzel (`TenantSlug`): nur `a-z`, `0-9` und Bindestrich, Bindestrich nicht am Anfang/Ende
  und nicht doppelt, höchstens 50 Zeichen, eindeutig. Großbuchstaben werden abgelehnt (mit
  Vorschlag der kleingeschriebenen Form), nie still umgewandelt. `service` ist reserviert
  (`TenantSlug::RESERVED`). Dieselben Regeln prüft die Datenbank per CHECK; beide Stellen
  immer gemeinsam ändern.
- Name (`TenantName`): Leerraum am Rand wird entfernt, nicht leer, höchstens 100 Zeichen,
  keine Steuerzeichen.
- Das Kürzel ist nach dem Anlegen unveränderlich, Städte werden nie gelöscht, nur gesperrt
  (`active = 0`, Daten bleiben erhalten). Da Trigger verboten sind, sichert das der Code:
  `TenantRepository` hat bewusst keine Methode zum Ändern des Kürzels oder zum Löschen; eine
  solche nicht hinzufügen.
- Verwaltung nur per Kommandozeile `bin/tenant.php` (`list`, `create --slug=… --name=…`,
  `deactivate --slug=…`, `activate --slug=…`, `--config=PFAD` wie bei `migrate.php`).
  Auf dem Server: `cd ~/htdocs/apps/firefighting-equipment && php85 bin/tenant.php list`.
  Exit-Codes: 0 OK, 1 Fehler (z. B. ungültiges oder doppeltes Kürzel), 2 falscher Aufruf.

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
- Passwörter: mindestens 10, höchstens 128 Zeichen, keine Komplexitätspflicht, Sperrliste
  häufiger Passwörter, kein Bestandteil von E-Mail, Name oder Stadtname. Hash mit Argon2id
  (`PASSWORD_ARGON2ID`, Standardparameter; auf STRATO in CLI und Web verfügbar).
- Sitzungsdauer (Inaktivität / Höchstdauer): `user` 30 Tage / 90 Tage, `admin` 7 Tage / 30 Tage,
  `superadmin` 1 Stunde / 12 Stunden. Die Inaktivitätsgrenze wird zur Laufzeit aus Rolle und
  `last_seen_at` berechnet. Passwortänderung, Rollenwechsel, Sperren oder Löschen beenden alle
  Sitzungen des Kontos.
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
  `display_name`. Der Super-Admin selbst wird per Skript angelegt.
- Einmal-Links (`fe_user_tokens`): kurz gültig (Bestätigung/Passwort setzen 24 h, Passwort
  zurücksetzen 1 h), nur einmal nutzbar, nur als Hash gespeichert. Einlösen löscht die Zeile;
  je Benutzer und Zweck gibt es höchstens einen offenen Link, ein neuer ersetzt den alten.
- Tokens (Sitzung, Einmal-Links) haben mindestens 32 zufällige Bytes und werden als SHA-256
  (32 Byte) gespeichert; wegen der hohen Zufälligkeit genügt ein schneller Hash. Die Regel gilt
  nicht für Einladungscodes (siehe oben).
- Löschen = Anonymisieren: persönliche Daten (E-Mail, Namen, Passwort-Hash, Sitzungen, Tokens)
  werden entfernt, der Datensatz bleibt für die Historie ("Gelöschter Benutzer #ID"). Benutzer
  werden nie per `DELETE` entfernt; Fremdschlüssel auf `fe_users` kaskadieren nicht.
- Gebremst werden Anmeldung, Registrierung, Passwort zurücksetzen und E-Mail-Bestätigung
  (`fe_auth_attempts`, `action` = `login`, `register`, `password_reset`, `verify_email`).
  E-Mail nur als SHA-256 (bei `verify_email` ohne E-Mail möglich), IP-Adressen nur gekürzt
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
    `#[\SensitiveParameter]`. `PasswordHasher`: `hash()`, `verify()`, `needsRehash()` (Argon2id).
- `UserRepository`: ändernde Methoden (`lock`, `unlock`, `changeRole`, `setPassword`,
  `anonymize`) öffnen eine eigene Transaktion (eine bereits offene ist ein Fehler) und sperren
  zuerst alle Konten derselben Stadt bzw. alle Super-Admins (`FOR UPDATE` über
  `FORCE INDEX (uq_fe_users_tenant_email)`, immer dieselbe Abfrage → gleiche Sperrreihenfolge,
  andere Städte unberührt). Danach Regeln prüfen, dann schreiben.
  - Der letzte aktive (`status = active`), nicht gesperrte Admin einer Stadt bzw. Super-Admin wird
    nicht gesperrt, herabgestuft oder anonymisiert (`UserRuleViolationException`).
  - Die Rolle `superadmin` wird per `changeRole` weder vergeben noch entzogen. `setPassword` nicht
    für `invited` (eigener Ablauf). Gelöschte Konten werden nicht mehr geändert.
  - Sperren, Rollenwechsel, Passwort setzen und Anonymisieren löschen alle `fe_sessions` des
    Kontos; Anonymisieren zusätzlich alle `fe_user_tokens`. Abgelehnte Änderungen lassen alles
    unverändert (Rollback).
  - Keine Methode zum `DELETE` von Benutzern hinzufügen.
- Kommandozeile `bin/user.php` (Code `src/Cli/UserCommand.php`): `superadmin:create --email=…
  --name=…`, `superadmin:list`, `superadmin:password --email=…`, `superadmin:lock --email=…`,
  `superadmin:unlock --email=…`, `list --tenant=…`, `--config=PFAD`; Exit-Codes 0/1/2.
  Auf dem Server: `cd ~/htdocs/apps/firefighting-equipment && php85 bin/user.php superadmin:list`.
  - Passwörter nie als Argument (`--password=…` wird abgelehnt, ohne den Wert auszugeben).
    Verdeckte Eingabe mit Wiederholung per `stty` (`TerminalPasswordPrompt`); unter Windows oder
    ohne Terminal Abbruch mit Hinweis auf `--password-stdin`. Keine PowerShell-Lösung.
  - `--password-stdin` nur für Tests und Skripte (entfernt genau ein Zeilenende).
  - Ausgaben enthalten nie Hashes. Tabellen über `TextTable` (auch `bin/tenant.php`).

## Fachliche Entscheidungen (Version 1)
- Rollen, Anmeldung, Registrierung und Löschen von Benutzern: siehe "Benutzer und Anmeldung".
- Neue Stadt: Super-Admin legt Name, Kürzel und E-Mail des ersten Admins an (siehe
  "Benutzer und Anmeldung").
- Gerät: Pflicht Kennung, Gerätetyp, Standort, Status; optional Seriennummer,
  Hersteller/Modell, Beschaffungsdatum, Notiz, "nächste Prüfung fällig".
- Gerätetypen vom Admin pflegbar (Start: Atemschutzmaske, Atemschutzgerät, Funkgerät,
  Wärmebildkamera).
- Status: einsatzbereit, in Wartung, defekt, ausgesondert.
- Standorte: Fahrzeug, Raum/Lager, Extern; optional mit übergeordnetem Standort; sperrbar,
  Historie bleibt erhalten.
- Jede Änderung von Standort oder Status wird mit Wer/Wann/Von/Nach protokolliert.
- Übersicht "überfällig" und "in 30 Tagen fällig".
- CSV-Export (Semikolon, UTF-8, Umlaute korrekt) und CSV-Import mit Prüfung und Fehleranzeige.
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
- Frontend-Technik (Kandidat: Vue 3 mit Vite) – wird festgelegt, wenn die REST-API steht.
- Datenschutz (Verantwortlicher, Datenschutzerklärung, Löschfristen).