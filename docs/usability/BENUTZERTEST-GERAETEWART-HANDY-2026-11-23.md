# Benutzertest mit KI-Personas – Gerätewart am Handy (23.11.2026)

Auftrag Holger, 23.11.2026: „Jetzt noch den Gerätewart auf dem Handy durch Persona-Agent prüfen und verbessern lassen.“
Stand `main` `352c016` (nach den Verbesserungen A1–A5 des Admin-Tests), lokal gebaut, echtes Backend gegen die Testdatenbank
mit erfundenen Daten (Feuerwehr Musterstadt). Werkzeug und die acht Aufgaben des Gerätewarts wie in
`frontend/tests/usability/PERSONAS.md`; anders als am 12.11.2026 alle Aufgaben **nur am Handy**. Die drei Personas liefen
nacheinander, jede mit frisch zurückgesetzten Daten.

Einzelberichte, Protokolle der Treiberbefehle und Bildschirmfotos: [geraetewart-handy-2026-11-23/](geraetewart-handy-2026-11-23/).

| Persona | Gerät |
|---|---|
| jung (Jonas) | iPhone 390 × 664 px |
| mittel (Sabine) | Pixel 7 412 × 839 px |
| älter (Werner) | iPhone, Schrift 125 % |

## Kurzfassung

Sieben der acht Aufgaben haben alle drei gelöst, meist mit Einfachheit 5–6. **Aufgabe 8 (Wartungsdatum aller 20 Funkgeräte)
scheiterte bei allen drei:** In der Kartenansicht der Ausrüstungsliste gab es kein „Alle auswählen“, und die Leiste mit
„Wartungsdatum setzen …“ scrollte beim Ankreuzen aus dem Bild. Die übrigen Hürden: Die Kopfleiste mit dem Menü scrollt
mit weg; die Transportliste ließ sich nicht weitergeben (Zwischenablage im Testbrowser gesperrt, kein anderer Weg);
Standortnamen wurden neben Chip und Knöpfen Buchstabe für Buchstabe umbrochen; ein Rückgabedatum in der Vergangenheit
nahm das Fenster „Verleihen“ ohne Hinweis an.

## Matrix Persona × Aufgabe (Ergebnis / Einfachheit 1–7)

`ja` = ohne Umweg, `um` = gelöst mit Umweg, `nein` = nicht gelöst.

| Aufgabe | jung | mittel | älter |
|---|---|---|---|
| 1 Sammelstelle → Werkstatt, Liste für den Fahrer | um / 5 | um / 4 | um / 4 |
| 2 Gewartetes zurück ins Lager | um / 5 | um / 5 | ja / 6 |
| 3 Masken M41–M45 anlegen | um / 6 | ja / 6 | um / 5 |
| 4 Sollbestand LF 10 | ja / 6 | ja / 6 | um / 4 |
| 5 HRT 3 und HRT 4 verleihen | ja / 6 | ja / 6 | ja / 5 |
| 6 Rückgabe bearbeiten | ja / 6 | ja / 6 | ja / 6 |
| 7 Hinweis im Protokoll erledigen | ja / 5 | ja / 6 | um / 5 |
| 8 Wartungsdatum aller Funkgeräte | **nein / 2** | **nein / 2** | **nein / 2** |

## Probleme (zusammengeführt, nach Wirkung)

