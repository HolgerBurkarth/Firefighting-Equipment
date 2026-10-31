# Live-Test der Admin-Konsole (Schritt 13)

Abschließender Test der Admin-Konsole (KONZEPT.md Entscheidung 29) nur in der Test-Stadt
`https://www.prodad.de/firefighting/test/`, nie in einer echten Stadt. Ergebnisse bitte mit der
Nummer melden (z. B. „B3 rot: …“, mit Bildschirmfoto bzw. Text aus der Konsole). Gefundene Wünsche
kommen nach `docs/TODO-SPAETER.md`, Fehler werden vor dem Abschluss behoben.

Was in den Schritten 3 und 9–12 schon live geprüft ist (KONZEPT.md, "Bereiche des Admins"), steht
hier nur noch als kurzer Durchlauf (☐ kurz).

Stand 31.10.2026: Die Admin-Konsole ist als abgeschlossen markiert (KONZEPT.md); diesen Durchlauf
macht danach Claude im Browser. Schon erledigt (☑): die Live-Tests der Schritte 9–12 und die
Prüfung des Updates am PC (A1). Ergebnisse je Punkt bitte hier eintragen (☑ bzw. ☒ mit Fund).

## Vorbereitung
- Stand: der aktuelle `main` nach grünem CI-Lauf deployt. Live seit 31.10.2026: Build
  `b3438d44f157`, darin auch die Korrektur „Updates: Start wartet bis rund 30 s …“.
- Geräte: PC mit Chrome oder Edge (Entwicklerwerkzeuge offen, Reiter „Konsole“), iPhone mit Safari,
  wenn vorhanden ein Android-Handy; ein USB-Handscanner, wenn vorhanden.
- Konten der Test-Stadt: Admin A (Sie), Admin B mit „Mail bei jeder Änderung an Geräten“ (Postfach
  im Blick), ein Konto mit Rolle Benutzer. Ein Wegwerf-Konto zum Sperren und Löschen (z. B. über
  einen Einladungscode mit `holger.burkarth+user2@live.de` registriert).
- Bei jedem Punkt nebenbei: keine roten Einträge in der Konsole, keine Meldung zur
  Content-Security-Policy.

## A Update der App (Korrektur vom 31.10.2026)
- ☐ A1 Vor dem Deploy die Test-Stadt am iPhone als App (Homescreen) und am PC offen lassen. Nach dem
  Deploy die App am iPhone neu öffnen: höchstens rund 30 s „Seite wird geladen …“, dann die neue
  Version, nie „Die neue Version konnte nicht geladen werden.“
  ☑ PC (31.10.2026, Build `b3438d44f157`): neuer Tab direkt auf `dashboard` mit älterer Version im
  Service Worker → von selbst die neue Version, keine Meldung „konnte nicht geladen werden“, Seite
  vom neuen Service Worker gesteuert. Offen für Holger: iPhone als App.
- ☐ A2 Nur, falls ohnehin ein weiterer Deploy ansteht: am PC in einem offenen Formular (z. B.
  „Bearbeiten“ eines Geräts) etwas eintippen, dann deployen und zum Tab zurückkehren: Hinweis mit „Jetzt aktualisieren“, die Eingabe bleibt, bis Sie selbst
  aktualisieren (Rückfrage „Ihre Eingaben … gehen verloren“).
  Offen für Holger; im Browser durch Claude nicht prüfbar bzw. nicht geprüft.

## B Menü und Dashboard (Schritt 1)
- ☐ B1 Fensterbreite ab 1024 px: Menü links mit Text; 768–1023 px: nur Symbole, Name beim Darüberfahren;
  schmaler: Kopfleiste in der Stadtfarbe mit ☰, Schublade schließt mit Escape, „Menü schließen“ und
  beim Seitenwechsel.
  Offen für Holger (schmale Breiten); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☑ B2 Kacheln: Einsatzbereitschaft „N von M“ passt zur Geräteliste ohne Filter; „nicht
  einsatzbereit“ passt zum Filter in Wartung + defekt; jede Kachel führt zur passenden Liste bzw. zu
  Prüffristen, Protokoll und Benutzern.
  ☑ Claude per Chrome (31.10.2026).
- ☐ B3 Eine Umbuchung am iPhone (Benutzer-Konto): erscheint nach dem Neuladen des Dashboards oben in
  „Letzte Aktivitäten“ mit Wer, Von/Nach und Kommentar.
  Offen für Holger (iPhone); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☑ B4 Zähler im Menü (Benutzer, Protokoll) stimmen mit den Seiten überein.
  ☑ Claude per Chrome (31.10.2026).

