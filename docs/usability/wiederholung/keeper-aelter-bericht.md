# Bericht keeper-aelter (Gerätewart, älter, Laptop 1093x614 px, Zoom 125 %)

## 1. Kurzprofil
Gerätewart um die 60, kennt Wartung und Ausrüstung, bisher Excel. Anleitung einmal gelesen (sie behandelt fast nur das Handy/Geräteträger, für Wartung, Sollbestand, Rückgabe und Protokoll steht dort nichts). Ich habe nur die Aufgaben 1, 2, 4, 6, 7 bearbeitet; Aufgabe 5 habe ich versehentlich angefangen und verworfen (kein Ergebnis). Bildschirmfotos: `docs/usability/wiederholung/shots/keeper-aelter-*.png`.

## 2. Aufgaben
| Aufgabe | Ergebnis | Aktionen (gebraucht / kürzester Weg) | Einfachheit | Zitat |
|---|---|---|---|---|
| 1 Sammelstelle in Werkstatt + Transportliste | umweg (Liste nicht erhalten) | 6 / 4 | 4 | „Und wie komme ich jetzt an meine Liste für den Fahrer?“ |
| 2 Gewartetes zurück ins Lager | ja | 5 / 4 | 6 | „Gut, dass er mir sagt, warum PA 11 nicht mitkommt – aber wohin dann?“ |
| 4 Sollbestand LF 10 (4 Masken, 4 Atemschutzgeräte) | ja | 17 / ca. 10 | 5 | „Aha, die Meldung sagt mir gleich, dass mir Masken fehlen.“ |
| 6 Rückgabe bearbeiten | ja | 8 / 6 | 6 | „Wo ist denn jetzt meine Bestätigung? Ach, ganz oben.“ |
| 7 Protokoll-Hinweis erledigen | ja | 6 / 5 | 6 | „Muss ich jetzt noch was an der Kamera machen? Erst danach steht es da.“ |

## 3. Probleme

**P1 Transportliste kopieren (Wartung, Sammelstelle/Werkstatt)** – Schweregrad 3.
Klick auf „Transportliste kopieren“ ergab rot „Die Liste ließ sich nicht kopieren.“ (vermutlich Zwischenablage im Testbrowser gesperrt; der Erfolgsfall ließ sich deshalb NICHT prüfen). Beobachtet trotzdem: (a) Die Fehlermeldung steht im Dialog „In die Werkstatt bringen“ UND hinter dem Dialog doppelt, ohne Hinweis, was man stattdessen tun kann. (b) Ohne Auswahl ist unklar, ob die ganze Stufe oder nichts kopiert wird (Knopf ist immer aktiv, „(3)“ fehlt wie beim Hauptknopf). (c) Die Reihenfolge ist gut erklärt: Hinweistext „erst Transportliste kopieren, dann In die Werkstatt bringen“ und im Bestätigungsdialog „Transportliste vorher kopieren?“ – das half sehr. Ein Fahrer will aber ein Blatt, nicht die Zwischenablage. Fotos: `-01-t1-kopiert`, `-03-t1-dialogkopie`.
Vorschlag: Bei Fehler Liste zum Markieren/Abschreiben in einem Textfeld bzw. Druckansicht anzeigen; Knopf „Liste kopieren (3)“ mit Zahl; Erfolg mit „Liste kopiert (3 Zeilen)“ und Sortierung nennen; Fehlermeldung nur einmal.

**P2 Wartung: Knopftexte und „Alle auswählen“ bei defekter Ausrüstung** – Schweregrad 2.
Positiv: „Alle auswählen“ lässt das defekte PA 11 aus (Kästchen leer) mit rotem Satz „defekt – bleibt in der Werkstatt“, Knopf zeigt die Zahl „Ins Lager bringen … (8)“, Dialog nennt Ziel und Status. Schwach: Der Satz sagt nicht, wie es mit PA 11 weitergeht (reparieren? aussondern? in Werkstatt lassen). Die Knöpfe „In die Werkstatt bringen …“ und „Ins Lager bringen …“ sind verständlich, sind aber ausgegraut („gesperrt“) ohne Erklärung, bis man etwas ankreuzt; und die Meldung „Zur Werkstatt: 3 Ausrüstungen geändert.“ blieb als alte Meldung stehen, bis die neue kam. Zusätzlich lag ein defektes M5 unerwartet in der Sammelstelle (Folge der Daten, aber der Knopf „In die Werkstatt bringen“ würde es mitnehmen – unklar, ob defekte dorthin sollen). Foto: `-05-t2-auswahl`, `-06-t2-dialog`.
Vorschlag: Zusatz „… – bitte in der Ausrüstungsliste auf „In Wartung“ setzen oder aussondern“; Hinweis neben gesperrtem Knopf „Zuerst Ausrüstung ankreuzen“.

