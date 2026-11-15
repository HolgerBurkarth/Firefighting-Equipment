# Einrichtung Immendingen – Plan zum Abhaken

Für Holger (Super-Admin bzw. Admin der Stadt). Stand: 15.11.2026, nach Runde 16. Grundlage sind Phils
Angaben; allgemeine Erklärungen stehen im [Merkblatt Phase 2](MERKBLATT-EINRICHTUNG-PHASE-2.md). Bezeichnungen
stehen so da, wie sie in der Oberfläche heißen.

Dieser Plan führt nichts aus. Agenten ändern in Immendingen nichts; jeder Schritt ist für Holger zum Abhaken.
Mit **⚠ offen** sind Punkte markiert, die noch geklärt werden müssen (Sammlung am Ende).

Reihenfolge: 1 Vorher → 2 Abläufe → 3 Standorte → 4 Ausrüstungstypen → 5 Erstbefüllung (CSV) → 6 Fahrzeuge
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
- [ ] Phils Excel-Liste liegt vor (siehe 5) – **⚠ offen**.
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
| Atemschutzgerät (PA) | PA01, PA07 … | vorhandenen Typ „Atemschutzgerät“ umbenennen |
| Atemschutzmaske (M) | M01, M07 … | vorhandenen Typ „Atemschutzmaske“ umbenennen |
| Ersatz-Lungenautomat (E-LA) | E-LA01, E-LA12 … | neu anlegen |
| Arbeitsluftflasche (PLF) | PLF… | neu anlegen |

- [ ] Typen umbenennen bzw. anlegen. Der Name muss später in der CSV-Datei genau so stehen (Groß-/Kleinschreibung
  egal).
- [ ] Nicht gebrauchte Start-Typen (Funkgerät, Wärmebildkamera) deaktivieren, wenn sie stören.
- [ ] Kästchen **Einmalgerät**: siehe unbedingt die folgende Warnung, bevor Sie es setzen.

> **⚠ Achtung – „nur benutzt melden“ und Werkstatt passen heute nicht zusammen.** Die Einschränkung der Rolle
> „Bei Einmalgeräten nur „Benutzt melden““ wirkt nur bei Typen mit dem Kästchen **Einmalgerät**. Einmalgeräte
> lassen sich aber auf der Seite **Wartung** nicht in die Werkstatt bringen („Einmalgeräte werden nicht gewartet,
> sondern ersetzt.“). PA, Masken und Lungenautomaten gehen in Immendingen nach der Benutzung zur ASW TUT – mit
> „Einmalgerät“ ginge das nicht mehr. Außerdem könnten Geräteträger bei diesen Typen dann auch keinen Status
> mehr setzen (z. B. „defekt“), nur „Benutzt melden“.
>
> **Empfehlung (ohne Programmänderung):** PA, M und E-LA **nicht** als Einmalgerät markieren und die Rolle
> Geräteträger **ohne** Einschränkung anlegen. Weil Geräteträger nicht umbuchen dürfen (siehe 8), bleibt ihnen
> ohnehin nur: „Benutzt melden“ (kommt nach „benutzt“), „Status setzen“ (z. B. defekt) und „Tauschen“. Das ist
> fast genau Phils Wunsch.
> Soll es wirklich „nur benutzt melden“ sein (kein „defekt“ am Fahrzeug), braucht es eine kleine Änderung:
> die Einschränkung an einem eigenen Kästchen des Typs statt an „Einmalgerät“ – Rückfrage im Bericht der Runde 16.

- **⚠ offen:** PLF – Einschränkung ja/nein (Rückfrage an Phil läuft). Ohne Einschränkung gilt für PLF dasselbe wie
  oben.

## 5. Erstbefüllung per CSV-Import (Admin)

Ort: Admin-Konsole → **Ausrüstung** → **Import (CSV)**. Vorher müssen Standorte (3) und Typen (4) bestehen; der
Import legt keine Standorte oder Typen an.

