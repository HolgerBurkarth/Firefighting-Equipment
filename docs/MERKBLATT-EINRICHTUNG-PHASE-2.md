# Merkblatt: Einrichtung der Abläufe (Phase 2) in einer Stadt

Für Holger als Admin bzw. Super-Admin. Gilt für jede Stadt; Beispiel ist **Immendingen**
(Kürzel `immendingen`). Stand: 09.11.2026. Beschrieben ist nur, was es gibt; Bezeichnungen
stehen so da, wie sie in der Oberfläche heißen.

Mit **⚠ Entscheidung** sind Stellen markiert, an denen Sie festlegen müssen, wie es in der Stadt
sein soll (Sammlung am Ende).

Grundsatz: Solange der Super-Admin keine Aufgabe freischaltet, ändert sich für die Stadt nichts.
Alles unten lässt sich Schritt für Schritt einschalten und (mit den genannten Grenzen) wieder
zurücknehmen.

---

## 1. Vorher

- [ ] **Sicherung der Datenbank** (auf dem Server, per SSH):
  `cd ~/htdocs/apps/firefighting-equipment && php85 bin/db-dump.php --suffix=vor-einrichtung-immendingen`
  Die Datei liegt unter `~/htdocs/apps/_backup/`. Das Deploy sichert nur bei offenen Migrationen
  von selbst; für die Einrichtung bitte von Hand.
- [ ] **Welche Version ist live?** `https://www.prodad.de/firefighting/immendingen/build.json`
  öffnen; `build` sind die ersten 12 Zeichen des Commits. Mit dem letzten „✅ FERTIG“ vergleichen.
  Dazu `https://www.prodad.de/firefighting/service/api/v1/health` → `{"status":"ok","database":"ok"}`.
- [ ] **Letzten nächtlichen CI-Lauf** ansehen (grün?).
- [ ] **Information**: Wer wird wann informiert (Phil)? Zeitpunkt festlegen, an dem niemand mitten
  in einer Buchung ist (z. B. nicht während einer Übung oder eines Einsatzes). Die Apps der Nutzer
  holen die neuen Rechte beim nächsten Öffnen von selbst; sie müssen nichts tun.
- [ ] Es gibt einen Admin, der angemeldet ist und die Stadt kennt (Sie selbst als Admin; als
  Super-Admin melden Sie sich getrennt unter `…/firefighting/service/` an).

## 2. Super-Admin: Reiter „Abläufe“

Ort: `…/firefighting/service/` → **Städte** → Stadt **Immendingen** → Reiter **Abläufe**
(„Aufgaben für Standorte“). Je Aufgabe ein Schalter und ein Anzeigename. Der Name erscheint in der
Oberfläche der Stadt (Standard in Klammern).

| Aufgabe | Was sie bewirkt |
|---|---|
| **Lager** | Ein Standort ist ein Lager. Das **Standard-Lager** ist das Ziel von „Zurück ins Lager“ (Wartung) und „In Ordnung ins Lager“ (Rückgabe). Das erste Lager wird automatisch zum Standard-Lager. |
| **Sammelstelle** | Hierhin kommt benutzte oder defekte Ausrüstung („Benutzt melden“, Tausch, „Benutzte und Defekte in die Sammelstelle“). Schaltet die Seite **Wartung** frei. |
| **Werkstatt** | Ziel von „in die Werkstatt“ (Status „in Wartung“). Schaltet ebenfalls die Seite **Wartung** frei. |
| **Rückgabe** | Standort, an dem zurückgebrachte, verliehene Ausrüstung ankommt (Aushang mit QR-Code, siehe 7). Schaltet die Seite **Verleih** frei. |
| **Ausgeliehen an** | Standort eines Leihnehmers. Wer dorthin verliehen wird, bekommt einen Eintrag im Verleih (Ansprechpartner, „Rückgabe bis“). Schaltet die Seite **Verleih** frei. |