| # | Problem | Betroffen | Schwere |
|---|---|---|---|
| G1 | **Kein „Alle auswählen“ in der Kartenansicht** der Ausrüstungsliste (der Tabellenkopf mit dem Kästchen fehlt dort); ebenso in der Benutzerliste. | alle drei | 4 |
| G2 | **Leiste „N ausgewählt“ scrollt weg** (Ausrüstung, Wartung); beim Ankreuzen weiter unten ist „Wartungsdatum setzen …“ aus dem Bild. | alle drei | 3 |
| G3 | **Kopfleiste mit ☰ scrollt mit weg**; auf langen Seiten erst nach oben wischen, um die Seite zu wechseln. | jung, mittel | 3 |
| G4 | **Transportliste nicht weiterzugeben:** „Die Liste ließ sich nicht kopieren.“, kein Teilen, keine Anzeige (im Testbrowser war die Zwischenablage gesperrt; am echten Handy kann das ebenso vorkommen). | alle drei | 3 |
| G5 | **Standortnamen senkrecht zerhackt** („At/e/ms/ch…“), weil Chip und zwei Knöpfe die Zeile füllen. | alle drei | 3 |
| G6 | **„Rückgabe bis“ in der Vergangenheit ohne Hinweis** (Tippfehler 20.11. statt 20.12.; sofort „überfällig“). | alle drei | 2 |
| G7 | Fenster „Verleihen“: „Hinzufügen“ und „Entfernen“ rechts abgeschnitten (125 % Schrift). | älter | 2 |
| G8 | Sollbestand: Regeln ohne Überschrift; Zahlenfeld „Mindestens“ änderte sich beim Wischen (im Testbrowser per Mausrad). | älter | 2–3 |
| G9 | Werkstatt: frisch Gebrachtes und fertig Gewartetes nicht zu unterscheiden (alles „seit gerade eben“). | alle drei | 3 |
| G10 | Erfolgsmeldungen oben, während man unten steht (Wartung, Verleih, Protokoll). | jung, älter | 2 |
| G11 | Hinweis: Satz nach „Erledigen“ („nichts weiter zu tun … neueren Stand behalten“) passt nicht mehr, wenn man vorher korrigiert hat. | alle drei | 2 |
| G12 | Neue Ausrüstung: „Speichern und nächstes“ erst nach mehrmaligem Wischen; die Meldung nennt nur die letzte Nummer. | jung, älter | 2 |
| G13 | „In der Rückgabe“ zeigt nicht Name und Kommentar des Boten (nur im Dashboard). | alle drei | 2 |
| G14 | Kleinigkeiten: Kopf „Sammelstelle 3 Sammelstelle“, „2 von 4 Atemschutzmaske“ (Einzahl), Wort „Stufe“, Standortauswahl lang ohne Suche, kein Kamera-Scan beim Verleihen, Filter füllen den ersten Bildschirm. | einzeln | 1–2 |

## Was gut funktioniert hat

- Wartung: „Alle auswählen“ lässt Defektes in der Werkstatt aus, die Rückfrage nennt Ziel und Status.
- Sollbestand: Der Satz zu mehreren Typen führte richtig zur zweiten Regel; nach dem Speichern steht in Worten, dass LF 10
  außer Betrieb ist.
- Verleihen und Rückgabe: Nummer + Eingabetaste fügt hinzu; Sprungknopf „In der Rückgabe (1)“.
- Neue Ausrüstung: Typ und Standort bleiben, die Nummer zählt weiter.
- Schrift und Kontrast auch mit 125 % gut; Menü mit Symbol und Text.

## Umgesetzt (23.11.2026, gleiche Runde)

- **G1** „Alle angezeigten Ausrüstungen auswählen“ bzw. „… Konten auswählen“ über den Karten (nur in der Kartenansicht).
- **G2** Die Leiste „N ausgewählt“ (Ausrüstung, Benutzer) und die Knöpfe einer Stufe der Wartung bleiben beim Scrollen
  sichtbar (unter der Kopfleiste).
- **G3** Die Kopfleiste mit ☰ steht am Handy fest oben.
- **G4** Wartung: „Transportliste teilen …“ (Teilen-Dienst des Handys, nur wo es ihn gibt); scheitert das Kopieren, steht die
  Liste als Text zum Markieren da.
- **G5** Standorte: Chips stehen unter dem Namen, Namen brechen nur zwischen Wörtern bzw. wenn nötig.
- **G6** Verleihen: Hinweis „Dieses Datum liegt in der Vergangenheit.“ unter „Rückgabe bis“ (kein Fehler).

Offen (T29): G7–G14.

## Grenzen

Wie am 12.11.2026: KI-Personas statt Menschen, Chromium mit Handy-Emulation statt Safari bzw. Chrome am Gerät, je Persona ein
Durchgang. Die Zwischenablage war im Testbrowser gesperrt (G4); das Mausrad im Zahlenfeld (G8) gibt es am Handy so nicht.

## Wiederholung

Server `php -q -S localhost:4300 frontend/tests/usability/support/router.php`, vor jeder Persona
`node frontend/tests/usability/reset.mjs`, dann
`node frontend/tests/usability/driver.mjs --persona keeper-handy-<gruppe> --role keeper --device <iphone|pixel> [--zoom 1.25] --port <p> --out <ordner>`.