### 5.1 Vorlage holen
- [ ] „1. Vorlage ausfüllen“ → **Vorlage herunterladen** (`ausruestung-vorlage-immendingen.csv`) und
  **Gültige Werte herunterladen** (die genauen Namen der Typen und Standorte).
- Spalten der Vorlage: Nummer, Scan-Code, Ausrüstungstyp, Standort, Status, Nächste Wartung, Seriennummer,
  Hersteller, Modell, Beschaffungsdatum, Notiz. Spalten, die nicht gebraucht werden, dürfen fehlen (außer
  Nummer); für neue Ausrüstung sind **Ausrüstungstyp** und **Standort** Pflicht.
- [ ] Die zwei Beispielzeilen `BEISPIEL-1` und `BEISPIEL-2` löschen.

### 5.2 Phils Liste übertragen
Phils Excel hat Nummer, Seriennummer, Hersteller/Modell, nächste Prüfung – ohne Standort und ohne Typ.

- [ ] **Nummer**: so wie auf dem Etikett, ohne Leerzeichen (M07, PA07, E-LA12). Nur Buchstaben, Ziffern, `-`,
  `/`, `.`; höchstens 30 Zeichen. Achtung Excel: Nummern, die nur aus Ziffern bestehen oder wie ein Datum
  aussehen, vorher als **Text** formatieren (die Vorschau warnt davor).
- [ ] **Ausrüstungstyp**: aus dem Präfix der Nummer ergänzen (PA → „Atemschutzgerät (PA)“, M → „Atemschutzmaske
  (M)“, E-LA → „Ersatz-Lungenautomat (E-LA)“, PLF → „Arbeitsluftflasche (PLF)“). In Excel z. B. nach Nummer
  sortieren und blockweise ausfüllen.
- [ ] **Standort** – zwei Wege, **Vorschlag A**:
  - **A (empfohlen):** Alle Zeilen „Pool“. Den Fahrzeugbestand danach in der Ausrüstungsliste umbuchen (6).
    Einfach, und Phil muss nicht wissen, was gerade wo liegt.
  - **B:** Spalte vor dem Import richtig füllen („IMM 1/44“, „IMM 1/44 / Fahrerhaus“, „Pool“, „benutzt“ …,
    Schreibweise wie in „Gültige Werte“). Spart Schritt 6, braucht aber den aktuellen Stand.
- [ ] **Hersteller / Modell**: Steht beides in einer Spalte, trennen (Excel → Daten → **Text in Spalten**, z. B.
  am ersten Leerzeichen: „Dräger PSS 7000“ → Hersteller „Dräger“, Modell „PSS 7000“). Lässt es sich nicht sauber
  trennen, alles in **Modell** schreiben und **Hersteller** leer lassen – beides ist freiwillig und nur für die
  Suche und die Detailseite.
- [ ] **Nächste Wartung** (= nächste Prüfung): Format **TT.MM.JJJJ** (auch `T.M.JJJJ` und `JJJJ-MM-TT` gehen; keine
  zweistelligen Jahre). In Excel die Spalte als Datum mit Format `TT.MM.JJJJ` formatieren; leer = keine.
- [ ] **Status**: leer lassen (= einsatzbereit) oder „in Wartung“ / „defekt“ / „benutzt“, wenn bekannt.
- [ ] **Scan-Code** (Barcode/QR an den Geräten) – **⚠ offen**, was er enthält:
  - Enthält er die **Seriennummer**: Spalte Scan-Code = Kopie der Spalte Seriennummer (Excel: Spalte kopieren).
    Achtung: lange Ziffernfolgen zeigt Excel als `4,00638E+12` – Spalte vorher als **Text** formatieren (die
    Vorschau warnt bei wissenschaftlicher Schreibweise).
  - Enthält er die **Nummer** (z. B. „PA07“): Scan-Code leer lassen. Die Suche und „Code scannen“ finden die
    Ausrüstung dann schon über die Nummer.
  - Etwas anderes (z. B. GS1-Code des Herstellers): Scan-Code leer lassen und später je Gerät auf der Detailseite
    mit **Code zuordnen** (Handscanner oder Kamera) einlesen.
