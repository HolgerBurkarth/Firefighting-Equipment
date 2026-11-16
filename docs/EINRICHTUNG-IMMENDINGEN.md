# Einrichtung Immendingen – Plan zum Abhaken

Für Holger (Super-Admin bzw. Admin der Stadt). Stand: 16.11.2026, nach Runde 16 (Importdatei liegt vor). Grundlage sind Phils
Angaben; allgemeine Erklärungen stehen im [Merkblatt Phase 2](MERKBLATT-EINRICHTUNG-PHASE-2.md). Bezeichnungen
stehen so da, wie sie in der Oberfläche heißen.

Dieser Plan führt nichts aus. Agenten ändern in Immendingen nichts; jeder Schritt ist für Holger zum Abhaken.
Mit **⚠ offen** sind Punkte markiert, die noch geklärt werden müssen (Sammlung am Ende).

Reihenfolge: 1 Vorher → 2 Abläufe → 3 Standorte → 4 Ausrüstungstypen → 5 Erstbefüllung (CSV) → 6 Verleihen und Fahrzeuge
bestücken → 7 Sollbestand → 8 Rollen → 9 Konten → 10 Mails → 11 Rückgabe-Aushang → 12 Probelauf → 13 Einweisung.
Der Sollbestand kommt bewusst nach dem Bestücken der Fahrzeuge (sonst stehen die Fahrzeuge bis dahin als
„außer Betrieb“ da).

---

## 1. Vorher

- [ ] Version prüfen: `https://www.prodad.de/firefighting/immendingen/build.json` – `build` muss mindestens der
  Stand von Runde 16 sein (Rückgabe direkt in die Sammelstelle).
- [ ] Sicherung der Datenbank (per SSH):
  `cd ~/htdocs/apps/firefighting-equipment && php85 bin/db-dump.php --suffix=vor-einrichtung-immendingen`
- [ ] Holger ist in Immendingen als **Admin** angemeldet (Admin-Konsole), als Super-Admin getrennt unter
  `https://www.prodad.de/firefighting/service/`.
- [x] Importdatei liegt vor (siehe 5): von Claude aus Phils 112-Datenbank erstellt, liegt bei Holger, nicht im
  Repository (echte Daten).
- [ ] Datenschutz bei der Gemeinde geklärt (siehe Sammlung) – **⚠ offen**, vor dem Einladen der Aktiven.

## 2. Abläufe freischalten (Super-Admin)

Ort: `…/firefighting/service/` → **Städte** → **Immendingen** → Reiter **Abläufe**.

- [ ] **Lager** einschalten.
- [ ] **Sammelstelle** einschalten.
- [ ] **Werkstatt** einschalten.
- [ ] **Ausgeliehen an** einschalten.
- [ ] **Rückgabe** NICHT einschalten. Immendingen hat keinen eigenen Rückgabe-Platz; zurückgebrachte Ausrüstung
  kommt direkt in die Sammelstelle „benutzt“ (Aushang an der Sammelstelle, siehe 11, seit Runde 16 möglich). Die
  Seite **Verleih** gibt es trotzdem (sie kommt mit „Ausgeliehen an“).
- [ ] Anzeigenamen: Standard lassen (oder nach Wunsch ändern).
- [ ] „Die Abläufe sind gespeichert.“ erscheint.

## 3. Standorte anlegen (Admin)

Ort: Admin-Konsole → **Standorte**. Anlegen mit „Neuer Standort“, Fach mit „Fach in … anlegen“, Aufgabe im
Fenster „… bearbeiten“ (Feld **Aufgabe**, nur Ebene 1).

| Standort | Art | Aufgabe | Weitere Angabe |
|---|---|---|---|
| Pool | Raum/Lager | Lager | Kästchen **Standard-Lager** (das erste Lager wird es von selbst) |
| benutzt | Raum/Lager | Sammelstelle | **Nimmt an**: benutzte und defekte |
| ASW TUT | Extern | Werkstatt | (Atemschutzzentrum Tuttlingen) |
| Hattingen | Extern | Ausgeliehen an | |
| Ippingen | Extern | Ausgeliehen an | |
| IMM 1/44 | Fahrzeug | keine | Fach **Fahrerhaus** |
| IMM 1/45 | Fahrzeug | keine | Fach **Fahrerhaus** |

