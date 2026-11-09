# Live-Test Phase 2 (Test-Stadt `/firefighting/test/`)

Ergebnis je Punkt: ☑ bestanden, ☒ Fehler, ☐ offen für Holger. Vom Agenten im Browser (Admin der
Test-Stadt, kein Super-Admin-Konto) am 08.11.2026 nach dem Deploy.

## P2.1 Status „benutzt“ (Build `aa6810cdc675`, danach `ClientApi::MIN` 3 mit `19352f468538`)

- ☑ Migrationen 0049–0051 ausgeführt, `health` ok.
- ☑ M91 per `POST devices/{id}/state` auf `used`: Antwort „benutzt“; `GET devices?status=used` 1
  Treffer; `device-summary` `by_status` mit vier Einträgen (`used` 1); Geräteliste zeigt „● benutzt“;
  danach auf „in Wartung“ zurückgesetzt.
- ☑ `X-Client-Api: 2` → 409 `client_outdated` (`min_client_api` 3), `3` → 200.
- ☑ Farbe und Symbol des Status „benutzt“ am iPhone, hell und dunkel gut lesbar (Holger, 09.11.2026).
- ☑ Eine alte, offene App (Version 2) aktualisiert sich selbst: entfällt, nicht mehr prüfbar (alle Geräte längst aktualisiert).

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
- ☑ Ein Einmalgerät am echten iPhone zeigt nur „Benutzt melden“ (Holger, 09.11.2026): Konto „Holger (Test)“ mit Rolle „Live-Test Geräteträger“, Leiste nur „Suchen“ und „Profil“; M90 zeigt nur „Benutzt melden“; Rückfrage, „Abbrechen“ ändert nichts; HRT 13 zeigt die normale Umbuchen-Ansicht.
- ☑ „Benutzt melden“ offline am iPhone (Flugmodus, Holger, 09.11.2026): Rückfrage „Es kommt in die Sammelstelle Kleiderkammer“, Eintrag in der Warteschlange, nach Verbindung angekommen; Aktionszeit vom iPhone übernommen (34 s vor Eingang).

## P2.4 Wartung (Build `e737f630f9d8`)

- ☑ `GET maintenance` ohne freigeschaltete Aufgaben: 403 (Bereich fehlt, Menü ohne „Wartung“ – wie vor Phase 2).
- ☑ Sammelstelle, Werkstatt und Lager freigeschaltet und zugewiesen; Seite „Wartung“: „Zur Werkstatt …“ →
  Atemschutzwerkstatt, in Wartung; „Zurück ins Lager …“ → Lager Gerätehaus, einsatzbereit; Verlauf stimmt;
  „Transportliste kopieren“ liefert eine saubere Liste; Menü „Wartung“ nur mit freigeschalteten Aufgaben;
  danach zurückgesetzt, Wartung 403, M90 wieder HLF 20 / G1, einsatzbereit (geprüft von Claude im Browser,
  09.11.2026).


## Runde 9: P2.5 bis P2.7 und Korrekturen (Build `715e960ad7be`, 09.11.2026)

Vom Agenten im Browser (Admin der Test-Stadt, kein Super-Admin-Konto). Die Aufgaben „Ausgeliehen an“ und „Rückgabe“
kann nur der Super-Admin freischalten; ohne sie gibt es den Bereich Verleih nicht (wie vor Phase 2).

- ☑ Migrationen 0055–0060 ausgeführt, `health` ok, `build.json` = `715e960ad7be`.
- ☑ `GET loans` ohne freigeschaltete Aufgabe: 403. `POST returns/check` mit erfundenem Schlüssel: 400 „Dieser
  Rückgabe-Link gilt nicht (mehr)“, ohne Anmeldung erreichbar.
- ☑ Sollbestand: Regel „mindestens 99 Atemschutzgerät“ an HLF 20 gesetzt; „Standorte“ zeigt das Panel „Sollbestand“
  mit „außer Betrieb“ und „6 vorhanden, 93 fehlen“; danach alle Regeln entfernt (`stock` null).
- ☑ „Benutzt melden“ an M90: Rückfrage „M90 als benutzt melden?“, Fokus auf „Abbrechen“, Abbrechen ändert nichts. (Direkt
  geöffnet, ohne vorherige Synchronisierung: Text „falls es eine gibt – sonst bleibt der Standort“.)
- ☐ Als Super-Admin „Ausgeliehen an“ und „Rückgabe“ freischalten, je einen Standort zuweisen; dann Seite „Verleih“:
  Verleihen mit Nummer, „Rückgabe bis“ überfällig rot, Kachel im Dashboard, roter Zähler im Menü (Holger; danach
  abschalten).
