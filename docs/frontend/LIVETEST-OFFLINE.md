# Live-Test des Offline-Modus

Offline-Modus (KONZEPT.md, "Offline-Modus – Entscheidungen", 48–56) nur in der Test-Stadt
`https://www.prodad.de/firefighting/test/`, mit Testgeräten M90–M99 (Werte danach zurücksetzen).
Echtes Offline (Flugmodus) kann Claude im Browser nicht prüfen; diese Punkte machte Holger bisher am
iPhone. Seit 11.11.2026 prüft das Skript `npm run test:live-offline` (siehe unten, Abschnitt „Live-Lauf
Offline“) das meiste selbst mit echtem `setOffline` in Chromium; das iPhone entfällt als regelmäßige
Prüfung und wird nur noch bei gemeldeten Problemen gebraucht. Ergebnisse bitte mit der Nummer eintragen
(☑ bzw. ☒ mit Fund).

## Vorbereitung
- iPhone mit der Test-Stadt als App auf dem Home-Bildschirm (oder Safari), angemeldet als Benutzer
  oder Admin; einmal online „Suchen“ öffnen und ein Gerät öffnen (dann liegen Geräte und Standorte in
  der Kopie).
- Am PC als Admin die Geräteliste bzw. das Gerätedetail von M90 offen, um den Stand zu sehen.

## A Online unverändert (Claude per Chrome)
- ☑ A1 Umbuchen eines Testgeräts (M90) online wie bisher: grüne Bestätigung, Verlauf ohne
  „Eingang beim Server“, keine Warteschlange auf „Suchen“; danach zurückgesetzt. (31.10.2026, Build
  `487cdffbb4bf`: M90 → „in Wartung“ „✓ M90 → Lager Gerätehaus · in Wartung“, dann zurück auf
  „einsatzbereit“; keine Warteschlange, kein Streifen, keine Konsolenfehler.)
- ☑ A2 Kein grauer Streifen online; die Kopie liegt in IndexedDB (`ff-offline`, `snapshots`,
  `test:<konto-id>`), die Sitzung in `localStorage['ff-session:test']`. (31.10.2026: Datenbank
  Version 2 mit `queue` und `snapshots`, Kopie `test:4`; die Version 2 entstand durch die
  Selbstheilung nach meiner Diagnose, siehe Nachtarbeit-Datei.)

- ☑ A3 Wiederholung nach Verbindungsabbruch (Server): M90 online gebucht mit `client_ref`, dieselbe
  Buchung mit `occurred_at` (wie aus der Warteschlange) → 200 `replayed`, keine zweite Version;
  anderer Kommentar → 409 `client_ref_conflict`; danach zurückgesetzt. (01.11.2026, Build
  `f80fe02030cf`, per JavaScript im Browser.)
- ☒→☑ A4 Synchronisierung (Build `71a179b53e9f`, 01.11.2026): „Suchen“ lädt Geräte, Standorte und
  `client-config` (☑), Grenzen gespeichert (☑). ☒ Die Kopie blieb auf dem Stand von gestern 17:01:
  Seit der Selbstheilung (Version 2) scheiterte jedes Öffnen der Datenbank mit fester Version 1
  (`VersionError`). Behoben mit „Offline: Warteschlange bleibt beim Kontowechsel; lokale Datenbank ohne feste Version öffnen“; Ergebnis nach
  dem Deploy siehe A5.
- ☑ A5 Nach der Behebung (Build `c538ba392497`, 01.11.2026): Datenbank Version 2 öffnet, Kopie
  frisch gespeichert (69 Geräte, 7 Standorte), Besitzer der Warteschlange gemerkt
  (`ff-queue-owners:test`), kein Hinweis auf fremde Buchungen, keine Konsolenfehler. Der
  Kontowechsel selbst (B8a) braucht An- und Abmelden und bleibt für Holger.

## B Offline am iPhone (Holger)
Ergebnis (Holger, iPhone, 01.11.2026): ☑ B1–B9; B8a offen; B10 nicht live geprüft (durch
automatische Tests abgedeckt).
- ☑ B1 Flugmodus an, App öffnen bzw. neu laden: grauer Streifen „Offline – angezeigt wird der Stand
  vom …“, keine Anmeldeseite; „Suchen“ findet Geräte, „Prüffristen“ zeigt die Listen.