Weitere Fahrzeuge gibt es in Immendingen nicht (Angabe 16.11.2026).

- [ ] Pool angelegt, Aufgabe Lager, Standard-Lager.
- [ ] benutzt angelegt, Aufgabe Sammelstelle, nimmt benutzte und defekte an. Damit kommen „Benutzt melden“,
  Tausch und Rückgaben ohne Anmeldung hierher.
- [ ] ASW TUT angelegt, Aufgabe Werkstatt.
- [ ] Hattingen und Ippingen angelegt, je Aufgabe Ausgeliehen an.
- [ ] IMM 1/44 mit Fach Fahrerhaus, IMM 1/45 mit Fach Fahrerhaus. Buchen auf das ganze Fahrzeug („Ganzes
  Fahrzeug, ohne Fach“) bleibt erlaubt; der Sollbestand zählt das Fahrerhaus mit.
- [ ] Gibt es schon Standorte aus der Probezeit, die nicht mehr gebraucht werden: leeren und sperren (Stift →
  „… sperren …“; geht nur, wenn leer).
- **⚠ offen:** Zuordnung LF 20 / KatS zu IMM 1/44 bzw. 1/45 (Name der Fahrzeuge ggf. ergänzen, z. B.
  „IMM 1/44 (LF 20)“; das Umbenennen geht jederzeit).

## 4. Ausrüstungstypen (Admin)

Ort: Admin-Konsole → **Ausrüstungstypen**. Vorhanden sind die Start-Typen Atemschutzmaske, Atemschutzgerät,
Funkgerät, Wärmebildkamera.

| Typ | Nummern | Vorschlag |
|---|---|---|
| Atemschutzgerät (PA) | PA01 … (26 Stück) | vorhandenen Typ „Atemschutzgerät“ umbenennen |
| Atemschutzmaske (M) | M01 … (92 Stück) | vorhandenen Typ „Atemschutzmaske“ umbenennen |
| Ersatz-Lungenautomat (E-LA) | E-LA01 … (20 Stück) | neu anlegen |
| Arbeitsluftflasche (PLF) | PLF38–PLF43 (6 Stück, nur die Arbeitsluftflaschen) | neu anlegen |

- [ ] Typen umbenennen bzw. anlegen. Der Name muss in der Importdatei genau so stehen (Groß-/Kleinschreibung
  egal); vor dem Import mit der Spalte Ausrüstungstyp der Datei vergleichen.
- [ ] **Wärmebildkamera** deaktivieren (spielt in Immendingen keine Rolle). Funkgerät ebenso, wenn es stört.
- [ ] Kästchen **Einmalgerät**: bei PA, M, E-LA und PLF **nicht** setzen (entschieden, siehe unten).

> **Entschieden (Holger, 15.11.2026, Runde 16): „Nur benutzt melden“ wird für PA, M, E-LA und PLF nicht
> eingerichtet** – kein Einmalgerät, keine Einschränkung der Rolle. Die Rechte der Geräteträger (Benutzt melden,
> Status setzen, Tauschen, kein Umbuchen, siehe 8) reichen. Hintergrund:
>
> „Nur benutzt melden“ und Werkstatt passen heute nicht zusammen. Die Einschränkung der Rolle
> „Bei Einmalgeräten nur „Benutzt melden““ wirkt nur bei Typen mit dem Kästchen **Einmalgerät**. Einmalgeräte
> lassen sich aber auf der Seite **Wartung** nicht in die Werkstatt bringen („Einmalgeräte werden nicht gewartet,
> sondern ersetzt.“). PA, Masken und Lungenautomaten gehen in Immendingen nach der Benutzung zur ASW TUT – mit
> „Einmalgerät“ ginge das nicht mehr. Außerdem könnten Geräteträger bei diesen Typen dann auch keinen Status
> mehr setzen (z. B. „defekt“), nur „Benutzt melden“.
>
> Ohne Einmalgerät und ohne Einschränkung bleibt Geräteträgern, weil sie nicht umbuchen dürfen, ohnehin nur:
> „Benutzt melden“ (kommt nach „benutzt“), „Status setzen“ (z. B. defekt) und „Tauschen“. Eine Programmänderung
> (eigenes Kästchen am Typ statt „Einmalgerät“) ist damit nicht nötig.