## C Standorte und Gerätetypen (Schritt 2)
- ☑ C1 „Neuer Standort“ Fahrzeug „Live-Test“, darin „Fach anlegen“ „G1“; ein zweites „g1“ meldet
  schon beim Tippen, dass der Name vergeben ist.
  ☑ Claude per Chrome (31.10.2026).
- ☑ C2 Umbenennen; der Name führt zur Geräteliste mit diesem Standort (samt Fächern).
  ☑ Claude per Chrome (31.10.2026).
- ☑ C3 Sperren von „HLF 20“: Knopf gesperrt mit Hinweis, was noch dort liegt. Sperren des leeren
  „Live-Test“: erst Rückfrage, dann gesperrt; erscheint nur mit „Gesperrte anzeigen“ und nicht mehr
  in der Standortwahl am Handy. Entsperren: das Fach erst nach dem Fahrzeug.
  ☑ Claude per Chrome (31.10.2026). Fund: „Hier liegen noch 1 aktives Fach.“ → behoben (Einzahl, auch bei 1 Gerät).
- ☑ C4 Gerätetyp anlegen, umbenennen, deaktivieren (Rückfrage) und aktivieren; Sortierung über die
  Spaltenköpfe, am Handy über die Knöpfe „Sortieren“.
  ☑ Claude per Chrome (31.10.2026). Am PC geprüft; die Knöpfe „Sortieren“ am Handy offen (Holger).

## D Geräteliste (Schritt 3, kurz)
- ☑ D1 kurz: Filter Gerätetyp, Standort, Status, Suche; die Filter stehen in der Adresse und bleiben
  nach Zurück aus dem Gerätedetail.
  ☑ Claude per Chrome (31.10.2026).
- ☑ D2 „Ausgesonderte anzeigen“ zeigt M24; „Weitere laden“ nach 50 Geräten.
  ☑ Claude per Chrome (31.10.2026).
- ☑ D3 „Export (CSV)“ mit Filter: Datei öffnet in Excel mit Umlauten und den gefilterten Geräten.
  ☑ Claude per Chrome (31.10.2026).
- ☐ D4 Am iPhone: Karten, „Sortieren nach“ und Richtung, kein waagerechtes Wischen nötig.
  Offen für Holger (iPhone); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.

## E Gerätedetail (Schritt 4)
- ☑ E1 Kennung in der Liste öffnet `geraete/<id>/details`: Standort, Status, nächste Prüfung (bald
  fällig gelb, überfällig rot), Stammdaten, Verlauf; am breiten Bildschirm nebeneinander.
  ☑ Claude per Chrome (31.10.2026).
- ☑ E2 „Bearbeiten“ (z. B. Seriennummer) mit Kommentar: grüne Meldung, neuer Eintrag im Verlauf.
  ☑ Claude per Chrome (31.10.2026).
- ☑ E3 „Prüfdatum ändern“ und leeren.
  ☑ Claude per Chrome (31.10.2026).
- ☐ E4 „Code zuordnen“ am PC mit dem USB-Handscanner (Enter speichert; ein GS1-Code zeigt „Erkannt:
  …[GS]…“), am iPhone „Mit der Kamera scannen“: der Code steht danach im Fenster, gespeichert erst
  mit „Code speichern“. Ein schon vergebener Code wird am Feld abgelehnt.
  Offen für Holger (Handscanner, Kamera); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☑ E5 „Umbuchen / Status ändern“ führt zum Umbuchen und danach mit Bestätigung zurück zum Detail.
  ☑ Claude per Chrome (31.10.2026).
- ☑ E6 Dasselbe Gerät in zwei Tabs: in Tab 1 bearbeiten und speichern, in Tab 2 danach ändern →
  Hinweis, dass es inzwischen geändert wurde, der neue Stand steht oben, eigene Eingaben bleiben.
  ☑ Claude per Chrome (31.10.2026).
- ☑ E7 „Aussondern …“ eines Testgeräts (Rückfrage nennt das Gerät), dann „Wieder aufnehmen“ mit
  Status und Standort. Zweiter Durchgang: nach dem Aussondern ein neues Gerät mit derselben Kennung
  anlegen; die Wiederaufnahme verlangt dann eine andere Kennung.
  ☑ Claude per Chrome (31.10.2026). Fund: Die Meldung am Feld zeigte den Zusatz (Feld "identifier") → behoben (eigene Meldung, ebenso beim Scan-Code).

