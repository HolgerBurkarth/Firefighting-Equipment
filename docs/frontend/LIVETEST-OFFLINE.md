# Live-Test des Offline-Modus

Offline-Modus (KONZEPT.md, "Offline-Modus – Entscheidungen", 48–56) nur in der Test-Stadt
`https://www.prodad.de/firefighting/test/`, mit Testgeräten M90–M99 (Werte danach zurücksetzen).
Echtes Offline (Flugmodus) kann Claude im Browser nicht prüfen; diese Punkte macht Holger am iPhone.
Ergebnisse bitte mit der Nummer eintragen (☑ bzw. ☒ mit Fund).

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

## B Offline am iPhone (Holger)
- ☐ B1 Flugmodus an, App öffnen bzw. neu laden: grauer Streifen „Offline – angezeigt wird der Stand
  vom …“, keine Anmeldeseite; „Suchen“ findet Geräte, „Prüffristen“ zeigt die Listen.
- ☐ B2 Offline M90 öffnen, Status „in Wartung“, Speichern: Hinweis „Gespeichert – wird gesendet,
  sobald eine Verbindung besteht: M90 → in Wartung“; auf „Suchen“ die Warteschlange mit Uhr; M90
  zeigt in der Suche „in Wartung“.
- ☐ B3 Offline „Anderen Standort suchen …“: die Standorte erscheinen (aus der Kopie); Umbuchen auf
  ein Fach, Speichern → zweite Buchung in der Warteschlange.
- ☐ B4 Offline „Code scannen“: Kamera und Lesemodul funktionieren ohne Netz (Modul im Cache).
- ☐ B5 Flugmodus aus: Die Buchungen gehen von selbst raus (Haken, „Die Buchung wurde übernommen.“);
  am PC zeigt das Gerätedetail von M90 den neuen Stand, der Verlauf die Aktionszeit vom Handy und
  „Eingang beim Server“. „Angekommene ausblenden“ entfernt sie.
- ☐ B6 Konflikt: offline M90 auf „defekt“ (mit Kommentar) buchen, währenddessen am PC M90 auf
  „einsatzbereit“ setzen; dann Flugmodus aus. Ergebnis in der App gelb (nur im Verlauf bzw.
  überschrieben, je nach Reihenfolge der Zeiten), im Protokoll der Admins ein Hinweis, Mail „Log“ an
  die Empfänger.
- ☐ B7 Abmelden mit wartender Buchung (Flugmodus an, buchen, dann Flugmodus aus, aber bevor sie
  rausgeht – oder Server-Störung): Rückfrage „1 Buchung wurde noch nicht gesendet …“; „Abbrechen“
  behält alles. (Abmelden selbst geht nur online.)
- ☐ B8 Nach dem Abmelden und Anmelden mit einem anderen Konto: keine Geräte des alten Kontos
  sichtbar, bevor die Liste geladen ist; keine fremde Warteschlange.
- ☐ B9 Uhr des Handys 10 Minuten vorstellen, offline buchen, Flugmodus aus: Meldung „Die Uhr dieses
  Geräts geht vor …“ mit „Verwerfen“. Uhr zurückstellen.
- ☐ B10 Grenzen: Mit `offline.queue_max_age_days` = 1 auf dem Server (nur, wenn Holger die
  Konfiguration dafür ändern möchte) eine Buchung über einen Tag offline liegen lassen → „Trotzdem
  senden“ bzw. „Verwerfen“. Sonst als offen markieren.