- PLF: wie PA, M und E-LA kein Einmalgerät, keine Einschränkung (entschieden, Holger 15.11.2026). In der App
  nur die 6 Arbeitsluftflaschen; Sollbestand 1 PLF auf IMM 1/44 (siehe 7).

## 5. Erstbefüllung per CSV-Import (Admin)

Ort: Admin-Konsole → **Ausrüstung** → **Import (CSV)**. Vorher müssen Standorte (3) und Typen (4) bestehen; der
Import legt keine Standorte oder Typen an.

### 5.1 Die Importdatei
Die Datei hat Claude aus Phils 112-Datenbank erstellt; sie liegt bei Holger, nicht im Repository (echte Daten).
Inhalt: **144 Ausrüstungen** – 92 M, 26 PA, 20 E-LA, 6 PLF.

- **Nummer** in Phils Form: M01 …, PA01 …, E-LA01 …, PLF38–PLF43.
- **Ausrüstungstyp** wie in 4.
- **Standort**: alle „Pool“ (Verleih und Fahrzeuge folgen danach, 6).
- **Status**: einsatzbereit.
- **Scan-Code** = Barcode der 112-Datenbank (8 Ziffern mit führender 0, z. B. `01332711`); vorhanden bei Masken
  und PA, leer bei E-LA und PLF. Masken und PA findet „Code scannen“ damit über den Barcode, E-LA und PLF über die
  Nummer.
- **Beschaffungsdatum** = Indienststellung laut 112-Datenbank.
- **Notiz**: die letzten Prüfungen laut 112-Datenbank.
- **Nächste Wartung**: leer. Die Prüffristen legt Immendingen später fest; dann in der Ausrüstungsliste je Typ
  filtern, alle auswählen und **Wartungsdatum setzen …** (Sammelaktion).

> **Achtung: Die Datei nicht in Excel öffnen und speichern.** Excel macht aus `01332711` die Zahl `1332711`
> (führende Null weg); der Scan-Code passte dann nicht mehr zum Barcode, und die Vorschau kann das bei neuer
> Ausrüstung nicht erkennen. Ansehen höchstens im Editor (z. B. Notepad). Muss doch etwas geändert werden: in
> Excel über Daten → **Aus Text/CSV** mit der Spalte Scan-Code als **Text** laden, oder Claude die Datei neu
> erstellen lassen.

### 5.2 Vor dem Import
- [ ] Standorte (3) und Typen (4) bestehen; die Typnamen in der Datei stimmen mit 4 überein.
- [ ] Optional zum Abgleich: „1. Vorlage ausfüllen“ → **Gültige Werte herunterladen** (genaue Namen der Typen und
  Standorte).

### 5.3 Prüfen und übernehmen
- [ ] „2. Datei prüfen“ → **CSV-Datei wählen …**. Die Vorschau zeigt Fehler, Warnungen und „Unbekannte Werte“
  (mit „Meinten Sie …?“). Erwartet: **144 neu**, keine Fehler. Unbekannte Typen bzw. Standorte in der App
  anlegen bzw. umbenennen (nicht die Datei in Excel ändern, siehe 5.1), dann **Erneut prüfen**.
- [ ] Erst ohne Fehler: Kommentar „Erstbefüllung aus der 112-Datenbank“ und **Import übernehmen (144 neu)**.
- [ ] Stichprobe in der Ausrüstungsliste: 92 M, 26 PA, 20 E-LA, 6 PLF, alle in „Pool“. Bei einer Maske die
  Detailseite öffnen: Scan-Code mit führender 0 (8 Ziffern), Beschaffungsdatum und Notiz da.

## 6. Nach dem Import: verleihen und Fahrzeuge bestücken

### 6.1 Verliehene PA an Hattingen und Ippingen
Diese PA sind derzeit in den Ortsteilen und kommen nach Benutzung zurück. Der Import kennt keinen Verleih (siehe
Kasten), deshalb über die Seite **Verleih**:
- [ ] Admin-Konsole → **Verleih** → **Verleihen …** → Nummern **PA14, PA19, PA20, PA21, PA22** (Nummer bzw.
  Scan-Code eingeben, Enter), Leihnehmer **Ippingen**, Kommentar „Bestand bei Einrichtung“; „Rückgabe bis“ leer.