## F Links in den Mails (Schritt 5)
- ☐ F1 Änderung an einem Gerät durch Admin A: Admin B erhält „Log Feuerwehr Test: …“, der Link öffnet
  das Gerätedetail (vorher Anmeldung, danach genau diese Seite).
  Offen für Holger (Mails); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☐ F2 CSV-Import (Teil H): eine Sammelmail, Link auf die Geräteliste.
  Offen für Holger (Mails); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☐ F3 Hinweis-Mail (bestätigte alte Offline-Buchung wie in Schritt 11): Link auf `protokoll/<id>`.
  Offen für Holger (Mails); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☐ F4 Neue Registrierung bestätigt: „Neue Registrierung wartet …“ mit Link auf die Benutzer.
  Offen für Holger (Mails); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.

## G Neues Gerät (Schritt 6)
- ☑ G1 „Neues Gerät“: Kennung M90, Typ, Standort, „Speichern und nächstes“ (auch Enter): grüne
  Meldung mit „Gerät öffnen“, Typ, Standort und Prüfdatum bleiben, Kennung M91 ist markiert.
  ☑ Claude per Chrome (31.10.2026).
- ☐ G2 Scan-Code am iPhone mit der Kamera; Enter des Handscanners im Feld Scan-Code speichert nicht.
  Offen für Holger (iPhone, Handscanner); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☑ G3 Schon vergebene Kennung bzw. vergebener Scan-Code: Fehler am Feld (bekannt: T10, T11).
  ☑ Claude per Chrome (31.10.2026).
- ☑ G4 „Speichern“ öffnet das neue Gerät mit Bestätigung.
  ☑ Claude per Chrome (31.10.2026).

## H CSV-Import (Schritt 7)
- ☑ H1 „Vorlage herunterladen“ und „Gültige Werte herunterladen“ öffnen in Excel richtig.
  ☑ Claude per Chrome (31.10.2026). Inhalt im Browser geprüft (UTF-8 mit BOM, Semikolon); Öffnen in Excel nicht geprüft.
- ☑ H2 Vorlage mit zwei Geräten ausfüllen, eins mit Standort „HLF20“: Vorschau mit „Meinten Sie „HLF
  20“?“ und Link auf die Standorte; Beispielzeilen stehen noch drin → Warnung.
  ☑ Claude per Chrome (31.10.2026).
- ☑ H3 Datei in Excel korrigieren, gleiche Datei erneut wählen: Vorschau ohne Fehler, „Import
  übernehmen (…)“ mit Kommentar; die Geräte stehen in der Liste mit Verlauf „Importiert“.
  ☑ Claude per Chrome (31.10.2026).
- ☑ H4 Export der Geräteliste ändern (z. B. Prüfdatum) und importieren: nur geänderte Zeilen.
  ☑ Claude per Chrome (31.10.2026).