- ☑ B2 Offline M90 öffnen, Status „in Wartung“, Speichern: Hinweis „Gespeichert – wird gesendet,
  sobald eine Verbindung besteht: M90 → in Wartung“; auf „Suchen“ die Warteschlange mit Uhr; M90
  zeigt in der Suche „in Wartung“.
- ☑ B3 Offline „Anderen Standort suchen …“: alle aktiven Standorte erscheinen (aus der Kopie, auch
  wenn online nie Umbuchen geöffnet war); Umbuchen auf ein Fach, Speichern → zweite Buchung in der
  Warteschlange.
- ☑ B4 Offline „Code scannen“: Kamera und Lesemodul funktionieren ohne Netz (Modul im Cache).
- ☑ B5 Flugmodus aus: Die Buchungen gehen von selbst raus (Haken, „Die Buchung wurde übernommen.“);
  am PC zeigt das Gerätedetail von M90 den neuen Stand, der Verlauf die Aktionszeit vom Handy und
  „Eingang beim Server“. „Angekommene ausblenden“ entfernt sie.
- ☑ B6 Konflikt: offline M90 auf „defekt“ (mit Kommentar) buchen, währenddessen am PC M90 auf
  „einsatzbereit“ setzen; dann Flugmodus aus. Ergebnis in der App gelb (nur im Verlauf bzw.
  überschrieben, je nach Reihenfolge der Zeiten), im Protokoll der Admins ein Hinweis, Mail „Log“ an
  die Empfänger.
- ☑ B7 Abmelden mit wartender Buchung (Flugmodus an, buchen, dann Flugmodus aus, aber bevor sie
  rausgeht – oder Server-Störung): Rückfrage „1 Buchung wurde noch nicht gesendet …“; „Abbrechen“
  behält alles. (Abmelden selbst geht nur online.)
- ☑ B8 Nach dem Abmelden und Anmelden mit einem anderen Konto: keine Geräte des alten Kontos
  sichtbar, bevor die Liste geladen ist; keine fremde Warteschlange.
- ☐ B8a (offen für Holger) Kontowechsel OHNE Abmelden (Entscheidung 55): Konto A bucht offline (Flugmodus), dann (online,
  Server gestört oder sofort danach) im selben Browser Konto B anmelden (z. B. Sitzung von A in einem
  anderen Tab beendet): Auf „Suchen“ steht „Auf diesem Gerät liegt 1 nicht gesendete Buchung von A.
  Sie wird gesendet, sobald A sich wieder anmeldet.“; die eigene Warteschlange von B ist leer. A
  wieder anmelden → die Buchung geht raus. Zweiter Durchgang: als B „Verwerfen …“ → Rückfrage →
  Hinweis weg, A hat danach keine Buchung mehr.
- ☑ B9 Uhr des Handys 10 Minuten vorstellen, offline buchen, Flugmodus aus: Meldung „Die Uhr dieses
  Geräts geht vor …“ mit „Verwerfen“. Uhr zurückstellen.
- ☐ B10 Grenzen: Mit `offline.queue_max_age_days` = 1 auf dem Server (nur, wenn Holger die
  Konfiguration dafür ändern möchte) eine Buchung über einen Tag offline liegen lassen → „Trotzdem
  senden“ bzw. „Verwerfen“. Sonst als offen markieren.
  Nicht live geprüft (bräuchte eine Änderung der Server-Konfiguration; 01.11.2026), durch
  automatische Tests abgedeckt: `offline.spec.ts` „alte Buchung: erst „Trotzdem senden“, dann mit
  confirmed_stale“ (Browser), `bookingQueue.spec.ts` „alte Buchung: erst Rückfrage, dann mit
  confirmed_stale“ und „stale_action des Servers ebenso; clock_implausible ist ein Fehler zum
  Verwerfen“ (Vitest), `DeviceOfflineBookingTest::testStaleLimitAndConfirmation` (PHPUnit, Grenze
  `queue_max_age_days` und `confirmed_stale`).