- [ ] Ebenso **PA15, PA16, PA17, PA18** an **Hattingen**.
- [ ] Die Seite **Verleih** zeigt 9 offene Verleihe (5 Ippingen, 4 Hattingen).

> **Geprüft (16.11.2026, Code): Der Import legt keinen Verleih an.** Eine Zeile mit Standort „Hattingen“ würde die
> Ausrüstung nur dorthin buchen (Ereignis „Importiert“); ein Verleih (Eintrag in der Verleihliste) entsteht nur über
> **Verleihen …** (`POST loans`). Ebenso entsteht durch gewöhnliches Umbuchen nach „Hattingen“ kein Verleih. Folgen
> ohne Verleih: Die Ausrüstung fehlt auf der Seite **Verleih**, und die Rückgabe über den Aushang (11) lehnt sie ab
> („… ist nicht verliehen …“), weil nur verliehene Ausrüstung zurückgegeben werden kann. Der Weg über
> **Verleihen …** bleibt also nötig; deshalb stehen alle Zeilen der Datei auf „Pool“.

### 6.2 Fahrzeuge bestücken
Ort: Admin-Konsole → **Ausrüstung**.
- [ ] Filter Standort „Pool“, Suche bzw. Typ eingrenzen, die Ausrüstung eines Fahrzeugs ankreuzen.
- [ ] **Umbuchen / Status ändern …** → Neuer Standort „IMM 1/44“ (bzw. „IMM 1/44 / Fahrerhaus“), Status nicht
  ändern, Kommentar „Bestückung“. Für IMM 1/45 ebenso.
- [ ] Auf IMM 1/44 zusätzlich mindestens **1 PLF** (Sollbestand, siehe 7).
- [ ] Was gerade benutzt ist bzw. in Tuttlingen liegt, nach „benutzt“ bzw. „ASW TUT“ buchen.

## 7. Sollbestand (Admin)

Ort: Admin-Konsole → **Standorte** → unten **Sollbestand festlegen …** (oder Knopf „Sollbestand“ oben).
- [ ] IMM 1/44: Regel **mindestens 6** „Atemschutzmaske (M)“; Regel **mindestens 4** „Atemschutzgerät (PA)“.
- [ ] IMM 1/44: zusätzlich Regel **mindestens 1** „Arbeitsluftflasche (PLF)“ (Angabe Phil, 16.11.2026).
- [ ] IMM 1/45: Regeln 6 M und 4 PA (keine PLF-Regel).
- [ ] Danach beide Fahrzeuge „vollständig“ (sonst fehlt Ausrüstung im Fahrzeug, siehe 6.2).
- Wirkung: Jede Buchung, die den einsatzbereiten Bestand eines Fahrzeugs unter die Zahl drückt, lehnt der Server
  ab und bietet **Tauschen …** an („Benutzt melden“ und „Status setzen“ eingeschlossen). „Trotzdem buchen …“ gibt
  es nur mit der Aktion „Sollbestand ohne Ersatz unterschreiten“ – die bekommt keine eigene Rolle.
- Grenzen (von Holger am 15.11.2026 als in Ordnung bestätigt): Die Rolle **Admin** hat immer alle Rechte, also auch „Trotzdem buchen“ (Phil,
  Holger); das lässt sich nicht abschalten. Und **offline** gebuchte Vorgänge lehnt der Server nie ab (die
  Wirklichkeit gilt), es entsteht dann ein Hinweis „Sollbestand unterschritten“ im Protokoll.
- **⚠ offen:** Zuordnung LF 20 / KatS (gleiche Zahlen für beide Fahrzeuge?).

## 8. Rollen anlegen (Super-Admin)

Ort: `…/service/` → **Städte** → **Immendingen** → Reiter **Rollen** → **Neue Rolle**. *Admin* ist fest
(alle Rechte). *Benutzer* bleibt unverändert und wird in Immendingen nicht gebraucht.

### Gerätewart (Nils, Paddy, Sven; Phil ist Admin)
- [ ] Name „Gerätewart“, Kurzbeschreibung „Verwaltet die Ausrüstung, keine Konten“.
- [ ] „1 · Ansichten und Bereiche“:
  - **Ändern**: Ausrüstungsliste, Standorte, Ausrüstungstypen, Termine, Protokoll und Hinweise, Wartung,
    Verleih und Rückgaben.
  - **Ansehen**: Dashboard, Suchen und Ausrüstung ansehen.
  - **Kein Zugriff**: Benutzer, Einstellungen der Stadt.