## I Benutzer und Einladungscodes (Schritte 8 und 9, kurz)
- ☐ I1 Wartende Anmeldung: „Freischalten …“ mit Anzeigename und Rolle bzw. „Ablehnen …“ (Mail an die
  Person).
  Offen für Holger; im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☐ I2 Wegwerf-Konto: Rolle ändern, sperren (Anmeldung danach abgelehnt), entsperren, löschen
  („Gelöschter Benutzer #ID“ im Verlauf).
  Offen für Holger; im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☑ I3 Eigenes Konto: Hinweis, Rolle gesperrt, kein Sperren oder Löschen.
  ☑ Claude per Chrome (31.10.2026).
- ☑ I4 (Schritt 9, live geprüft 31.10.2026) kurz: neuer Einladungscode, „Code kopieren“, danach „Widerrufen …“; der Code gilt nicht mehr.

## J Sammelaktionen, Protokoll, Einstellungen (Schritte 10–12, kurz)
- ☑ J1 (Schritt 10, live geprüft 31.10.2026) kurz: drei Geräte auswählen, „Prüfdatum setzen …“, Ergebnis über der Leiste.
- ☑ J2 (Schritt 11, live geprüft 31.10.2026) kurz: Protokoll mit Filter Offen/Erledigt/Alle, ein Hinweis erledigen und wieder öffnen.
- ☑ J3 (Schritt 12, live geprüft 31.10.2026; Fund T14) kurz: Akzentfarbe ändern und wieder auf Standard; Logo bleibt im Menü, auf der Anmeldeseite und
  als Symbol im Tab. Homescreen am iPhone ist geprüft (sauber); Android siehe T13.

## K Übergreifend
- ☐ K1 Mit dem Benutzer-Konto `…/test/geraete`, `…/test/benutzer`, `…/test/einstellungen` direkt
  aufrufen: jeweils „Suchen“, keine Admin-Seite.
  Offen für Holger (Benutzer-Konto); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☑ K2 Anzeigemodus Dunkel: alle Seiten der Konsole lesbar (Tabellen, Fenster, Auswahllisten,
  Statusfarben).
  ☑ Claude per Chrome (31.10.2026).
- ☐ K3 Am iPhone: jede Seite der Konsole einmal, Fenster lassen sich schließen, Auswahllisten scrollen,
  die Tastatur verdeckt keine Knöpfe dauerhaft.
  Offen für Holger (iPhone); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.
- ☑ K4 Admin auf den Handy-Seiten: Leiste unten mit „Dashboard“; auf dem Umbuchen der Link
  „Gerätedetail (Admin)“.
  ☑ Claude per Chrome (31.10.2026). Bei voller Fensterbreite geprüft.
- ☐ K5 Abmelden aus dem Menü, danach führt Zurück im Browser nicht in die Konsole.
  Offen für Holger (Abmelden); im Browser durch Claude nicht prüfbar bzw. nicht geprüft.

## Ergebnis des Durchlaufs (Claude per Chrome, 31.10.2026)
- ☑ B2, B4, C1–C4, D1–D3, E1–E3, E5–E7, G1, G3, G4, H1–H4, I3, K2, K4 (Einzelheiten an den Punkten).
- Offen für Holger (im Browser nicht prüfbar: iPhone, schmale Breiten, Handscanner, Mails,
  Anmelden und Abmelden): A1 iPhone, A2, B1, B3, D4, E4, F1–F4, G2, I1, I2, K1, K3, K5.
- Funde, behoben: C3 Einzahl „Hier liegt noch 1 aktives Fach.“ bzw. „1 Gerät.“; E7 eigene Meldung
  am Feld, wenn Kennung bzw. Scan-Code beim Wiederaufnehmen vergeben sind (vorher mit
  `(Feld "identifier")` bzw. „mit null entfernen“ aus der API).
- ☑ Behebungen live geprüft (Claude per Chrome, 31.10.2026, Build `b6a25b03573c`): Sperren zeigt
  „Hier liegt noch 1 aktives Fach.“ (Live-Test Wagen) bzw. „Hier liegt noch 1 Gerät.“ (M90 kurz in
  Live-Test Wagen / G1), mehrere weiter mit „liegen“ (MTW, LF 10, HLF 20 / G4). Wiederaufnahme von M94
  (dazu kurz ausgesondert, M93 mit Kennung M94 und Scan-Code LT-SC-94): „Die Kennung „M94“ ist
  inzwischen an ein anderes Gerät vergeben. Bitte geben Sie eine andere Kennung ein.“ bzw. mit M95 die
  Meldung zum Scan-Code, beides ohne Feldnamen der API. M90, M93, M94 danach zurückgesetzt.
- ☒ Fund beim Update nach diesem Deploy (PC, Chrome, Tab mit altem Build `b3438d44f157` im Service
  Worker, direkt `standorte` geöffnet): nach rund 30 s „Die neue Version konnte nicht geladen
  werden.“, obwohl die neue Version danach als `waiting` installiert war. „Neu laden“ holte die neue
  Seite vom Server, beim nächsten normalen Neuladen lieferte aber wieder der alte Service Worker die
  alte Version (die neue weiter `waiting`). Vermutung: Die neu geladene Seite registriert `sw.js` erneut
  und belebt so die eben abgemeldete Registrierung mit dem alten Service Worker wieder. Erst „Jetzt
  aktualisieren“ brachte die neue Version. Noch nicht behoben, Untersuchung als nächster Schritt.
- Notiz (kein Fehler): „Letzte Aktivitäten“ ist nach Eingang beim Server sortiert, zeigt aber die
  Zeit der Aktion (bei Offline-Buchungen können die Zeiten daher nicht absteigend wirken).
- Testdaten in der Test-Stadt (bleiben vorerst): M90, M91 (neu), M92 (früheres M91), M93 (HLF 20),
  M94, Standort „Live-Test Wagen“ mit G1, Gerätetyp „Live-Testtyp 2“. An M27 stehen noch
  Seriennummer LT-0027 und Status in Wartung, das Prüfdatum ist entfernt (vorher 02.11.2027).

## Abschluss
Die Admin-Konsole ist seit 31.10.2026 abgeschlossen (KONZEPT.md). Nach dem Durchlauf trägt der
Agent das Ergebnis hier und kurz in KONZEPT.md ("Admin-Konsole") ein; Fehler werden behoben, Wünsche
kommen nach `docs/TODO-SPAETER.md`.