**P3 Sollbestand (Standorte)** – Schweregrad 2.
Positiv: Der Sprunglink „Sollbestand ↓“ oben auf „Standorte“ war sofort sichtbar und führte an die Stelle (ich habe danach unnötig weitergescrollt). Der Hinweis „Mehrere Typen werden zusammen gezählt. Für getrennte Zahlen legen Sie eine zweite Regel an.“ verhinderte genau den Fehler, den ich sonst gemacht hätte; zwei Regeln (je 4) waren dadurch klar. Die Meldung nach dem Speichern ist deutlich und hilfreich: „Sollbestand von „LF 10“ gespeichert. „LF 10“ hat zurzeit nur 2 von 4 Atemschutzmaske und 3 von 4 Atemschutzgerät (einsatzbereit) und gilt deshalb als außer Betrieb.“ Schwach: (a) Die Standortliste im Fenster ist lang (rund 23 Einträge), Fächer („LF 10 / G1“) stehen gleichrangig zwischen Fahrzeugen; man muss scrollen (Scrollen mit der Maus im offenen Auswahlfeld wirkte im Test nicht, Taste Ende ging), keine Suche. Ich habe zuerst „LF 10“ gewählt, was richtig war, aber bei „Fahrzeug zählt die Fächer mit“ (Fenstertext) bleibt offen, ob „LF 10“ allein reicht – ja, der Text sagt es. (b) „Mindestens“ steht über einem Zahlenfeld mit Vorbelegung 1; Tippen ersetzte den Wert, gut. (c) Die erste Seite „Standorte“ zeigt den Sollbestand ganz unten, den Menüpunkt dafür findet man nur über den Sprunglink; ohne ihn würde ich ihn bei „Standorte“ nicht vermuten (der Bereich „Außer Betrieb“ im Dashboard weist darauf hin). Fotos: `-07-t4-sprung`, `-09-t4-fenster`, `-10-t4-liste`, `-12-t4-regel1`, `-14-t4-gespeichert`.
Vorschlag: Fächer eingerückt/gruppiert im Auswahlfeld zeigen (wie beim Standort-Wahlfenster), Suchfeld oder Tippen zum Springen; nach dem Speichern grün/orange Meldung mit Warnsymbol (Farbe nicht geprüft).

**P4 Rückgabe bearbeiten (Verleih, „In der Rückgabe“)** – Schweregrad 2.
Klar: zwei getrennte Knöpfe „In Ordnung ins Lager …“ und „Benutzte und Defekte in die Sammelstelle …“, Dialoge nennen das Ziel. Schwach: Im Dialog steht „Standard-Lager“ (statt „Lager Gerätehaus“, wie es sonst heißt) und „Benutzte kommen in die Sammelstelle für Benutzte, defekte in die für Defekte; der Status bleibt“ – sperrig. Der Bestätigungsknopf „Bringen“ ist in den ersten Sekunden ausgegraut (Schutz vor Doppeltipp), das wirkt auf ältere Personen wie „gesperrt“. Die grüne Erfolgsmeldung steht ganz oben auf der Seite; ich musste hochscrollen, um sie zu sehen. Beides getrennt gleichzeitig auswählen geht nicht (zuerst „Gutes“ ankreuzen, dann „Benutzte“): richtig, aber nicht erklärt. Fotos: `-16-t6-dialog`, `-17-t6-dialog2`, `-18-t6-ergebnis`.
Vorschlag: „Lager Gerätehaus (Standard-Lager)“; Erfolgsmeldung am Knopf/Listenkopf anzeigen oder die Seite nach oben scrollen; Hinweis „Zuerst die einwandfreien ankreuzen“.

**P5 Protokoll-Hinweis (Klartext, was nach „Erledigen“ zu tun ist)** – Schweregrad 2.
Positiv: Der Titel „Buchung kam zu spät – Stand nicht geändert“ und der Erklärsatz („…kam erst an, nachdem die Ausrüstung später noch einmal geändert worden war. Sie steht nur im Verlauf; die Ausrüstung behielt den neueren Stand.“) sind Klartext und verständlich. Nach „Erledigen“ steht klar: „Der Hinweis ist erledigt. Es ist nichts weiter zu tun: Die Ausrüstung hat den neueren Stand behalten.“ Schwach: Diese Antwort „nichts weiter zu tun“ erscheint erst NACH dem Erledigen; vorher fehlt eine Empfehlung (z. B. „Prüfen Sie, ob WBK 3 wirklich defekt ist; wenn ja, über „Umbuchen / Status ändern“ setzen“). Die Seite ist lang (Buchung, Stand beim Eingang, Ausrüstung jetzt doppelt mit Standort/Status), „Erledigen …“ liegt erst nach Scrollen. Fotos: `-19-t7-hinweis`, `-20-t7-unten`, `-21-t7-erledigen`, `-22-t7-fertig`.
Vorschlag: Empfehlung je Hinweisart schon vor dem Erledigen, Knopf „Erledigen …“ oben neben dem Titel.

## 4. Was gut funktioniert hat
- Wartung: Kästchen mit „Alle auswählen“, Zahl im Knopf, Dialog mit Ziel und Status, klare Erfolgsmeldungen mit Anzahl.
- Schrift und Kontrast bei 125 % gut lesbar, Knöpfe groß genug (nur Links der Nummern 22 px hoch, Kästchen 24 px, klein).
- Sollbestand: Sprunglink, Erklärtext zu „zusammen gezählt“, aussagekräftige Meldung nach dem Speichern.
- Protokoll: Klartext statt Fachbegriffen, freiwillige Notiz mit Erklärung.

## 5. Unklare Begriffe
- „Transportliste“ → Liste zum Ausdrucken/Weitergeben (nicht klar, ob Zwischenablage).
- „Standard-Lager“ → vermutlich „Lager Gerätehaus“.
- „Sollbestand“ / „außer Betrieb“ (für ein Fahrzeug) → Mindestbestand; „außer Betrieb“ klingt, als dürfe das Fahrzeug nicht fahren.
- „Stand beim Eingang“ → Zustand, als die Buchung ankam.
- „gesperrt“-Knopf ohne Begründung (nur Optik, ausgegraut).
