# Live-Test Phase 2 (Test-Stadt `/firefighting/test/`)

Ergebnis je Punkt: ☑ bestanden, ☒ Fehler, ☐ offen für Holger. Vom Agenten im Browser (Admin der
Test-Stadt, kein Super-Admin-Konto) am 08.11.2026 nach dem Deploy.

## P2.1 Status „benutzt“ (Build `aa6810cdc675`, danach `ClientApi::MIN` 3 mit `19352f468538`)

- ☑ Migrationen 0049–0051 ausgeführt, `health` ok.
- ☑ M91 per `POST devices/{id}/state` auf `used`: Antwort „benutzt“; `GET devices?status=used` 1
  Treffer; `device-summary` `by_status` mit vier Einträgen (`used` 1); Geräteliste zeigt „● benutzt“;
  danach auf „in Wartung“ zurückgesetzt.
- ☑ `X-Client-Api: 2` → 409 `client_outdated` (`min_client_api` 3), `3` → 200.
- ☐ Farbe und Symbol des Status am iPhone und im Dunkelmodus ansehen (Holger).
- ☐ Eine alte, offene App (Version 2) aktualisiert sich selbst (Holger, mit einem zweiten Gerät).

## P2.2 Standort-Aufgaben (Build `7e1a6873b531`)

- ☑ Migrationen 0052/0053 ausgeführt; `GET location-tasks` liefert die fünf Aufgaben, alle nicht
  freigeschaltet; Standorte tragen `task`, `is_default_store`, `collection_accepts`.
- ☑ `POST locations/{id}/task` ohne Freischaltung: 409 „Die Aufgabe „Lager“ ist für diese Stadt nicht
  freigeschaltet.“
- ☑ Reiter „Abläufe“ im Stadtdetail der Test-Stadt (Super-Admin): Lager, Sammelstelle, Werkstatt freigeschaltet,
  Sammelstelle in „Sammelstelle Wache“ umbenannt; Meldung „Die Abläufe sind gespeichert.“, Name bleibt nach dem
  Neuladen (geprüft von Claude im Browser, 09.11.2026, Antwort auf Runde 8b).
- ☑ „Standorte“ als Admin: Lager Gerätehaus = Lager (automatisch Standard-Lager), Kleiderkammer = Sammelstelle
  (Auswahl „Nimmt an“), Atemschutzwerkstatt = Werkstatt; Chips in der Liste; danach die Aufgaben wieder entfernt
  (geprüft von Claude im Browser, 09.11.2026).

## P2.3 Einmalgeräte und „Benutzt melden“ (Build `e737f630f9d8`, 09.11.2026)

- ☑ Migration 0054 ausgeführt, `health` ok; `GET device-types` liefert `single_use`.
- ☑ Typ „Live-Testtyp 2“ per `POST device-types/{id}/single-use` auf true und zurück auf false; die Geräte
  tragen `device_type.single_use`.
- ☑ M91 per `POST devices/{id}/used`: Status benutzt, Standort blieb (keine Sammelstelle eingerichtet);
  danach auf „in Wartung“ zurückgesetzt.
- ☑ Rollen-Editor: Abschnitt „3 · Einschränkungen“ vorhanden; Rolle „Live-Test Geräteträger“ (ID 23, Test-Stadt)
  angelegt mit Suchen Ansehen, Umbuchen, Status setzen, Benutzt melden und der Einschränkung (geprüft von Claude
  im Browser, 09.11.2026). Diese Rolle bleibt für den Test am iPhone stehen, nicht löschen.
- ☑ „Benutzt melden“ am Handy (Breite): M90 → Kleiderkammer · benutzt (geprüft von Claude im Browser, 09.11.2026).
- ☐ Ein Einmalgerät am echten iPhone zeigt nur „Benutzt melden“ (Holger).
- ☐ „Benutzt melden“ offline am iPhone (Warteschlange „M1 → benutzt gemeldet“, jetzt mit Rückfrage; Holger).

## P2.4 Wartung (Build `e737f630f9d8`)

- ☑ `GET maintenance` ohne freigeschaltete Aufgaben: 403 (Bereich fehlt, Menü ohne „Wartung“ – wie vor Phase 2).
- ☑ Sammelstelle, Werkstatt und Lager freigeschaltet und zugewiesen; Seite „Wartung“: „Zur Werkstatt …“ →
  Atemschutzwerkstatt, in Wartung; „Zurück ins Lager …“ → Lager Gerätehaus, einsatzbereit; Verlauf stimmt;
  „Transportliste kopieren“ liefert eine saubere Liste; Menü „Wartung“ nur mit freigeschalteten Aufgaben;
  danach zurückgesetzt, Wartung 403, M90 wieder HLF 20 / G1, einsatzbereit (geprüft von Claude im Browser,
  09.11.2026).

