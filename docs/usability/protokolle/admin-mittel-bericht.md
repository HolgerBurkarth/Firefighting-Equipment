# Bericht Admin (Kommandant, mittel 30–49) – Frank

**Kurzprofil:** Frank, Kommandant, Büro-PC-Routine, Laptop 1366×768, für die Freischaltung einmal Handy (Pixel). Anleitung überflogen (4 Seiten, sie behandelt nur Dashboard/Ausrüstung, nicht Benutzer/Einladungen/Einstellungen). Vorgehen: Menü und Überschriften lesen, Knopftexte anklicken.

| Aufgabe | Ergebnis | Aktionen (gebraucht / kürzester Weg) | Einfachheit | Zitat |
|---|---|---|---|---|
| 1 Erika freischalten (Handy) | ja | 5 / 4 | 6 | „Der Link ‚Freischaltungen ansehen‘ auf dem Dashboard hat mich direkt hingebracht, gut.“ |
| 2 Einladungscode + Aushang | ja | 7 / 6 | 6 | „Code, QR und ‚Aushang drucken‘ stehen alle zusammen, das ist klar.“ |
| 3 Karl → Gerätewart | ja | 5 / 5 | 6 | „Stift in der Zeile, Rolle anklicken, speichern – wie im Büro.“ |
| 4 Otto entfernen | umweg | 15 / 5 | 5 | „Warum muss ich zum Löschen ‚Bearbeiten‘ öffnen und dann ganz nach unten?“ |
| 5 Logo und Farbe | ja | 4 / 4 | 6 | „Hex-Wert tippen ist okay, ein Farbfeld wäre mir lieber.“ |
| 6 Rückgabe-Aushang erneuern | ja | 5 / 4 | 6 | „Gut, dass es vorher fragt und sagt, dass der alte Link ungültig wird.“ |

## Probleme
- **P1 (Schwere 3)** Benutzer → Bearbeiten-Fenster am Laptop (768 px hoch): Das Fenster ist höher als der Bildschirm; „Otto löschen …/sperren …“ liegen ganz unten und sind nur durch Scrollen/Tabben erreichbar. Mein Scrollen (`scroll down`) bewegte das Fenster nicht, ich kam nur per Tab-Taste hin (≈10 Aktionen Umweg). Foto: `../bilder/admin-mittel-04-a4-dialog.png`. Vorschlag: „Löschen …/Sperren …“ als sichtbare Knöpfe in der Zeile bzw. Fenster scrollbar mit fester Fußleiste (Abbrechen/Speichern/Löschen immer sichtbar).
- **P2 (Schwere 2)** Bearbeiten-Fenster enthält den Block „Claude-Zugang: Aus/Lesen/Ändern“ ohne Erklärung für Laien; verlängert das Fenster (verstärkt P1). Vorschlag: einklappen oder nur zeigen, wenn die Stadt es nutzt, Erklärtext „Claude ist ein KI-Assistent …“.
- **P3 (Schwere 2)** Einladungscode: Voreinstellung der Rolle ist „Benutzer“, nicht „Geräteträger“; der Admin merkt nicht, dass dies nach Freischaltung nur ein Vorschlag ist. Der angezeigte Link beginnt (im Test) mit localhost – Testumgebung, nicht bewertet.
- **P4 (Schwere 1)** Freischalt-Fenster (Handy): Auswahlpunkte der Rolle 20×20 px, die ganze Karte ist aber antippbar – ok; das Anzeigename-Feld ist beim Öffnen komplett markiert, das ist ungewohnt (Gefahr, beim Tippen den Namen zu überschreiben). Foto: `../bilder/admin-mittel-01-a1-dialog.png`.
- **P5 (Schwere 1)** „Aushang drucken“: Druckdialog im Test nicht bedienbar; ich konnte nicht prüfen, was erscheint. Der Hinweis „Der Code erscheint nur jetzt“ ist aber deutlich.
- **P6 (Schwere 1)** Logo: Dateiauswahl nicht ausführbar (keine Datei); Weg „Logo hochladen …“ und Hinweis (PNG/JPG/SVG, 512×512) sind klar. Die Anleitung erklärt Aufgaben 1–6 (außer Dashboard/Ausrüstung) nicht.

## Was gut funktioniert hat
- Dashboard-Link „Freischaltungen ansehen“ führt direkt zur wartenden Person; Bestätigung „Erika Probe ist freigeschaltet (Geräteträger) …“.
- Rollenkarten mit Kurzbeschreibung „Sieht: …“; Rückfragen beim Löschen („Gelöschter Benutzer #…, nicht rückgängig“) und Aushang erneuern sind verständlich.
- Rolle ändern speichert sofort mit klarer Meldung. Farbe speichern mit Bestätigung „gilt für alle Benutzer“.

## Unklare Begriffe
- „Claude-Zugang“ → keine Ahnung, wofür.
- „Aushang erneuern/Widerrufen“ → erneuern = alter Link tot, widerrufen = Aushang abschalten (vermutet).
- „Akzentfarbe“ → Farbe der Kopfleiste (vermutet).
- „Sperren“ vs. „Löschen“ → sperren = vorübergehend (vermutet).
