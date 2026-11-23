# Benutzertest mit KI-Personas – Admin (23.11.2026)

Auftrag Holger, 23.11.2026: „Der Persona-Agent soll die App als Admin bewerten.“ Nur testen und berichten, am App-Code nichts
geändert. Stand `main` `998d609` (mit der erweiterten Admin-Anleitung), lokal gebaut, echtes Backend gegen die Testdatenbank
mit erfundenen Daten (Feuerwehr Musterstadt). Werkzeug, Anweisung und Aufgaben wie beim Test vom 12.11.2026
(`frontend/tests/usability/`, Abschnitt „Admin“ in `PERSONAS.md`). Anders als damals liefen die drei Personas **nacheinander**,
jede mit frisch zurückgesetzten Daten (keine Störungen durch parallele Personas).

Einzelberichte, Protokolle der Treiberbefehle und Bildschirmfotos: [admin-2026-11-23/](admin-2026-11-23/).

| Persona | Gerät |
|---|---|
| jung (Sarah, Schriftführerin) | PC 1920 × 1080, Aufgabe 1 am Handy (Pixel 7) |
| mittel (Frank, Kommandant) | Laptop 1366 × 768, Aufgabe 1 am Handy |
| älter (Helmut, Kommandant) | Laptop mit 125 % Zoom, Aufgabe 1 am Handy |

## Kurzfassung

Alle drei Personas haben alle sechs Aufgaben gelöst, mit Einfachheit 5–7. Gegenüber dem 12.11. ist das Bild gleich bis etwas
besser (Freischalten und Gerätewart einmal 7). Die Hürden liegen in der **Benutzerverwaltung**: Ein Klick auf den Namen tut
nichts (nur der unbeschriftete Stift öffnet), und „Konto beenden / löschen“ liegt im Fenster „Bearbeiten“ unter dem sichtbaren
Bereich. Dazu kommen die **Wahl der Rolle** beim Freischalten („Benutzer“ vorgewählt, Beschreibung „Wie bisher; bekommt jede
neue Stadt“ unverständlich) und die fehlende **Vorschau des Einladungs-Aushangs** vor dem Drucken. Die erweiterte
Admin-Anleitung deckt laut der älteren Persona die Aufgaben 1–5 gut ab, den Rückgabe-Aushang (Aufgabe 6) nicht.

## Matrix Persona × Aufgabe (Ergebnis / Einfachheit 1–7)

`ja` = ohne Umweg, `um` = gelöst mit Umweg. In Klammern der Wert vom 12.11.2026.

| Aufgabe | jung | mittel | älter |
|---|---|---|---|
| 1 Erika freischalten, Rolle Geräteträger (Handy) | ja / 7 (6) | ja / 7 (6) | ja / 6 (6) |
| 2 Einladungscode und Aushang | ja / 6 (6) | ja / 6 (6) | ja / 6 (6) |
| 3 Karl Alt wird Gerätewart | um / 6 (6) | ja / 7 (6) | um / 6 (5) |
| 4 Otto Austritt entfernen | ja / 7 (6) | um / 5 (5) | um / 5 (5) |
| 5 Logo (nur Weg) und Farbe | um / 6 (5) | ja / 6 (6) | ja / 6 (5) |
| 6 Rückgabe-Aushang erneuern | ja / 6 (6) | ja / 6 (6) | ja / 6 (5) |

## Probleme (zusammengeführt, nach Wirkung)

