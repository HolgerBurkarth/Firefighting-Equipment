# Bericht keeper-jung-b (Tim, Gerätewart, 18–29, PC 1920x1080), Aufgaben 3–8

## 1. Kurzprofil
Tim überflog die Anleitung nur (nichts zu diesen Aufgaben dort) und probiert aus. Frische Daten, Port 5104. Alle sechs Aufgaben gelöst.

## 2. Tabelle
| Aufgabe | Ergebnis | Aktionen (gebraucht / kürzester Weg) | Einfachheit | Zitat |
|---|---|---|---|---|
| G3 M41–M45 anlegen | ja | 22 / ca. 12 | 5 | „Praktisch, dass die Nummer weiterzählt.“ |
| G4 Sollbestand LF 10 (4 Masken, 4 Geräte) | ja | 13 / 11 | 5 | „Ah, zwei Regeln, nicht ein Haken je Typ.“ |
| G5 Verleih HRT 3/4 an FF Nachbarort bis 20.11. | ja | 11 / 9 | 5 | „Warum muss ich jede Nummer einzeln eintippen?“ |
| G6 Rückgabe bearbeiten | ja | 5 / 4 | 6 | „Das Kästchen ist winzig.“ |
| G7 Hinweis erledigen | ja | 5 / 4 | 6 | „Was heißt ‚Nur Historie‘?“ |
| G8 Funkgeräte Wartung 15.03.2027 | ja | 8 / 7 | 6 | „Sammelauswahl ist top.“ |

## 3. Probleme
- P1 (Neue Ausrüstung): Nach dem letzten „Speichern und nächstes“ steht schon M46 im Formular; nach dem Speichern ändert sich die Reihenfolge der Bedienelemente (Meldung „M41 angelegt“ mit Link schiebt alles), Schwere 1–2. Vorschlag: Meldung zählt die Nummer des gerade angelegten (M45), Hinweis „Nächste Nummer: M46 – leer lassen zum Beenden“.
- P2 (Neue Ausrüstung): Die Meldung heißt nach jedem Speichern „M41 angelegt“ obwohl M42 gespeichert wurde? Tatsächlich zeigte der Text beim Wiederholen mehrfach noch M41, bis ich sah, dass die Nummer weitergezählt hatte; Rückmeldung wirkt veraltet (Schwere 2). Foto: ../bilder/keeper-jung-b-01-g3x.png (Formular vor dem Speichern).
- P3 (Standorte, Sollbestand festlegen): Im Auswahlfeld Standort stehen Fächer nur als „G1“, „G3“ ohne Fahrzeug, schwer zuordenbar; „mehrere = gleichwertig, zählen zusammen“ ist leicht zu überlesen, man braucht für Masken und Geräte zwei getrennte Regeln (Schwere 2). Foto: keeper-jung-b-03-g4b.png. Vorschlag: „LF 10 / G1“ anzeigen; Beispiel unter dem Typfeld.
- P4 (Sollbestand): Nach dem Speichern wird LF 10 sofort „außer Betrieb“ (2 von 4 Masken) – korrekt, aber ohne Erklärung, was „außer Betrieb“ praktisch heißt (Schwere 1).
- P5 (Verleih): Jede Nummer einzeln eintippen und Enter; kein Mehrfachauswahl aus der Liste (Schwere 2). Datumsfeld nimmt am PC nur das Browserformat; Tippen von „20.11.2026“ hat im Testtreiber nicht funktioniert (Treibergrenze, nicht App).
- P6 (Verleih, Rückgabe): Kästchen „HRT 20 auswählen“ nur 13x13 px (Schwere 2). Foto: —.
- P7 (Protokoll, Hinweis): „Nur Historie (neuerer Stand vorhanden)“ ist unverständlich; der Hinweis zeigt Buchung und Ausrüstung jetzt, aber nicht, was ich tun soll außer „Erledigen“ (Schwere 2).
- P8 (Wartung, Dialog) „Bringen“ als Knopf für „zur Werkstatt“/„Zurück ins Lager“/„In die Sammelstelle“ passt nicht überall (Schwere 1).

## 4. Was gut funktioniert hat
- „Speichern und nächstes“ mit Weiterzählen der Nummer; Enter im Nummernfeld speichert.
- Sammelauswahl in der Ausrüstungsliste mit Filter nach Typ; Dialog „Wartungsdatum für 20 Ausrüstungen“ klar; Ergebnis „20 Ausrüstungen geändert.“
- Sollbestand: Zusammenfassung nennt direkt „2 vorhanden, 2 fehlen“.
- Verleih-Dialog: Liste der hinzugefügten Geräte mit „Entfernen“, Bestätigung „2 Ausrüstungen an „FF Nachbarort“ verliehen.“
- Protokoll: Hinweis zeigt Buchung, Stand beim Eingang und aktuellen Stand nebeneinander, „Erledigen“ mit Notiz.

## 5. Unklare Begriffe
- „Nur Historie (neuerer Stand vorhanden)“ → dachte: Buchung wurde nur protokolliert, nicht angewendet.
- „Außer Betrieb“ (Standort) → Fahrzeug nicht einsatzfähig wegen zu wenig Ausrüstung.
- „gleichwertig, zählen zusammen“ → mehrere Typen in einer Regel zählen als eine Summe.
- „Bringen“ (Dialogknopf).

## Zusammenfassung (5 Sätze)
1. Die größten Schwierigkeiten hatte ich im Sollbestand-Fenster, weil man für Masken und Geräte zwei getrennte Regeln anlegen muss und die Fächer ohne Fahrzeugnamen angezeigt werden.
2. Beim Anlegen mehrerer Masken verwirrte, dass die Bestätigung „angelegt“ und die weiterzählende Nummer nicht eindeutig zusammenpassen.
3. Im Verleih muss man jede Nummer einzeln eintippen, eine Auswahl aus der Liste fehlt.
4. Winzige Kästchen (13 px) in der Rückgabe sind am PC schlecht zu treffen.
5. Der Hinweis im Protokoll („Nur Historie …“) ist für Laien unklar, sonst lief alles flott.