Hinweise:
- Die Seiten **Wartung** und **Verleih** (Menü, Rechte „Wartung“ bzw. „Verleih und Rückgaben“) gibt
  es erst, wenn die zugehörige Aufgabe freigeschaltet ist; vorher sieht sie auch ein Admin nicht.
- Das Freischalten ändert noch keine Buchung und keinen Standort.
- Nach dem Speichern erscheint „Die Abläufe sind gespeichert.“. Die Rechte der Konten ändern sich
  dadurch (neue Bereiche); die Apps laden sie beim nächsten Aufruf.
- **⚠ Entscheidung:** Welche Aufgaben braucht Immendingen zum Start? Naheliegend: alle fünf. Wer
  nur „Benutzt melden“ und Sollbestand will, braucht **Sammelstelle** (und **Lager** als Ziel von
  „Zurück“); Verleih/Rückgabe kann später folgen.
- **⚠ Entscheidung:** Eigene Anzeigenamen (z. B. „Gerätehaus-Lager“ statt „Lager“)?

## 3. Admin: Standorte zuweisen

Ort: Admin-Konsole → **Standorte** → Stift am Standort („… bearbeiten“) → Feld **Aufgabe**.
Nur Standorte der **Ebene 1** (Fahrzeug, Raum, Extern, nicht Fächer) können eine Aufgabe tragen;
angeboten werden nur freigeschaltete Aufgaben, sonst „Keine Aufgabe“.

- [ ] **Lager**: den Raum wählen, in dem einsatzbereite Ersatzausrüstung liegt. Kästchen
  **Standard-Lager** (höchstens eines je Stadt; das erste Lager wird es automatisch). Ohne
  Standard-Lager meldet „Zurück ins Lager“: „Es gibt kein Standard-Lager.“
- [ ] **Sammelstelle**: Raum, in dem Benutzte/Defekte landen. Auswahl **Nimmt an**:
  *benutzte und defekte* (eine Sammelstelle für alles), *nur benutzte* oder *nur defekte*.
  Gibt es getrennte Stellen, legen Sie zwei Sammelstellen an. „Benutzt melden“ bucht in die
  Sammelstelle, die Benutzte annimmt (bei mehreren die mit der kleinsten ID); ohne Sammelstelle
  bleibt der Standort.
- [ ] **Werkstatt**: Raum bzw. Extern, in dem gewartet wird (z. B. Atemschutzwerkstatt).
- [ ] **Rückgabe**: Standort, an dem zurückgebrachte Ausrüstung ankommt, z. B. ein Regal im
  Gerätehaus. Je Rückgabe-Standort gibt es einen eigenen Aushang.
- [ ] **Ausgeliehen an**: je Leihnehmer ein Standort der Ebene 1 (z. B. „Nachbarwehr Tuttlingen“,
  „Kreisfeuerwehrverband“). Art *Extern* bietet sich an.

Grenzen:
- Eine Aufgabe lässt sich vom Super-Admin nur **abschalten**, wenn **kein Standort** sie mehr
  trägt (sonst Fehler 409). Zuerst bei den Standorten auf „Keine Aufgabe“ stellen.
- „Ausgeliehen an“ lässt sich von einem Standort nicht entfernen, solange dort verliehene
  Ausrüstung ist.
- Gesperrte Standorte können keine Ziele sein; ein Standort lässt sich nur sperren, wenn er leer ist.
- **⚠ Entscheidung:** Welche Räume sind Lager, Sammelstelle, Werkstatt, Rückgabe? Wer sind die
  Leihnehmer?

## 4. Ausrüstungstypen: Einmalgerät

Ort: Admin-Konsole → **Ausrüstungstypen** → Stift → Kästchen **Einmalgerät** („wird nach dem
Einsatz ersetzt statt gewartet“).

Folgen:
- Einmalgeräte werden **nicht gewartet** (auf der Seite Wartung nicht wählbar, „in die Werkstatt“
  ist ein Fehler).