| # | Problem | Betroffen | Schwere | Einschätzung |
|---|---|---|---|---|
| A1 | **Löschen versteckt:** „Konto beenden / … löschen (ausgetreten)“ steht im Fenster „Bearbeiten“ unter „Speichern“, außerhalb des sichtbaren Bereichs; in der Liste gibt es keinen Knopf dafür. Die ältere Persona brauchte 13 statt 3–4 Aktionen (erst 7× Tab). | mittel, älter | 3 | Teilweise Treiber: Das Mausrad scrollte das Fenster erst nach einem Klick hinein (schon am 12.11. beobachtet; Playwright-Test mit Mausrad grün). Unabhängig davon liegt der Abschnitt erst nach Scrollen. |
| A2 | **Name in der Benutzerliste nicht anklickbar:** Klick auf Name oder Zeile tut nichts, nur der kleine Stift ohne Beschriftung ganz rechts öffnet. | jung, älter | 2 | Bewusst so (CLAUDE.md: Benutzerliste ohne `v-row-link`). Zwei von drei Personas erwarteten es wie in den anderen Listen. |
| A3 | **Rolle beim Freischalten:** „Benutzer“ ist vorgewählt; „Benutzer“ und „Geräteträger“ sind schwer zu unterscheiden (gleiche Zeile „Sieht: …“); die Beschreibung der Standard-Rolle „Wie bisher; bekommt jede neue Stadt“ versteht ein Admin nicht („welche Stadt denn?“); „die Rolle ist vorausgewählt“ fordert nicht zum Prüfen auf. | alle drei | 2 | Die Beschreibung stammt aus `DefaultRoles` (gedacht für den Super-Admin). Vorgewählt ist die Rolle des Einladungscodes. |
| A4 | **Keine Vorschau des Einladungs-Aushangs** vor dem Drucken; nach „Aushang drucken“ ändert sich die Seite sichtbar nicht. | alle drei | 2 | Druckdialog im Test nicht bedienbar; die Vorschau fehlt aber auch im echten Browser (nur der Druckdialog des Browsers). |
| A5 | **Seite verrutscht nach „Farbe speichern“:** Die Knöpfe verschwinden, die grüne Meldung schiebt den Inhalt; der nächste Klick traf „Passwort ändern“ statt „Logo hochladen …“. | jung | 2 | |
| A6 | **Rückgabe-Aushang:** viel Text mit ähnlichen Begriffen (Rückgabe, Rückgabestelle, Rückgabe-Regal), unruhige Anordnung; nach „Neuen Aushang erzeugen“ keine ausdrückliche Bestätigung, dass der alte nicht mehr gilt (die Rückfrage vorher sagt es klar). | alle drei | 1–2 | „Rückgabe-Regal“ ist ein Name aus den Testdaten. |
| A7 | **Knopf „Speichern“ in der Vorschau des Erscheinungsbilds** sieht echt aus. | alle drei | 1 | |
| A8 | **Gesperrter Knopf „In Ordnung ins Lager …“ (Verleih) sieht bedienbar aus.** | jung, älter | 1–2 | |
| A9 | Link „Freischaltungen ansehen“ am Handy erst nach Scrollen; Hinweis „Handys laden dabei ihre Offline-Kopie neu“ unverständlich; E-Mail-Spalte bricht Adressen mitten im Wort um. | einzeln | 1 | |

**Kein Fehler der App:** Der Code „Dienstabend November“ zeigt „0 von 50 Registrierungen“, obwohl Erika sich laut ihrer Karte
damit registriert hat (von allen drei bemerkt). Das Testskript `seed.php` legt Erika direkt in der Datenbank an, ohne den
Zähler des Codes zu erhöhen. In der echten Registrierung zählt `UserRepository::register` mit.

## Was gut funktioniert hat

- Freischalten am Handy: über „Freischaltungen ansehen“ bzw. Menü → Benutzer in 5 Aktionen, Einfachheit 6–7.
- Einladungscode: Code, QR-Code, „Aushang drucken“, „Link teilen“ stehen zusammen; der Satz, dass man bei Verlust einfach einen
  neuen Code anlegt, beruhigt.