- ☑ Rückgabe ohne Anmeldung per QR-Code (Aushang am PC-Bildschirm mit dem iPhone gescannt, Holger, 09.11.2026): Seite ohne Anmeldung, Schlüssel sofort aus der Adresse, M92 „In Ordnung“ zurückgegeben; Verlauf „Rückgabe“ von „Holger IPhone (ohne Anmeldung)“, Hattingen → Ippingen. Alles danach zurückgesetzt (Aushang widerrufen, Aufgaben entfernt); Rolle ID 23 bleibt. Aushang drucken: offen.
- ☐ „In der Rückgabe“: „In Ordnung ins Lager“ und „Benutzte und Defekte in die Sammelstelle“ (Holger).
- ☐ Seite „Wartung“: gleiche Zeit sortiert natürlich nach Nummer (HRT 16 vor HRT 20), Rückfragen mit „Sie steht/Sie
  stehen“; Standort-Liste: Chip „Standard-Lager“; Rollen-Editor: Vorschau „Darf“ nennt „bei Einmalgeräten nur Benutzt
  melden“ (Holger, braucht freigeschaltete Aufgaben bzw. Super-Admin).

### Live-Prüfung Runde 9 durch Claude im Browser (Test-Stadt, 09.11.2026)

Geprüft mit freigeschalteten Aufgaben (danach alles zurückgesetzt: Abläufe abgeschaltet, Aufgaben entfernt, Aushang widerrufen, M92 und M93 wieder am Ausgangsort; die Rolle „Live-Test Geräteträger“ (ID 23) bleibt).

- ☑ Abläufe: alle fünf Aufgaben freigeschaltet und zugewiesen (Lager Gerätehaus = Lager, Kleiderkammer = Sammelstelle, Atemschutzwerkstatt = Werkstatt, Hattingen = Ausgeliehen an, Ippingen = Rückgabe); Standortliste zeigt „Standard-Lager“.
- ☑ Rollen-Editor: Vorschau „Darf“ nennt „bei Einmalgeräten nur Benutzt melden“.
- ☑ Wartung: Rückfragen in Einzahl/Mehrzahl richtig (HRT 16 nach HRT 20 war richtig: 1 s später gebucht).
- ☑ Verleihen: M92 an Hattingen mit Frist 08.11.2026 → „seit 1 Tag überfällig“ rot, roter Zähler „Verleih 1“ im Menü, Dashboard-Kachel „Verliehen 1, davon 1 überfällig“.
- ☑ Aushang erzeugen, erneuern (mit Rückfrage), widerrufen (mit Rückfrage); QR-Code vorhanden; der Schlüssel verschwindet sofort aus der Adresse.
- ☑ Rückgabe ohne Anmeldung: M92 „benutzt“ → Verlauf „Rückgabe“ von „Live-Test Claude (ohne Anmeldung)“ mit Hinweis; Enter prüft; nicht verliehene und unbekannte Nummer bekommen dieselbe Meldung.
- ☑ In der Rückgabe: „Benutzte und Defekte in die Sammelstelle …“ (M92 → Kleiderkammer, benutzt) und „In Ordnung ins Lager …“ (M93 → Lager Gerätehaus, einsatzbereit).
- ☒ „Benutzt melden“ bei direkt geöffneter Seite (M94): Ersatztext „falls es eine gibt …“ trotz vorhandener Sammelstelle → behoben in Runde 10 (`client-config` wird vor der Rückfrage geladen), live noch zu prüfen.
- ☒ Korrekturen aus der Prüfung, behoben in Runde 10 (live noch zu prüfen): Meldung „1 Ausrüstung an „Hattingen“ verliehen.“; die Liste zeigt nach dem Verleihen nie kurz „Zurzeit ist nichts verliehen.“ („Liste wird aktualisiert …“); ein Verleih heißt im Verlauf, in „Letzte Aktivitäten“, im CSV und in der Log-Mail „Verliehen“; nach „Widerrufen“ steht „Kein gültiger Aushang“.

## Runde 10: P2.8 (Sollbestand bei Buchungen, Tausch; 09.11.2026)

Build `cc9926f979eb`. Vom Agenten im Browser geprüft nur: Knopf „Tauschen …“ erscheint im Gerätedetail (M23), `POST devices/swap` mit demselben Gerät zweimal → 400 „nicht gegen sich selbst getauscht“ (☑). Die fachlichen Abläufe unten brauchen Testdaten (Sollbestand, Sammelstelle) und sind offen.

- ☐ Sollbestand setzen (HLF 20: mindestens 2 Atemschutzmasken, 2 einsatzbereite am HLF); eine davon in Wartung setzen: Hinweis
  „Am Standort … blieben nur 1 von mindestens 2“, Knöpfe „Tauschen …“ und (Admin) „Trotzdem buchen …“.
- ☐ „Tauschen …“: Ersatz aus dem Lager wählen, M-Nummer geht in die Sammelstelle (benutzt), Ersatz steht am HLF; Verlauf beider
  Ausrüstungen; Sollbestand wieder erfüllt; danach alles zurücksetzen und die Regeln entfernen.
- ☐ „Trotzdem buchen“ (Admin): Hinweis „Sollbestand unterschritten“ im Protokoll; erledigen.
- ☐ Geräteträger (Rolle ID 23) ohne `device.swap`: kein Knopf „Tauschen …“ (Rolle um die Aktion erweitern nur mit Holger).