- Rollen mit der Einschränkung **„Bei Einmalgeräten nur „Benutzt melden““** (siehe 6) sehen bei
  einem Einmalgerät weder Standort noch Status noch „Speichern“, nur **Benutzt melden** (mit
  Rückfrage „… als benutzt melden? Es kommt in die Sammelstelle …“). Das gilt auch für die
  Sammelaktionen und offline.
- Als Einmalgerät markieren heißt nicht, dass vorhandene Ausrüstung umgebucht wird; es ändert nur
  das Verhalten ab jetzt. Zurücknehmen: Kästchen wieder entfernen.

Mögliche Kandidaten (nur Beispiele, bitte prüfen): Atemschutzfilter, Einweg-Schutzanzüge,
Einmalhandschuhe, Verbandmaterial. Atemschutzmasken, Atemschutzgeräte und Funkgeräte sind in der
Regel **keine** Einmalgeräte (sie werden gereinigt bzw. gewartet).
**⚠ Entscheidung:** Welche Typen in Immendingen sind Einmalgeräte?

## 5. Sollbestand je Fahrzeug

Ort: Admin-Konsole → **Standorte**, unten der Bereich Sollbestand → **Sollbestand festlegen …**
(Recht: Standorte *Ändern*; nicht für Rollen mit Datenfilter).

- Fenster: Standort wählen, dann Regeln aus **Mindestanzahl** (1–99) und **Typ-Kästchen**.
  Mehrere angehakte Typen einer Regel sind **gleichwertig** („mindestens 4 Atemschutzmaske oder
  Funkgerät“ heißt: zusammen 4). Mehrere Regeln je Standort sind möglich (höchstens 20, je bis zu
  20 Typen). Das Speichern ersetzt alle Regeln des Standorts; leere Liste = keine Regel.
- Gezählt wird: **einsatzbereite**, nicht ausgesonderte Ausrüstung der Typen am Standort; bei einem
  Fahrzeug (Ebene 1) **samt Fächern**.
- **„außer Betrieb“** (Chip in der Liste; sonst „vollständig“) heißt: mindestens eine Regel des
  Standorts ist unterschritten („mindestens 3 Atemschutzmaske: 2 vorhanden, 1 fehlt“). Das ist nur
  eine Anzeige, es sperrt nichts.
- Bei jeder Buchung prüft der Server: Verringert sie den Bestand unter die Mindestanzahl, wird sie
  abgelehnt (Kasten mit **Tauschen …**). Beim **Tauschen** geht die alte Ausrüstung in die
  Sammelstelle (benutzt/defekt) und der Ersatz (einsatzbereit, aus Lager/Raum) an den bisherigen
  Standort; der Bestand bleibt dann erhalten. Wer das Recht **„Sollbestand ohne Ersatz
  unterschreiten“** (`stock.override`) hat, kann zusätzlich **Trotzdem buchen …** wählen; das
  erzeugt einen Hinweis „Sollbestand unterschritten“ im Protokoll. Ohne dieses Recht gibt es nur
  das Tauschen. Über Claude gibt es `stock.override` nie.
- **Offline** gebuchte Vorgänge werden nie abgelehnt (die Wirklichkeit gilt); eine Unterschreitung
  erscheint als Hinweis im Protokoll.
- Der CSV-Import warnt bei Unterschreitung, übernimmt aber trotzdem.
- **⚠ Entscheidung:** Welche Fahrzeuge/Fächer bekommen einen Sollbestand, mit welchen Typen und
  Zahlen? (Beispiel: HLF 20 – mindestens 4 Atemschutzgerät; mindestens 4 Atemschutzmaske.)

## 6. Rollen (Super-Admin)

Ort: `…/service/` → Stadt → Reiter **Rollen** → **Neue Rolle** (oder „Kopieren“). Der Editor hat
„1 · Ansichten und Bereiche“ (Kein Zugriff / Ansehen / Ändern), „2 · Aktionen“,
„3 · Einschränkungen“, „4 · Sieht nur …“. Die Änderung gilt sofort für alle Personen mit der Rolle
(beim nächsten Aufruf der App; die Offline-Kopie wird neu geladen). *Admin* ist fest und nicht
änderbar; *Benutzer* („Wie bisher“) bleibt, wie er ist.

