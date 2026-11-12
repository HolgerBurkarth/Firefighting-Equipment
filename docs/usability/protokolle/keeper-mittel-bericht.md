# Bericht keeper-mittel (Uwe, Gerätewart, 30–49, Laptop 1366×768)

## 1. Kurzprofil
Uwe, Gerätewart, Büro-PC-Routine, hat die Anleitung überflogen (nur Handy-Seiten gesehen, Wartung/Verleih standen dort nicht in den ersten 4 Seiten). Vorgehen: Menü links durchgegangen, Aufgaben 1–8. Andere Personen arbeiteten parallel; mehrere Aufgaben waren teilweise schon erledigt (M41, Sollbestand LF 10, Rückgabe, Hinweis, Wartungsdatum).

## 2. Tabelle
| Aufgabe | Ergebnis | Aktionen (gebraucht / kürzester Weg) | Einfachheit | Zitat |
|---|---|---|---|---|
| 1 Sammelstelle → Werkstatt + Liste | umweg | 9 / 5 | 5 | „Die Liste für den Fahrer – kopiert, aber hat es geklappt? Ich sehe nichts.“ |
| 2 Gewartetes zurück ins Lager | ja | 5 / 5 | 6 | „Alle auswählen, den defekten abhaken, fertig.“ |
| 3 Fünf Masken anlegen | umweg | 19 / ca. 17 | 5 | „M41 gibt es schon – dann eben M46 bis M50.“ |
| 4 Sollbestand LF 10 | umweg | 5 / 4 | 5 | „Warum steht das ganz unten auf Standorte?“ |
| 5 HRT 3 und HRT 4 verleihen | umweg | 13 / 11 | 4 | „Fehler oder nicht? Es steht ‚nicht möglich‘, aber sie sind verliehen.“ |
| 6 Rückgabe bearbeiten | nein | 4 / – | 5 | „Da liegt nichts mehr.“ (Daten schon weg, nicht ausführbar) |
| 7 Protokoll-Hinweis | nein | 2 / – | 6 | „Ist schon erledigt, von jemand anderem.“ (nicht ausführbar) |
| 8 Wartungstermin Funkgeräte | ja | 7 / 7 | 6 | „Typ Funkgerät, alle, Datum – gut.“ |

## 3. Probleme
- **P1 (Wartung, „Transportliste kopieren“), Schwere 3**: Nach dem Klick keine sichtbare Rückmeldung (kein „Kopiert“). Ich wusste nicht, ob die Liste in der Zwischenablage ist. Außerdem ist die Liste der Sammelstelle nach dem Verschieben leer; die Reihenfolge (erst Liste, dann verschieben) wird nirgends gesagt. Foto: `../bilder/keeper-mittel-02-a1-liste.png`. Vorschlag: Kurzmeldung „Liste kopiert (3 Ausrüstungen)“; Hinweis im Bestätigungsfenster „Transportliste jetzt kopieren?“ oder Liste nach dem Verschieben aus der Werkstatt-Gruppe anbieten.
- **P2 (Verleih, „Verleihen …“), Schwere 3**: Meldung „0 Ausrüstungen geändert, 2 nicht möglich … HRT 3 steht schon bei FF Nachbarort“, obwohl die Liste danach HRT 3/4 mit „verliehen gerade eben von Max Muster“ und Rückgabe bis 20.11.2026 zeigt. Widersprüchlich; ich war unsicher, ob es geklappt hat. Foto: `keeper-mittel-07-a5-fehler.png`. Vorschlag: Meldung und Liste abgleichen (nach Erfolg nur grüne Meldung), bei wirklich schon verliehener Ausrüstung klar sagen, seit wann und von wem.
- **P3 (Verleih, „Rückgabe bis“), Schwere 2**: Datumsfeld akzeptiert nur JJJJ-MM-TT in der Eingabe; „20.11.2026“ war ein Fehler. Vorschlag: Format als Platzhalter anzeigen bzw. deutsches Format zulassen.
- **P4 (Standorte, Sollbestand), Schwere 2**: Der Bereich „Sollbestand“ liegt am Ende einer langen Seite; nur durch Scrollen gefunden, und der Menüpunkt „Standorte“ verrät nichts davon. Foto: `keeper-mittel-05-a4.png`. Vorschlag: Kurzlink „Sollbestand“ oben auf der Seite oder eigener Menüpunkt.
- **P5 (Ausrüstung neu, Standortliste), Schwere 2**: Beim Auswählen des Standorts zeigte die Liste nur die ersten 10 Einträge; „Lager Gerätehaus“ war erst nach Scrollen/Taste Ende sichtbar. Dazu muss die Seite für „Speichern und nächstes“ nach unten gescrollt werden (Knöpfe liegen unter dem Sichtbereich bei 768 px Höhe). Foto: `keeper-mittel-03-a3-liste.png`. Vorschlag: Suche in der Liste, Speichern-Knöpfe fest unten.
- **P6 (Wartung, Auswahlkästchen), Schwere 1**: Kästchen nur 22×22 px, Nummern-Links 22 px hoch; für PC knapp, aber bedienbar.
- **P7 (Wartung, „Alle auswählen“ in der Werkstatt), Schwere 2**: „Alle“ nimmt auch Defekte mit (PA 11); ich musste einzeln abwählen. Das Bestätigungsfenster nennt immerhin Ziel und Status.

## 4. Was gut funktioniert hat
- Wartung: zwei Spalten Sammelstelle/Werkstatt, „Zur Werkstatt …“ mit Rückfrage, die Ziel und Status nennt, und grüne Erfolgsmeldung mit Anzahl.
- „Speichern und nächstes“ zählt die Nummer weiter, auch per Enter; fünf Masken in wenigen Schritten. Fehlermeldung bei doppelter Nummer klar und mit Sprunglink.
- Ausrüstung: Typfilter + „Alle auswählen“ + „Wartungsdatum setzen …“ ist direkt und ergibt eine klare Meldung.
- Leerzustände (Rückgabe, Hinweise) verständlich formuliert; Reiter „Erledigt“ findet den Hinweis.
- Dashboard mit Kennzahlen als Einstieg gut lesbar.

## 5. Unklare Begriffe
- „Transportliste“ → Liste zum Ausdrucken/Mitgeben, nicht klar, wohin sie kopiert wird.
- „Aushang erneuern/widerrufen“ → Plakat mit QR-Code, erst nach Nachdenken verstanden.
- „Außer Betrieb“ (Fahrzeug) → Fahrzeug nicht einsatzfähig wegen Sollbestand; erst durch die Regel darunter erklärt.
- „Sammelstelle“/„Werkstatt“/„Zurück ins Lager“ → verständlich, bekannt aus dem Alltag.

## Ehrliche Einschränkungen
Aufgaben 6 und 7 konnten nicht ausgeführt werden, weil die Daten bereits von anderen Teilnehmern bearbeitet waren. Aufgabe 4: Regel bestand schon und wurde nur geprüft, nicht neu gesetzt. Aufgabe 8: Server meldete 20 unverändert (Datum war schon gesetzt).
