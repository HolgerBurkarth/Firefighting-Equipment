# Übergabe an den Backend-Chat: Änderungen an der API

Jede Änderung an der REST-API (auch neue Felder) wird hier zusätzlich eingetragen, neueste oben,
mit Datum und Betreff des Commits. Verbindlich bleibt `CLAUDE.md`; diese Datei sagt dem
Backend-Chat, was sich seit seinem letzten Stand geändert hat.

Eintrag: Datum, Commit-Betreff (der Agent trägt den Betreff seines Commits ein; seit 31.10.2026
committet er selbst, pushen tut nur der Nutzer), was sich geändert hat, warum, Tests, Stelle in
`CLAUDE.md`.

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