- „… löschen (ausgetreten)“ mit klarer Rückfrage, sobald gefunden.
- Farbe: Hex-Wert eintippen, Vorschau sofort, Kontrasthinweis verständlich, Bestätigung „gilt ab jetzt für alle“.
- Rückgabe-Aushang erneuern: Sprungknopf auf „Verleih“, Rückfrage „Der bisherige Aushang gilt danach nicht mehr“.
- Admin-Anleitung (erweitert am 23.11.): deckt Freischalten, Einladung, Benutzer bearbeiten und Einstellungen ab.

## Umgesetzt (23.11.2026, gleiche Runde)

- **A2** Benutzerliste: Ein Klick in die Zeile bzw. Karte öffnet „Bearbeiten“ (`v-row-link`), der Stift heißt „Bearbeiten“.
- **A1** Fenster „Bearbeiten“: oben der Knopf „Sperren oder löschen (z. B. bei Austritt) ↓“, er scrollt zu „Konto beenden“.
- **A3** Freischalten: Satz „Vorgewählt ist „…“, die Rolle des Aushangs „…“. Bitte prüfen Sie, ob sie passt.“; je Rolle „Darf: …“
  neben „Sieht: …“ (auch bei den Einladungscodes); die Standard-Rollen zeigen statt „Wie bisher; bekommt jede neue Stadt“ bzw.
  „Alle Rechte, nicht änderbar“ einen Satz für Admins.
- **A4** Einladungscode: aufklappbar „Vorschau des Aushangs (A4)“ unter den Knöpfen.
- **A5** Einstellungen: Die Meldung nach „Farbe speichern“ steht an der Stelle der Knöpfe, gleich hoch; „Logo hochladen …“ bleibt, wo
  es war.

Offen (T27): A6–A9 und die Vorschläge 5 (zweiter Teil), 6 und 7.

## Vorschläge (Stand des Tests; 1–4 und 5 erster Teil umgesetzt, siehe oben)

1. Benutzerliste: Klick auf Zeile bzw. Name öffnet „Bearbeiten“ (wie `v-row-link` der anderen Listen); Stift mit Text „Bearbeiten“.
2. Fenster „Bearbeiten“: „Konto beenden“ (Sperren, Löschen) sichtbar ohne Scrollen, z. B. als eigener Knopf in der Zeile oder
   oben im Fenster; Rest wie U7.
3. Rollen beim Freischalten: Kurzbeschreibung der Standard-Rolle für Admins verständlich („Suchen, Umbuchen, Status melden“);
   „Sieht: … / Darf: …“ so, dass sich Benutzer und Geräteträger unterscheiden; Satz „Rolle aus dem Einladungscode – bitte
   prüfen“.
4. Einladungs-Aushang: Vorschau (verkleinertes A4) vor „Aushang drucken“.
5. Einstellungen: nach „Farbe speichern“ Platz der Knöpfe halten (Meldung ohne Verschieben); „Speichern“ in der Vorschau als
   Attrappe kennzeichnen oder entfernen.
6. Rückgabe-Aushang: Text kürzen, nach dem Erneuern Meldung „Neuer Aushang erzeugt – der alte gilt nicht mehr“; Anleitung um
   den Rückgabe-Aushang ergänzen.
7. Testwerkzeug: `seed.php` erhöht `use_count` des Codes für Erika.

## Grenzen

Wie am 12.11.2026 (KI-Personas statt Menschen, Chromium statt Safari, kein Druckdialog, keine Dateiauswahl, je Persona ein
Durchgang). Das Mausrad im Fenster ist eine bekannte Grenze des Treibers (A1).

## Wiederholung

Server `php -q -S localhost:4300 frontend/tests/usability/support/router.php`, vor jeder Persona
`node frontend/tests/usability/reset.mjs`, dann
`node frontend/tests/usability/driver.mjs --persona admin-<gruppe> --role admin --device <desktop|laptop> [--zoom 1.25] --port <p> --out <ordner>`
(alles im Hauptordner des Projekts mit `vendor/`, `frontend/node_modules` und `config/config.test.php`).