Vorschlag (Anpassen nach Bedarf; Bezeichnungen wie im Editor):

**Gerätewart** (verwaltet die Ausrüstung, kein Benutzer-/Einstellungs-Admin)
- Bereiche *Ändern*: Ausrüstungsliste, Standorte (nötig für Sollbestand), Ausrüstungstypen,
  Termine, Protokoll und Hinweise, Wartung, Verleih und Rückgaben.
- Bereiche *Ansehen*: Dashboard, Suchen und Ausrüstung ansehen.
- Kein Zugriff: Benutzer, Einstellungen der Stadt.
- Aktionen: Umbuchen, Status setzen, Benutzt melden, Tauschen, Ausrüstung anlegen, Stammdaten
  bearbeiten, Code zuordnen, Sammelaktionen, CSV-Import, CSV-Export, Log-Mails empfangen;
  optional Aussondern und wieder aufnehmen sowie „Sollbestand ohne Ersatz unterschreiten“.
- Einschränkung: keine.
- Datenfilter: keiner (siehe unten).

**Geräteträger** (bucht am Handy)
- Bereiche *Ansehen*: Suchen und Ausrüstung ansehen, Termine. Sonst kein Zugriff (damit kein
  Konsolen-Menü).
- Aktionen: Umbuchen, Status setzen, Benutzt melden, Tauschen. Kein „Sollbestand ohne Ersatz
  unterschreiten“.
- Einschränkung: **„Bei Einmalgeräten nur „Benutzt melden““** (erst sinnvoll, wenn Typen als
  Einmalgerät markiert sind, siehe 4).
- Alternativ **Benutzer** unverändert verwenden (ohne Benutzt melden/Tauschen).

**Datenfilter „Sieht nur …“** lohnt sich, wenn jemand nur einen Teil sehen soll (z. B. eine
Atemschutz-Beauftragte nur die Atemschutz-Typen, oder ein Löschzug nur seine Fahrzeuge). Folgen:
Mit Filter gibt es keine Benutzerverwaltung, höchstens *Ansehen* bei Standorten/Typen, kein
CSV-Import und keine Seiten Wartung und Verleih. „Benutzt melden“ bucht auch mit Filter in die
Sammelstelle; die Rolle sieht die Ausrüstung danach nicht mehr. Für den Start
in Immendingen eher **ohne** Filter.

**Wer bekommt was?** Admin-Konsole → **Benutzer** → Stift → Rolle (oder „Rolle zuweisen …“ für
mehrere). Neue Einladungscodes tragen eine Rolle (Standard *Benutzer*; *Admin* gibt es für Codes
nicht). Schutzregel: Wer Rollen vergibt, braucht selbst alle Rechte der Rolle. Wechsel von oder zu
*Admin* meldet die Person auf allen Geräten ab.

Agenten ändern in Immendingen nichts; Rollen legen Sie dort selbst an.
**⚠ Entscheidung:** Genaue Rechte von *Gerätewart* und *Geräteträger*; wer ist Gerätewart, wer
Geräteträger; Filter ja/nein; wer darf Aussondern und „Sollbestand ohne Ersatz unterschreiten“.

## 7. Rückgabe ohne Anmeldung (Aushang)

Voraussetzung: Aufgabe **Rückgabe** freigeschaltet und einem Standort zugewiesen (3).
Ort: Admin-Konsole → **Verleih** → Abschnitt der Rückgabe (Überschrift = Anzeigename) →
**Aushang erzeugen …**.

- [ ] Aushang erzeugen: Der Link mit QR-Code erscheint **nur einmal** (der Server speichert nur
  einen Hash). **Aushang drucken** oder den Link kopieren, bevor die Ansicht geschlossen wird.
