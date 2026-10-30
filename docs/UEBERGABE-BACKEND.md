# Übergabe an den Backend-Chat: Änderungen an der API

Jede Änderung an der REST-API (auch neue Felder) wird hier zusätzlich eingetragen, neueste oben,
mit Datum und Betreff des Commits. Verbindlich bleibt `CLAUDE.md`; diese Datei sagt dem
Backend-Chat, was sich seit seinem letzten Stand geändert hat.

Eintrag: Datum, Commit-Betreff (der Agent trägt den Betreff ein, den er für den Commit vorschlägt;
der Nutzer verwendet ihn), was sich geändert hat, warum, Tests, Stelle in `CLAUDE.md`.

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