- [ ] „2 · Aktionen“: Umbuchen, Status setzen, Benutzt melden, Tauschen, Ausrüstung anlegen, Stammdaten
  bearbeiten, **Aussondern und wieder aufnehmen**, Code zuordnen, Sammelaktionen, CSV-Import, CSV-Export,
  Log-Mails empfangen. **Nicht**: „Sollbestand ohne Ersatz unterschreiten“.
- [ ] „3 · Einschränkungen“: keine. „4 · Sieht nur …“: Alle / Alle.

### Geräteträger (alle Aktiven)
- [ ] Name „Geräteträger“, Kurzbeschreibung „Meldet Benutzung, setzt Status, tauscht“.
- [ ] „1 · Ansichten und Bereiche“: **Ansehen**: Suchen und Ausrüstung ansehen, Termine. Alles andere kein Zugriff
  (damit gibt es kein Konsolen-Menü, nur die Handy-Seiten).
- [ ] „2 · Aktionen“: **Status setzen**, **Benutzt melden**, **Tauschen**. **Nicht**: Umbuchen, „Sollbestand ohne
  Ersatz unterschreiten“.
- [ ] „3 · Einschränkungen“: **keine** (entschieden 15.11.2026, siehe 4).
- [ ] „4 · Sieht nur …“: Alle / Alle.

Geprüft in Runde 16 (Code und Tests): Eine Rolle mit „Tauschen“ und „Status setzen“ ohne „Umbuchen“ funktioniert
vollständig. Die Detailseite zeigt dann nur die Statusknöpfe (keinen Standort), dazu „Benutzt melden“ und
„Tauschen …“. Der Tausch bucht beide Geräte selbst um (der Server verlangt dafür nur „Tauschen“): die alte
Ausrüstung nach „benutzt“ (Zustand benutzt, defekt oder in Wartung), den einsatzbereiten Ersatz an den bisherigen
Platz – auch offline.

## 9. Konten

- [ ] **Admins** (Phil, Holger): Phil als Admin einladen, falls noch nicht (`…/service/` → Immendingen →
  „Admin einladen …“).
- [ ] **Einladungscodes** (Admin-Konsole → **Benutzer** → Reiter **Einladungscodes** → „Neuer Einladungscode“):
  einen Code mit **Rolle nach der Freischaltung = Geräteträger** für den Dienstabend (Gültigkeit z. B. 14 Tage,
  Registrierungen z. B. 60), Aushang drucken.
- [ ] Gerätewarte (Nils, Paddy, Sven): registrieren sich mit demselben Code und bekommen danach im Fenster
  „Bearbeiten“ die Rolle **Gerätewart** (oder ein eigener Code mit Rolle Gerätewart).
- [ ] Nach der Registrierung: Benutzer → „N neue Anmeldungen warten auf Freischaltung“ → **Freischalten …**
  (Anzeigename prüfen).

## 10. Mails

Jede Person stellt es selbst ein: Profil bzw. Einstellungen → „Benachrichtigung“ → Kästchen **Mail bei jeder
Änderung an der Ausrüstung** → **Nur ausgewählte** → Thema → **Auswahl speichern**.
- [ ] Phil (Admin): nur **Defekt gemeldet**.
- [ ] Paddy (Gerätewart): nur **Defekt gemeldet**.
- [ ] Alle anderen: aus (Standard).
- Bei Rückgaben gibt es ohnehin keine Mail (Rückgabe ohne Anmeldung löst keine aus, T23). „Defekt gemeldet“ gilt
  auch für eine Rückgabe mit Zustand „defekt“ nicht (kommt ohne Anmeldung); defekt zurückgegebene Ausrüstung
  sieht man auf der Seite **Wartung** in „benutzt“.

## 11. Rückgabe-Aushang an der Schleuse (Admin)

Ort: Admin-Konsole → **Verleih** → Abschnitt **Rückgabe-Aushang**. Dort steht „benutzt“ mit dem Zusatz
**Sammelstelle**.
- [ ] Bei „benutzt“ **Aushang erzeugen …** → **Aushang drucken** (Link und QR-Code erscheinen nur einmal).
- [ ] Aushang an der **Schleuse** aufhängen. Zuständig: **Atemschutzgerätewart** (erneuert ihn, wenn er
  verschmutzt, verloren oder in falsche Hände geraten ist: „Aushang erneuern …“; der alte gilt dann nicht mehr).