- [ ] Aushang am Rückgabe-Standort aufhängen (Gerätehaus, am Regal der Rückgabe). Wer verliehene
  Ausrüstung zurückbringt, scannt den QR-Code und gibt sie **ohne Anmeldung** zurück: Nummer
  (oder Scan-Code) eingeben/prüfen, Name, Zustand (in Ordnung / benutzt / defekt), optional
  Hinweis. Der Verlauf nennt „<Name> (ohne Anmeldung)“; keine Log-Mail (offen, T23).
- [ ] Danach bearbeiten Admins/Gerätewarte die Rückgaben im Verleih: **In Ordnung ins Lager …**
  bzw. **Benutzte und Defekte in die Sammelstelle …**.
- **Erneuern** („Aushang erneuern …“): wenn der Aushang in falsche Hände geraten ist, verschmutzt
  oder verloren ist. Der alte Aushang gilt danach nicht mehr, der neue Link erscheint nur einmal.
- **Ungültig machen** (widerrufen): wenn die Rückgabe an diesem Ort nicht mehr gewünscht ist.
- Missbrauch begrenzt: 20 Fehlversuche je Netz in 15 Minuten, dann gesperrt. Der Aushang verrät
  nur Nummer und Typ einer tatsächlich verliehenen Ausrüstung.
- **⚠ Entscheidung:** Wo hängt der Aushang? Wer erneuert ihn, und wie oft (z. B. jährlich)?

## 8. Mails

- Jede Person stellt ihre Benachrichtigung selbst ein (Profil bzw. Einstellungen,
  „Benachrichtigung „Log““). Nur Rollen mit der Aktion **Log-Mails empfangen** sehen sie
  (Admin immer; bei *Gerätewart* anhaken).
- **Alle Vorgänge** (Standard für alle, die vorher schon eingeschaltet hatten) oder
  **Nur ausgewählte** mit Themen, dann **Auswahl speichern**:
  Defekt gemeldet · Benutzt gemeldet · Rückgabe eingegangen · Ausrüstung verliehen ·
  Hinweise im Protokoll · Sonstige Umbuchungen und Status · Stammdaten, Wartungsdatum und Import.
- Es gibt keine Erinnerungs-Mails (T19) und keine Mail bei Rückgabe ohne Anmeldung (T23).
- **⚠ Entscheidung:** Wer soll welche Themen bekommen? Vorschlag: Gerätewart mindestens
  „Defekt gemeldet“, „Benutzt gemeldet“, „Hinweise im Protokoll“.

## 9. Erster Tag: Prüfliste

Mit zwei Konten (Admin/Gerätewart und Geräteträger am Handy). Dabei an echter Ausrüstung nur
vorsichtig buchen oder ein Testgerät verwenden.

- [ ] **Buchung**: Ausrüstung suchen, umbuchen, Bestätigung erscheint, Verlauf zeigt den Eintrag.
- [ ] **Benutzt melden**: Rückfrage nennt die Sammelstelle; danach Status „benutzt“ und Standort
  Sammelstelle. Geräteträger mit Einmalgerät: nur dieser Knopf.
- [ ] **Wartung**: Ausrüstung in der Sammelstelle → in die Werkstatt → zurück ins Lager
  (Status „einsatzbereit“).
- [ ] **Verleih**: „Verleihen …“ an einen Leihnehmer; Eintrag in der Liste mit „Rückgabe bis“.
- [ ] **Rückgabe**: über den Aushang (am besten am Handy) zurückgeben; in der Liste erscheint die
  Ausrüstung in der Rückgabe; ins Lager bearbeiten.
- [ ] **Sollbestand**: Bestand eines Fahrzeugs prüfen („vollständig“); eine Buchung, die ihn
  unterschreitet, ergibt den Kasten mit **Tauschen …**; Tausch ausführen.
- [ ] **Protokoll** ansehen: Hinweise offen? (z. B. „Sollbestand unterschritten“).