- [ ] Speichern als **CSV UTF-8 (durch Trennzeichen getrennt)** (Excel: „Speichern unter“). Ein Excel-CSV mit
  Semikolon in Windows-Kodierung geht ebenfalls; die App erkennt Kodierung und Trennzeichen.

### 5.3 Prüfen und übernehmen
- [ ] „2. Datei prüfen“ → **CSV-Datei wählen …**. Die Vorschau zeigt Fehler, Warnungen und „Unbekannte Werte“
  (mit „Meinten Sie …?“). Fehler in Excel beheben, **Erneut prüfen**.
- [ ] Erst ohne Fehler: Kommentar „Erstbefüllung aus Phils Liste“ und **Import übernehmen (N neu, …)**.
- [ ] Stichprobe in der Ausrüstungsliste: Anzahl je Typ stimmt mit Phils Liste.

## 6. Fahrzeuge bestücken (nur bei Weg A)

Ort: Admin-Konsole → **Ausrüstung**.
- [ ] Filter Standort „Pool“, Suche bzw. Typ eingrenzen, die Geräte eines Fahrzeugs ankreuzen.
- [ ] **Umbuchen / Status ändern …** → Neuer Standort „IMM 1/44“ (bzw. „IMM 1/44 / Fahrerhaus“), Status nicht
  ändern, Kommentar „Bestückung“. Für IMM 1/45 ebenso.
- [ ] Was gerade benutzt ist bzw. in Tuttlingen liegt, nach „benutzt“ bzw. „ASW TUT“ buchen.

## 7. Sollbestand (Admin)

Ort: Admin-Konsole → **Standorte** → unten **Sollbestand festlegen …** (oder Knopf „Sollbestand“ oben).
- [ ] IMM 1/44: Regel **mindestens 6** „Atemschutzmaske (M)“; Regel **mindestens 4** „Atemschutzgerät (PA)“.
- [ ] IMM 1/45: dieselben zwei Regeln.
- [ ] Danach beide Fahrzeuge „vollständig“ (sonst fehlt Ausrüstung im Fahrzeug, siehe 6).
- Wirkung: Jede Buchung, die den einsatzbereiten Bestand eines Fahrzeugs unter die Zahl drückt, lehnt der Server
  ab und bietet **Tauschen …** an („Benutzt melden“ und „Status setzen“ eingeschlossen). „Trotzdem buchen …“ gibt
  es nur mit der Aktion „Sollbestand ohne Ersatz unterschreiten“ – die bekommt keine eigene Rolle.
- Grenzen (bitte bewusst sein): Die Rolle **Admin** hat immer alle Rechte, also auch „Trotzdem buchen“ (Phil,
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
- [ ] „3 · Einschränkungen“: nach der Empfehlung in 4 **keine** (**⚠ offen**, Rückfrage Runde 16).
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
2. **PLF**: Einschränkung „nur benutzt melden“ ja/nein (Rückfrage an Phil) (4).
3. **„Nur benutzt melden“ und Werkstatt**: Empfehlung ohne Einmalgerät/ohne Einschränkung, oder Programmänderung
   (eigenes Kästchen am Typ) (4, 8).
4. **Inhalt des Barcodes/QR** an den Geräten: Nummer, Seriennummer oder etwas anderes (5.2).
5. **Excel-Liste** von Phil (5).
6. **Datenschutz bei der Gemeinde** (Verantwortlicher, Information der Aktiven, siehe
   `docs/DATENSCHUTZ-BESTANDSAUFNAHME.md`) – vor dem Einladen der Aktiven.
7. „Trotzdem buchen“ bleibt für Admins (Phil, Holger) möglich, und offline wird nie abgelehnt – so in Ordnung? (7)
