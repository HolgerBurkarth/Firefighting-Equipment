# Mockups zum Änderungskonzept 2026-11

Abzug der UI-Mockups aus dem Claude-Projekt (Design „Mockups Änderungskonzept“), Stand 05.11.2026.
Konzept: [`../../AENDERUNGSKONZEPT-2026-11.md`](../../AENDERUNGSKONZEPT-2026-11.md).

## Wie lesen
- Die Dateien sind Referenz, kein Code der App. Sie laufen nur im Design-Werkzeug (`support.js`, `<x-dc>`, `sc-for`/`sc-if` fehlen hier) und lassen sich im Browser nicht direkt öffnen.
- Lesbar sind Texte, Spalten, Knöpfe, Felder und Abläufe: das HTML zwischen `<x-dc>` und `</x-dc>`, die Beispieldaten und das Verhalten im Skript `class Component` am Ende jeder Datei. `{{name}}` sind Platzhalter, die das Skript füllt.
- Farben, Abstände und Schrift folgen dem bestehenden Mockup (`docs/frontend/mockup/`) und `tokens.css`; maßgeblich bleiben dort die Design-Variablen, nicht die festen Werte hier.
- Oberflächentexte: Die Mockups verwenden bereits die festgelegten Begriffe (Konzept Abschnitt 9, z. B. „Ausrüstung“, „Termine“, „Rückgabe“). Bei Abweichungen gilt die Tabelle im Konzept.
- Personen, E-Mail-Adressen (`example.de`) und Geräte sind erfunden.

## Dateien

| Seite | Datei | Inhalt | Konzept |
|---|---|---|---|
| 1 · Rollen-Verwaltung | `Main.dc.html` | Super-Admin · Rollen einer Stadt | 1 |
| 1 · Rollen-Verwaltung | `Rolle-bearbeiten.dc.html` | Super-Admin · Rolle bearbeiten (Gerätewart) | 1 |
| 2 · Rolle zuweisen | `Benutzer.dc.html` | Admin · Benutzer mit Rollen | 1 |
| 2 · Rolle zuweisen | `Benutzer-Rolle.dc.html` | Admin · Rolle einer Person ändern | 1 |
| 2 · Rolle zuweisen | `Einladungscodes.dc.html` | Admin · Einladungscode mit Rolle | 1 |
| 3 · Wartungsablauf | `Standorte-Aufgaben.dc.html` | Standorte mit Aufgabe | 2, 3, 10 |
| 3 · Wartungsablauf | `Wartung.dc.html` | Gerätewart · Seite „Wartung“ | 2, 3, 10 |
| 3 · Wartungsablauf | `Handy-Benutzt.dc.html` | Geräteträger · Einmalgerät benutzt melden | 2, 3, 10 |
| 3 · Wartungsablauf | `Wege.dc.html` | Wege der Ausrüstung als Grafik (PC) | 2, 3, 10 |
| 3 · Wartungsablauf | `Geraeteweg.dc.html` | Detailseite · Weg einer Ausrüstung | 2, 3, 10 |
| 4 · Wartungen je Gerätetyp | `Geraetetyp-Intervall.dc.html` | Ausrüstungstyp mit mehreren Wartungen (klein/groß) | 4 |
| 4 · Wartungen je Gerätetyp | `Geraet-Fristen.dc.html` | Ausrüstung: eine Zeile pro Wartung | 4 |
| 5 · Verleih und Rückgabe | `Verleih.dc.html` | Gerätewart · Verliehen und verleihen | 5, 9 („Seiten anpassen“) |
| 5 · Verleih und Rückgabe | `Handy-Rueckgabe.dc.html` | Extern · Rückgabe ohne Anmeldung (Handy) | 5, 9 („Seiten anpassen“) |
| 5 · Verleih und Rückgabe | `Annahme.dc.html` | Gerätewart · Rückgabe bearbeiten | 5, 9 („Seiten anpassen“) |
| 6 · Mindestbestand und Tausch | `Mindestbestand.dc.html` | Standort mit Sollbestand, außer Betrieb | 6 |
| 6 · Mindestbestand und Tausch | `Handy-Tausch.dc.html` | Geräteträger · Tausch am Handy | 6 |
| 7 · Mail-Benachrichtigungen | `Benachrichtigungen.dc.html` | Profil · Mails und Erinnerungen an Termine | 7 |
| 8 · Konto wechseln | `Konto-wechseln.dc.html` | Admin · Konto wechseln (PC, Menü unten) | 12 (E14) |
| 8 · Konto wechseln | `Handy-Konto-wechseln.dc.html` | Handy · Konto wechseln im Profil | 12 (E14) |

Geändert am 05.11.2026: `Einladungscodes.dc.html` zeigt bei bestehenden Codes die Bezeichnung statt Codeteilen und keinen Nachdruck (Plan Phase 1, F4); das Formular hat wieder Bezeichnung, Gültig (Tage) und Registrierungen höchstens.

Ergänzt am 05.11.2026 (Schritt 1.7d „Schnellstart“, Ziel 4), von Holger freigegeben:

| Seite | Datei | Inhalt |
| --- | --- | --- |
| 9 · Startbild | `Startbild.dc.html` | Startbild beim Laden (Handy): gezeichnetes Löschfahrzeug, Name, Ladebalken mit drei Schritten, hell/dunkel |