Bei Problemen:
- **Erst nachsehen**: Protokoll (Hinweise), Verlauf der Ausrüstung, Dashboard.
- **Zurückschalten** (in dieser Reihenfolge, jeweils einzeln möglich):
  1. Rolle zurück auf *Benutzer* (Benutzer → Rolle) bzw. Rolle im Editor anpassen.
  2. Sollbestand-Regeln leeren („Sollbestand festlegen …“, leere Liste).
  3. „Einmalgerät“ bei den Typen entfernen.
  4. Aushang **ungültig machen**.
  5. Aufgaben bei den Standorten auf „Keine Aufgabe“, danach im Reiter **Abläufe** abschalten
     (nur möglich, wenn kein Standort sie trägt und nichts verliehen ist).
  Bereits gebuchte Vorgänge bleiben im Verlauf; es wird nichts gelöscht.
- Datenbank zurückspielen nur im Notfall und nur von Hand (README „Sicherung zurückspielen“).
- Meldungen/Fehler an Claude (Agent) weitergeben mit Uhrzeit, Konto und Nummer der Ausrüstung.

## 10. Was es noch nicht gibt

(Details `docs/TODO-SPAETER.md`.)

- **T19** Erinnerungs-Mails (Termin fällig, Rückgabe überfällig) und tägliche Zusammenfassung:
  braucht einen Auslöser von außen (derzeit ausdrücklich „nein“). Fälliges zeigen Dashboard,
  Termine und Verleih.
- **T20** Wartung: Transportliste als CSV/zum Drucken (nur „Transportliste kopieren“);
  „Wartungsdatum setzen“ beim Zurückholen aus der Werkstatt, Wartungsintervall je Typ.
- **T21** Sollbestand: Kachel „Außer Betrieb“ im Dashboard, Anzeige am Fahrzeug in der
  Standortliste, Verlauf und Hinweis von „außer Betrieb“.
- **T22** Verleih: Rückgabe von Hand aus der Liste, Verleih verlängern, Kamera-Scanner im Fenster
  „Verleihen“ und auf der Rückgabeseite, Mail „Rückgabe eingegangen“, Mail „Rückgabe überfällig“
  (gibt es nicht).
- **T23** Log-Mail bzw. Hinweis bei Rückgabe ohne Anmeldung (nach ersten Rückmeldungen).
- **T24** Kamera-Scanner auf der öffentlichen Rückgabeseite.
- **T25** Mail-Auswahl „Nur Wichtiges“ und Filter nach Ausrüstungstypen.
- **T17 Datenschutz / Claude-Zugang:** Der Zugang für Claude (MCP) ist **nur in der Test-Stadt**
  freigegeben. Vor einer Freigabe in einer echten Stadt (auch Immendingen) muss der Datenschutz
  geklärt sein (Auftragsverarbeitung/Rechtsgrundlage, Hinweis auf der Seite „Claude verbinden“,
  Datenschutzerklärung, wer den Zugang je Stadt freigibt). Bis dahin ist in Immendingen „Claude-
  Zugang“ nicht verfügbar.

---

## Sammlung: Hier müssen Sie entscheiden

1. Welche Aufgaben für Immendingen freischalten, mit welchen Anzeigenamen (Abschnitt 2).
2. Welche Räume sind Lager (und Standard-Lager), Sammelstelle (mit „Nimmt an“), Werkstatt,
   Rückgabe; wer sind die Leihnehmer (3).
3. Welche Ausrüstungstypen sind Einmalgeräte (4).
4. Welche Fahrzeuge/Fächer bekommen einen Sollbestand, mit welchen Typen und Mindestanzahlen (5).
5. Rechte von *Gerätewart* und *Geräteträger*, Datenfilter ja/nein, wer Aussondern und
   „Sollbestand ohne Ersatz unterschreiten“ darf, wer welche Rolle bekommt (6).
6. Wo der Aushang hängt, wer ihn erneuert (7).
7. Wer welche Mail-Themen bekommt (8).
8. Zeitpunkt und Information (Phil) für den Start (1).
9. Datenschutz des Claude-Zugangs vor jeder Freigabe in einer echten Stadt (10, T17).
