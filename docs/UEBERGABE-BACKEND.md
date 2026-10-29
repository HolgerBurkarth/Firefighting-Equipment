# Übergabe an den Backend-Chat: Änderungen an der API

Jede Änderung an der REST-API (auch neue Felder) wird hier zusätzlich eingetragen, neueste oben,
mit Datum und Betreff des Commits. Verbindlich bleibt `CLAUDE.md`; diese Datei sagt dem
Backend-Chat, was sich seit seinem letzten Stand geändert hat.

Eintrag: Datum, Commit-Betreff (bei noch nicht committeten Änderungen „noch nicht committet“, der
Nutzer ergänzt den Betreff), was sich geändert hat, warum, Tests, Stelle in `CLAUDE.md`.

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
