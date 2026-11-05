# Übergabe an den Backend-Chat: Änderungen an der API

Jede Änderung an der REST-API (auch neue Felder) wird hier zusätzlich eingetragen, neueste oben,
mit Datum und Betreff des Commits. Verbindlich bleibt `CLAUDE.md`; diese Datei sagt dem
Backend-Chat, was sich seit seinem letzten Stand geändert hat.

Eintrag: Datum, Commit-Betreff (der Agent trägt den Betreff seines Commits ein; seit 31.10.2026
committet er selbst, pushen tut nur der Nutzer), was sich geändert hat, warum, Tests, Stelle in
`CLAUDE.md`.

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
