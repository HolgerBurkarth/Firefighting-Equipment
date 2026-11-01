# Live-Test der Wünsche aus TODO-SPAETER.md

Kleine Wünsche (T1, T2, T3, T4, T9, T10, T11, T14), umgesetzt am 01.11.2026. Geprüft nur in der
Test-Stadt `https://www.prodad.de/firefighting/test/`; an bestehenden Geräten geänderte Werte danach
zurückgesetzt. Was der Browser nicht prüfen kann (iPhone, Touch), steht unter "Offen für Holger".
Ergebnisse mit der Nummer eintragen (☑ bzw. ☒ mit Fund).

## Geprüft (Claude per Chrome)
- ☑ T1 „Zuletzt benutzt“ nach der letzten Änderung (Build `57bc22d0ab2a`): M27 geöffnet (eigene
  Nutzung damit vor M90), angezeigt bleibt M90 („vor 46 Min.“) vor M27 („gestern 12:30“); keine
  Konsolenfehler.
- ☑ T2 Nur ein zuletzt benutzter Standort (Build `c27f187161f6`): gespeicherte Ziele (Testwert)
  HLF 20 / G1 (= aktuell), Lager Gerätehaus, LF 10 → angezeigt „HLF 20 / G1 aktuell“ und „Lager
  Gerätehaus zuletzt“; Testwert danach entfernt.
- ☐ T3 Standortwahl scrollt beim Aufklappen (Build `b3c40d371dee`): am PC nicht aussagekräftig
  prüfbar, das Fenster ließ sich nicht verkleinern (965 px hoch, alle Fächer passen ohne Scrollen).
  Abgedeckt durch `locationPicker.spec.ts` „Standortwahl: Aufklappen scrollt die Fächer ins Bild
  (T3)“ in allen fünf Profilen (auch iPhone 13 und Pixel 7 emuliert); siehe unten L1.
- ☑ T4 „Speichern“ nur bei Änderung (Build `06af0e348f85`), M90: zu Beginn gesperrt; nur
  Kommentar → gesperrt mit „Bitte wählen Sie einen anderen Standort oder Status.“; anderer Status →
  antippbar; zurück auf den jetzigen → gesperrt; nichts gespeichert.
- ☑ T9 Geräteliste (Build `74dd5d716ffd`): Zeile mit Zeiger-Cursor; Kästchen von M90 wählt nur aus
  („1 Gerät ausgewählt“, Auswahl danach aufgehoben); Klick auf den Standort in der Zeile öffnet das
  Gerätedetail von M90.
- ☑ T10 Neues Gerät (Build `01b9a281aa8e`): „Speichern“ ohne Eingaben → unter den Knöpfen „Nicht
  gespeichert: Bitte eine Kennung eingeben.“ mit „Zum Feld „Kennung““ (Sprung fokussiert die
  Kennung); Kennung „M90“ (vergeben) mit „Live-Testtyp 2“ und „Lager Gerätehaus“ → „Nicht
  gespeichert: Die Kennung „M90“ ist bereits an ein anderes Gerät vergeben …“, kein Gerät angelegt.
- ☑ T11 Neues Gerät am PC (Build `e00eac8e3d10`): M98 mit „Live-Testtyp 2“ und „Lager Gerätehaus“,
  zum Knopf gescrollt (115 px), „Speichern und nächstes“ → Seite oben (0), „✓ M98 angelegt.“,
  Kennung „M99“ fokussiert und markiert. M98 danach ausgesondert (Kommentar „Live-Test T11
  (Testgerät)“). Am iPhone siehe L2.
- ☑ T14 Einstellungen (Build `68d81f088c0b`): Antwort beim Speichern im Tab per JavaScript um 2 s
  verzögert; „Dunkelblau“ gespeichert, währenddessen „Grün“ gewählt → gespeichert ist `#1d3557`
  (Stadtfarbe der App), im Feld bleibt `#2E5E3E`, „Farbe speichern“ wieder angeboten. Danach die
  bisherige Farbe `#c62828` wiederhergestellt; keine Konsolenfehler.

## Offen für Holger (iPhone)
- ☐ L1 (T3) „Anderen Standort suchen …“: ein Fahrzeug weit unten aufklappen (z. B. MTW nach
  Scrollen ganz nach unten); die Fächer laufen nicht mehr aus dem Bild, der Kopf des Fahrzeugs
  bleibt unter der Kopfzeile sichtbar.
- ☐ L2 (T11) Neues Gerät in der Test-Stadt mit Kennung M9x: „Speichern und nächstes“ antippen
  (Tastatur offen oder zu): danach steht die Seite oben, „M9x angelegt.“ ist sichtbar, die Kennung
  ist fokussiert und markiert, die Tastatur offen. Gerät danach aussondern.
