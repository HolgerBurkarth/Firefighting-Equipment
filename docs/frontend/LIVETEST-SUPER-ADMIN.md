# Live-Test des Super-Admin-Bereichs

Bereich `https://www.prodad.de/firefighting/service/` (KONZEPT.md, "Super-Admin – Entscheidungen",
39–47). Angelegt in der Nacht vom 31.10.2026: Claude prüft im Browser nur lesend (Nachtauftrag); alle
schreibenden Punkte (Stadt anlegen, umbenennen, sperren, Admins einladen, Super-Admins sperren)
macht Holger. Ergebnisse bitte mit der Nummer eintragen (☑ bzw. ☒ mit Fund).

Wichtig: Schreibende Prüfungen nur mit einer eigens angelegten Probe-Stadt (z. B. „Feuerwehr
Probe“, Kürzel `probe`), nie mit Immendingen. Die Test-Stadt `test` nur umbenennen und gleich
zurückbenennen; nicht sperren, solange dort jemand arbeitet. Städte lassen sich nicht löschen: Die
Probe-Stadt bleibt danach gesperrt stehen.

## Vorbereitung
- Stand: aktueller `main` nach grünem CI-Lauf deployt.
- PC mit Chrome oder Edge (Konsole offen), dazu das iPhone für die schmale Breite.
- Angemeldet als Super-Admin unter `/firefighting/service/anmelden`; ein zweites Postfach für die
  Einladung (z. B. `holger.burkarth+probe@live.de`).
- Bei jedem Punkt nebenbei: keine roten Einträge in der Konsole, keine CSP-Meldung.

## A Lesend (Claude per Chrome)
- ☑ A1 Dashboard: Kacheln Städte (aktiv/gesperrt), offene Admin-Einladungen, Benutzer gesamt;
  darunter die Städte. (31.10.2026, Build `c99efc2b76c4`: 2 aktiv, 0 gesperrt, 0 Einladungen,
  5 Benutzer.)
- ☑ A2 Städte: Liste mit Adresse, Status, Admins, Benutzer; Sortieren über die Spaltenköpfe.
- ☑ A3 Einstellungen: Anzeigemodus, Benachrichtigung („aus allen Städten“), Passwort, angemeldete
  Geräte; kein Erscheinungsbild.
- ☑ A4 „Neue Stadt“: Kürzel folgt dem Namen, Prüfung beim Tippen („Feuerwehr Test“ → `test`
  vergeben, „Feuerwehr Test Probe Süd“ → `test-probe-sued` frei), mit „Abbrechen“ geschlossen ohne
  Anlegen (31.10.2026, Build `1e0bc9a58faa`).
- ☑ A5 Stadtdetail der Test-Stadt: Angaben (Adresse, Kürzel, 2 Admins, 4 Benutzer, angelegt
  26.10.2026), 4 Konten nur zum Ansehen, Knöpfe „Admin einladen …“, „Umbenennen …“, „… sperren …“
  (nicht betätigt); keine Konsolenfehler (31.10.2026, Build `d4a4bcdc0ca8`).
- ☐ A6 Super-Admins: Liste, eigenes Konto „Sie“ ohne Knopf, geschütztes Konto „geschützt“ ohne
  Knopf.

## B Schreibend (Holger)
- ☐ B1 „Neue Stadt“ mit Name „Feuerwehr Probe“: Kürzel `probe` vorgeschlagen und frei, E-Mail des
  zweiten Postfachs → grüne Meldung, die Stadt steht mit „Einladung offen“ in der Liste, die
  Kachel „Offene Admin-Einladungen“ zählt 1.
- ☐ B2 Mail „Einladung“ kommt an (Absender, Spam-Ordner?), Link öffnet `einladung-annehmen` der
  Probe-Stadt; Konto einrichten; danach zeigt das Dashboard keine offene Einladung mehr und die
  Stadt „aktiv“ mit 1 Admin. Mail „Einladung angenommen“ an die Super-Admins.
- ☐ B3 „Erneut senden“ (Dashboard und Stadtdetail) bei einer offenen Einladung: neue Mail, der alte
  Link gilt nicht mehr (Meldung beim Öffnen des alten Links).
- ☐ B4 Stadtdetail Probe: „Umbenennen …“ (Kürzel bleibt), danach zurückbenennen.
- ☐ B5 Stadtdetail Probe: „Admin einladen …“ mit einer schon vorhandenen Adresse der Stadt → Meldung
  am Feld; mit neuer Adresse → Einladung (Mail).
- ☐ B6 Stadtdetail Probe: „Feuerwehr Probe sperren …“ mit Rückfrage; danach meldet die
  Anmeldeseite der Probe-Stadt „gesperrt“, ein dort angemeldetes Konto ist abgemeldet; „Admin
  einladen …“ ist gesperrt. Entsperren mit Rückfrage; Anmeldung wieder möglich.
- ☐ B7 Super-Admins: ein zweites, nicht geschütztes Konto sperren und entsperren (nur, wenn es
  eins gibt); das gesperrte Konto kann sich nicht anmelden.
- ☐ B8 Handy-Breite (iPhone): Dashboard, Städte als Karten mit Sortier-Knöpfen, Stadtdetail,
  Super-Admins; Menü über ☰; kein waagerechtes Scrollen.
- ☐ B9 Update beim Deploy (Korrektur „Start wartet auf eine späte Registrierung“): einen Tab unter
  `/service/` offen lassen, deployen, dann in diesem Tab eine andere Seite öffnen bzw. neu laden:
  höchstens rund 30 s „Seite wird geladen …“, nie „Die neue Version konnte nicht geladen werden.“
  ☑ PC (Claude, 31.10.2026, Deploy `d4a4bcdc0ca8`, Tab mit `1e0bc9a58faa`): Registrierung kam nach
  4,2 s (`registration-late`), dann `skip-waiting`, Übernahme, Neuladen; neue Version nach rund 5 s,
  keine Meldung. Offen: iPhone als App.