- So läuft es: Wer verliehene Ausrüstung aus Hattingen oder Ippingen zurückbringt, scannt den QR-Code, gibt Nummer
  und Namen ein und wählt **Benutzt** (vorgewählt) oder **Defekt** – „In Ordnung“ gibt es an der Sammelstelle
  nicht (alles dort prüft der Gerätewart). Die Ausrüstung steht danach in „benutzt“, der Verleih ist beendet, der
  Verlauf nennt „<Name> (ohne Anmeldung)“. Weiter geht es auf der Seite **Wartung** (in die Werkstatt bzw. ins
  Lager bringen).

## 12. Probelauf (vor dem Dienstabend)

Mit einem Gerätewart- und einem Geräteträger-Konto, an einem Gerät, das danach wieder zurückgesetzt wird:
- [ ] Geräteträger am Handy: Maske im Fahrzeug suchen (Nummer, z. B. „M07“), **Benutzt melden** → wird abgelehnt,
  wenn das Fahrzeug genau 6 Masken hat → **Tauschen …** mit Ersatz aus „Pool“ → beide richtig gebucht.
- [ ] Geräteträger: Status „defekt“ an einer Ausrüstung im Pool (kein Sollbestand) → geht; Phil und Paddy bekommen
  die Mail „Defekt gemeldet“.
- [ ] Gerätewart: Seite **Wartung** → „In die Werkstatt bringen …“ (ASW TUT) → „Ins Lager bringen …“ (Pool).
- [ ] Verleih an Hattingen → Rückgabe über den Aushang an der Schleuse → Ausrüstung in „benutzt“, Verleih beendet.
- [ ] Aussondern mit einem Gerätewart-Konto (Detailseite → „Aussondern …“) an einem Testgerät und wieder aufnehmen.
- [ ] Die Testbuchungen zurücksetzen (Status/Standort wie vorher).

## 13. Einweisung am Dienstabend

- [ ] Alle Aktiven an einem Dienstabend: Einladungscode-Aushang (9), App aufs Handy legen.
- [ ] Anleitungen ausdrucken bzw. verteilen (`docs/anleitungen/`):
  `anleitung-geraetetraeger.pdf` (alle Aktiven), `anleitung-geraetewart.pdf` (Nils, Paddy, Sven, Phil),
  `anleitung-admin.pdf` (Phil). `anleitung-benutzer.pdf` wird in Immendingen nicht gebraucht.
- [ ] Vorführen: Ausrüstung suchen (auch per „Code scannen“), Benutzt melden, Tauschen, Status setzen.
- [ ] Hinweis: Nummer am besten ohne Leerzeichen eingeben, wie auf dem Etikett („PA07“). „PA 07“ findet die
  Ausrüstung zwar auch, aber womöglich zusammen mit anderen Treffern (siehe Bericht Runde 16).

---

## Sammlung: offene Punkte

1. **Zuordnung LF 20 / KatS** zu IMM 1/44 und IMM 1/45 und ob der Sollbestand (6 M, 4 PA) für beide gilt (3, 7).
2. **Prüffristen** (Nächste Wartung) legt Immendingen später fest; danach per Sammelaktion setzen (5.1).
3. **Datenschutz bei der Gemeinde** (Verantwortlicher, Information der Aktiven, siehe
   `docs/DATENSCHUTZ-BESTANDSAUFNAHME.md`) – vor dem Einladen der Aktiven.

Erledigt (Holger, 15.11.2026): „Nur benutzt melden“ für PA, M, E-LA und PLF wird nicht eingerichtet (4, 8);
„Trotzdem buchen“ für Admins und dass offline nie abgelehnt wird, ist in Ordnung (7).

Erledigt (16.11.2026): Importdatei aus der 112-Datenbank liegt vor (5); Scan-Code = Barcode der 112-Datenbank
(5.1); PLF nur die 6 Arbeitsluftflaschen, Sollbestand 1 PLF auf IMM 1/44 (7); nur die Fahrzeuge 1/44 und 1/45,
Wärmebildkamera deaktivieren (3, 4); verliehene PA über „Verleihen …“, weil der Import keinen Verleih anlegt (6.1).