## Sperre für veraltete App-Versionen (Phase 0, Änderungskonzept 2026-11)

Live geprüft am 04.11.2026 im Browser (Chrome, Test-Stadt, als Admin), Build `6e16dc5cc7cc`:

- ☑ V1 Die App läuft mit der neuen Version (`data-build` = Build, Service Worker kontrolliert die
  Seite), kein Hinweis „veraltet“, keine Konsolenfehler.
- ☑ V2 `X-Api-Version: 1` an JSON-Antworten (`auth/me`, `devices`, auch 404) und am CSV-Export
  (`devices/export`, im Browser gelesen, nicht gespeichert); `health` per curl ebenso.
- ☑ V3 Anfrage mit `X-Client-Api: 0` → 409 `client_outdated`, `details {min_client_api: 1}`, Meldung
  „Diese Version der App ist veraltet – bitte aktualisieren.“ (unter der Stadt und unter `service`);
  ohne Header und mit `1` wie bisher.
- ☐ V4 (offen, nicht live auslösbar: braucht `ClientApi::MIN` > 1 auf dem Server) Hinweis „Diese
  Version ist veraltet – bitte aktualisieren.“ in der App, sofortiges Update ohne Eingabe, Eingaben
  bleiben, wartende Offline-Buchung geht nach dem Update raus. Abgedeckt durch `appUpdate.spec.ts`
  (Browser, Mail-Link mit `client_outdated` bzw. `X-Api-Version: 2`, in CI in allen fünf Profilen),
  `appUpdate.spec.ts`, `bookingQueue.spec.ts`, `client.spec.ts` (Vitest). Live erst, wenn `MIN`
  zum ersten Mal steigt (Phase 1, Schritt 1.7).
- ☐ V5 (offen für Holger, iPhone) Warteschlange im neuen Format: offline buchen, dann online; die
  Buchung geht wie bisher raus (gespeichert jetzt als `{format: 1, bookings}`; ältere Listen liest
  die App weiter).

## Live-Lauf Offline (Skript, ohne Holger)

`npm run test:live-offline` in `frontend/` (Test-Stadt, zwei vorhandene Konten aus
`frontend/tests/live/.env.local`, nur M90–M99, danach zurückgesetzt; Regeln in CLAUDE.md, „Autonomer
Ablauf“, Schritt 5). Der Agent führt ihn nach jedem Deploy aus, der Offline, Warteschlange, Tausch,
Benutzt melden, Service Worker oder Updates betrifft, und trägt das Ergebnis hier ein.

Einmalig von Holger: Datei `frontend/tests/live/.env.local` anlegen (Vorlage `.env.local.example`
daneben) mit `FF_LIVE_USER1_EMAIL`, `FF_LIVE_USER1_PASSWORD` (Konto #5 „Holger (Test)“) und
`FF_LIVE_USER2_EMAIL`, `FF_LIVE_USER2_PASSWORD` (Konto #7 „Holger Test 1“). Die Datei ist nicht
versioniert.

Punkte des Laufs (Chromium, echtes Offline): C1 Service Worker und Kopie, C2 Neuladen ohne Netz
(Streifen), C3 Suche aus der Kopie, C4 Umbuchen offline, C5 Abmelden mit wartender Buchung (Rückfrage,
„Abbrechen“ behält alles), B8a Kontowechsel mit Warteschlange des anderen Kontos, C6 Senden genau
einmal (Aktionszeit, `client_ref`), C7 Benutzt melden offline, C8 Tausch offline, C9 Senden von Konto 2
genau einmal, C10 „Ersatz inzwischen woanders“ (Hinweis im Ergebnis). WebKit „iPhone 13“: W1–W3 (App-Logik,
IndexedDB, Warteschlange, Senden mit abgebrochenen API-Anfragen; kein echtes Offline, keine Kamera, kein
Flugmodus). G1 meldet Verstöße gegen den Schutz (fremde Adresse, andere Ausrüstung), R1 das Zurücksetzen.

Bleibt für Holger nur bei gemeldeten Problemen: echtes iPhone im Flugmodus (B1–B9 wie oben), Kamera
(B4), Uhr des Handys (B9) und B10 (Konfiguration des Servers).

Läufe:
- 11.11.2026, mehrere Läufe (Chromium Playwright, Chrome-Kanal, nur Konto 1, Konto 2 im eigenen
  Browser, `context.route` statt `setOffline`): ☑ C1 Service Worker und Kopie, ☑ C2 Neuladen ohne Netz,
  ☑ C3 Suche aus der Kopie, ☒ C4 `locator.click: Page crashed` (Umbuchen von M94 offline), alle weiteren
  Punkte ☐ nicht gelaufen (C5–C10, B8a, W1–W3). Beim Absturz stürzen BEIDE Seiten gleichzeitig ab, auch
  in getrennten Browsern; auch mit nur Konto 1, mit installiertem Chrome und mit abgebrochenen
  API-Anfragen statt `setOffline`. Das Browser-Log (`--enable-logging --v=1`) zeigt keine Absturzmeldung.
  Von Hand in Holgers Chrome (fetch für `/api/` abgelehnt) kein Absturz, Buchung kam genau einmal an;
  die 16 Offline-Browser-Tests laufen lokal grün. Ursache offen (Verdacht: Speicher des Rechners,
  Schleife der Seite nach Neuladen ohne Netz); Wiederholung erst nach Klärung. M90–M95 danach
  unverändert (einsatzbereit, Anfangsstandorte); der Hinweis `swap_replacement_moved` im Protokoll
  bleibt für Claude als Admin.
- 11.11.2026, Nachtrag zum Absturz (Ursache geklärt): Es war KEIN Speicherproblem der App und kein Absturz beim
  Speichern. Mit Speichermessung (`FF_LIVE_MEM=1`) blieb die Seite bei 5–32 MB (alle Chromium-Prozesse rund
  280 MB), keine Schleife (Stichproben der Aufrufliste im Leerlauf). Der Klick auf die Karte von M94 in
  `openDevice` wartete ohne Ende, weil das Muster `M94` nie passte (Text der Karte „M94Live-Testtyp 2 …“
  ohne Wortgrenze); nach 10 Minuten (Zeitgrenze des Tests) beendete Playwright den Browser, daher „Page crashed“
  in beiden Browsern gleichzeitig. Behoben mit `cardTextPattern` (Test `memoryGuard.spec.ts`). Der lokale
  Browser-Test `offlineReload.spec.ts` (Neuladen ohne Netz, dann Standort, Status, Benutzt melden offline mit
  Lager und Sammelstelle, Speichergrenze) lief von Anfang an grün.
- 11.11.2026, Lauf 1 nach der Korrektur (beide Konten, WebKit, `FF_LIVE_MEM=1`): ☑ C1–C8, ☒ C9 (Messfehler des
  Skripts: Die Antwort wurde gezählt, bevor sie aufgezeichnet war; kein Fehler der App), ☑ C10, ☑ W1–W3, ☑ R1.
- 11.11.2026, Lauf 2 (Skript korrigiert, `expectSentOnce` wartet auf die Antwort): ☑ C1, ☑ C2, ☑ C3, ☑ C4, ☑ C5,
  ☑ B8a, ☑ C6, ☑ C7, ☑ C8, ☑ C9, ☑ C10, ☑ W1, ☑ W2, ☑ W3, ☑ R1 (alle bestanden). Höchster Seitenspeicher 32 MB,
  Schutz nicht ausgelöst. M90–M95 danach auf dem Anfangsstand.
- Weitere Diagnose-Schalter: `FF_LIVE_MEM=1` (Speichermessung und Schutz, `test-results/live-memory.txt`).
- Diagnose-Schalter des Skripts (Umgebungsvariablen): `FF_LIVE_DIAG=1` (Abstürze, Skriptfehler und
  Fehlermeldungen der Seiten), `FF_LIVE_NET=route` (API-Anfragen abbrechen statt `setOffline`),
  `FF_LIVE_CHANNEL=chrome` (installiertes Chrome), `FF_LIVE_ONE=1` (nur Konto 1, Lauf endet nach C6),
  `FF_LIVE_LOGFILE=<Pfad>` (Browser-Log, danach löschen). Konto 2 läuft immer in einem eigenen Browser.
