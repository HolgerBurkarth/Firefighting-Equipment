# Änderungskonzept: Rollen, Abläufe und Bestände

Stand: 04.11.2026 · Holger Burkarth

> Abzug des Claude-Dokuments „Änderungskonzept: Rollen, Abläufe und Bestände“ (dort wird weiter gearbeitet; diese Datei wird bei Änderungen neu abgelegt). Die UI-Mockups dazu liegen im Claude-Projekt (Design „Mockups Änderungskonzept“). **Für alles, was Nutzer sehen, gelten die Begriffe der Tabelle in Abschnitt 9**, nicht die Arbeitsbegriffe im übrigen Text.

## Kurzfassung

Empfehlung: Zwei Grundlagen zuerst bauen, dann die Abläufe darauf setzen.

- **Rechte statt fester Rollen:** Der Server prüft künftig einzelne Rechte aus einem festen Katalog. Eine Rolle ist nur ein benanntes Bündel dieser Rechte, gespeichert je Stadt.
- **Standort-Rollen:** Bestimmte Standorte bekommen eine feste Aufgabe: Lager, Sammelstelle, Werkstatt, Annahme, Verleih. Der Name bleibt frei wählbar. Wartungsablauf, Verleih, Rückgabe und Übersichten hängen dann an der Aufgabe, nicht am Namen.

Darauf bauen auf: mehrstufige Wartung, Wartungsintervall je Gerätetyp, Verleih mit Rückgabe ohne Anmeldung, Mindestbestand mit Tausch und konfigurierbare Mails. Das Schlauchlager (nur Anzahl, kein Code) ist ein eigenes Datenmodell und kommt zuletzt, wenn der Ablauf für Schläuche beschrieben ist.

Andere Städte behalten ihr heutiges Verhalten: Neue Abläufe greifen nur, wenn eine Stadt sie einrichtet, die heutigen Rollen werden zu Standard-Rollen. Die Umsetzung läuft in neun Phasen, jede für sich nutzbar (Abschnitt 11).

## 1. Rollen und Rechte

Vorschlag: Der Server prüft künftig Rechte, nicht Rollen. Heute hat jede Route eine feste Rollenprüfung (Benutzer, Admin, Super-Admin); das wird durch eine Prüfung des passenden Rechts ersetzt.

**Rechte-Katalog (fest im Code, fein unterteilt):** Der Katalog ist fest, weil jedes Recht im Code geprüft und getestet werden muss. Er ist so fein, dass sich Rollen frei zusammenstellen lassen, z. B. mehrere Arten von Geräteträgern. Zwei Arten von Rechten:

- **Bereiche** mit den Stufen *kein Zugriff*, *Lesen* oder *Schreiben*: Geräte, Standorte, Gerätetypen, Prüffristen, Protokoll und Hinweise, Verleih, Wartung, Benutzer, Einstellungen der Stadt.
- **Einzelne Aktionen** als Schalter: Gerät anlegen, bearbeiten, aussondern, Code zuordnen, umbuchen, Status setzen, benutzt melden, tauschen, verleihen, Annahme bearbeiten, Sammelaktionen, CSV-Import, CSV-Export, Mails empfangen.
- **Einschränkungen** je Rolle, z. B. „bei Einmalgeräten nur benutzt melden“. Dazu kommen die Datenfilter (unten).

**Sichtbarkeit**, zwei Arten:

- **Ansichten:** ergeben sich aus den Rechten. Ohne Leserecht fehlt die Seite im Menü, und ein direkter Aufruf wird abgelehnt.
- **Datenfilter:** Eine Rolle sieht nur bestimmte Standorte (samt Fächern) oder Gerätetypen. Das muss der Server in jeder Abfrage durchsetzen: Liste, Suche, Zähler, Verlauf, Hinweise, CSV, Mails und Offline-Kopie. Das ist aufwendig und fehleranfällig; es kommt trotzdem gleich in Phase 1, damit das Rechtemodell von Anfang an vollständig ist (Entscheidung E4).

**Regeln für Datenfilter** (Vorschlag):

- Ein Filter ist eine Positivliste aus Standorten (jeweils mit Fächern) und Gerätetypen. Leer heißt: alles sichtbar.
- Ein Gerät ist sichtbar, wenn sein Typ und sein aktueller Standort im Filter liegen.
- Als Ziel einer Buchung sind nur Standorte im Filter wählbar. Ausnahme: Ziele mit fester Aufgabe wie Sammelstelle oder Annahme, die der Ablauf vorgibt.
- Im Verlauf erscheint ein Standort außerhalb des Filters nur als „anderer Standort“.
- Zähler, Dashboard, Prüffristen, CSV, Mails und Offline-Kopie rechnen nur mit sichtbaren Geräten. Ein Test prüft jede Abfrage mit und ohne Filter.

**Rollen je Stadt:** Eine Rolle ist ein Datensatz mit Name und Rechten (später mit Filtern). Jede Stadt bekommt beim Anlegen die Standard-Rollen *Admin* und *Benutzer*, die dem heutigen Verhalten entsprechen. Schutzregeln:

- Die Rolle *Admin* hat immer alle Rechte und ist nicht änderbar.
- Jede Stadt behält mindestens einen aktiven Admin.
- Niemand kann Rechte vergeben, die er selbst nicht hat.
- Die eigene Rolle ist nicht änderbar.

**Einladungscodes mit Rolle** (entschieden 02.11.): Jeder Einladungscode bzw. Aushang bringt eine Rolle mit, z. B. „Geräteträger“. Beim Freischalten ist sie vorausgewählt, der Admin kann sie ändern. Die Rolle *Admin* ist für Codes nicht wählbar: Wer einen Aushang fotografiert, soll nie Admin werden können.

**Rollenverwaltung:** Ich empfehle, dass der Super-Admin die Rollen einer Stadt anlegt und ändert; der Admin der Stadt weist sie nur Personen zu. Falsch gesetzte Rechte sind ein Sicherheitsrisiko, es gibt wenige Städte, und die Rechte-Tabelle braucht Erklärung. Die Alternativen stehen unter „Offene Entscheidungen“.

**Rollen für Immendingen** als Startbelegung. Jede Zelle ist je Rolle einstellbar; der Geräteträger ist nur ein Beispiel (Entscheidung E2).

| Bereich | Admin | Gerätewart | Geräteträger |
| --- | --- | --- | --- |
| Geräte suchen, ansehen, Verlauf | Schreiben | Schreiben | Lesen |
| Umbuchen, Status setzen, Tausch | Schreiben | Schreiben | Schreiben (bei Einmalgeräten nur „benutzt“) |
| Geräte anlegen, bearbeiten, aussondern, Code zuordnen | Schreiben | Schreiben | kein Zugriff |
| Standorte und Gerätetypen verwalten | Schreiben | Schreiben | kein Zugriff |
| Prüffristen (Prüfliste) | Schreiben | Schreiben | kein Zugriff |
| Sammelaktionen, CSV-Import und -Export | Schreiben | Schreiben | kein Zugriff |
| Protokoll und Hinweise | Schreiben | Schreiben | kein Zugriff |
| Verleih und Annahme bearbeiten | Schreiben | Schreiben | kein Zugriff |
| Einstellungen der Stadt (Farbe, Logo) | Schreiben | kein Zugriff | kein Zugriff |
| Benutzer, Einladungscodes, Aushang | Schreiben | kein Zugriff | kein Zugriff |

*Extern* ist keine Rolle mit Konto, sondern ein öffentlicher Zugang nur für die Rückgabe (Abschnitt 5).

## 2. Standort-Rollen

Vorschlag: Standorte bekommen zusätzlich zur Art (Fahrzeug, Raum/Lager, Extern) eine **Aufgabe**. Abläufe, Buttons und Übersichten hängen an der Aufgabe, der Name bleibt frei. Heute unterscheidet die App nur die Art.

| Aufgabe | Wozu | Beispiel Immendingen | Anzahl je Stadt |
| --- | --- | --- | --- |
| ohne | normaler Standort | HLF 20, Fach G1, Kleiderkammer | beliebig |
| Lager | Ziel nach Wartung und Rückgabe | Lager Gerätehaus | beliebig, einer ist Standard |
| Sammelstelle | benutzte Geräte warten auf den Transport zur Werkstatt | Kiste „Benutzt“ | 1 oder mehr |
| Werkstatt | Geräte in Prüfung oder Reparatur | Atemschutzwerkstatt Tuttlingen | 1 oder mehr |
| Annahme | Rückgabe von Leihgeräten ohne Anmeldung | Annahme Gerätehaus | meist 1 |
| Leihnehmer | ausgeliehene Geräte bei Externen | Nachbarwehr, Schule, Verein | beliebig |

Dazu kommt je Standort die Einstellung **„darf nie leer sein“** bzw. ein Mindestbestand (Abschnitt 6).

**Aufgaben-Katalog** (entschieden 02.11.): Die Aufgaben stehen fest im Code, weil jede eingebautes Verhalten hat. Der Super-Admin schaltet je Stadt frei, welche Aufgaben es gibt, und darf ihre Anzeigenamen ändern (z. B. „Sammelstelle“ → „Benutzt-Kiste“).

**Zustand und Ort bleiben getrennt.** Ein Gerät hat weiter einen Status und einen Standort. Der Status bekommt den neuen Wert *benutzt*: einsatzbereit, benutzt, in Wartung, defekt, ausgesondert. Ein Gerät in der Sammelstelle ist also „benutzt“, in der Werkstatt „in Wartung“ oder „defekt“. So bleiben Filter, Dashboard und Prüffristen verständlich, auch für Städte ohne diese Abläufe.

## 3. Mehrstufige Wartung und Einmalgeräte

Vorschlag: „Wartung“ wird ein Ablauf über die Standort-Rollen, mit eigener Seite und Sammelaktionen je Stufe. Heute ist „in Wartung“ nur ein Status.

1. **Benutzt melden:** Wer das Gerät benutzt hat, setzt „benutzt“. Das Gerät geht automatisch in die Sammelstelle (Status *benutzt*). Ist der bisherige Standort „nie leer“, verlangt die App einen Tausch (Abschnitt 6).
2. **Zur Werkstatt:** Der Gerätewart bucht alle oder ausgewählte Geräte der Sammelstelle in einem Schritt in die Werkstatt (Status *in Wartung*). Eine Liste für den Transport lässt sich drucken oder exportieren.
3. **Zurück aus der Werkstatt:** Der Gerätewart hakt die zurückgekommenen Geräte an (Scannen hakt an, „Alle auswählen“ geht auch) und bucht sie gesammelt ein. Sie gehen ins Standard-Lager (Status *einsatzbereit*), und als Datum der letzten Wartung gilt heute (Abschnitt 4).
4. **Defekt:** Ein defektes Gerät nimmt denselben Weg, mit Status *defekt* und Pflichtkommentar wie heute.

Die neue Seite „Wartung“ zeigt die Geräte je Stufe mit Anzahl und seit wann sie dort liegen. Für Städte ohne Sammelstelle und Werkstatt bleibt alles wie heute.

Je Sammelstelle ist einstellbar, ob sie benutzte, defekte oder beide Arten von Geräten annimmt (entschieden 02.11.). Für getrennte Wege legt man zwei Sammelstellen an.

**Einmalgeräte:** Ein Gerätetyp bekommt die Einstellung „nach jeder Benutzung warten“. Bei solchen Geräten sieht ein Geräteträger statt „Umbuchen“ nur den Knopf „Benutzt melden“ mit optionalem Kommentar. Der Server lehnt andere Buchungen dieses Kontos für solche Geräte ab. Gerätewart und Admin buchen sie normal.

## 4. Wartungsintervall je Gerätetyp

Vorschlag: Der Gerätetyp bestimmt das Intervall, die App rechnet das Fälligkeitsdatum selbst aus. Heute trägt man beim Gerät das Datum der nächsten Prüfung von Hand ein.

- **Am Gerätetyp:** Intervall mit Einheit (Tage, Monate oder Jahre) und Berechnungsverfahren. Monate und Jahre sind nötig, weil Fristen meist so angegeben werden; „365 Tage“ verschiebt sich sonst in Schaltjahren.
- **Am Gerät:** *Neudatum* (Inbetriebnahme) und *letzte Wartung*. Die letzte Wartung setzt die App bei der Rückkehr aus der Werkstatt (Abschnitt 3) oder man trägt sie von Hand ein. Ein abweichendes Datum für ein einzelnes Gerät bleibt möglich.

**Berechnungsverfahren**, einstellbar je Gerätetyp:

| Verfahren | Nächste Fälligkeit | Passt für |
| --- | --- | --- |
| Gleitend (Vorschlag als Standard) | letzte Wartung + Intervall; ohne Wartung Neudatum + Intervall | die meisten Prüfungen |
| Festes Raster | Neudatum + ein Vielfaches des Intervalls; eine frühe Wartung verschiebt nichts | feste Zyklen ab Herstellung |
| Zum Monatsende | wie oben, aufgerundet auf das Monatsende | Prüfplaketten mit Monat und Jahr |

**Mehrere Fristen je Gerät:** In der Atemschutzpraxis hat ein Gerät oft mehrere Fristen, etwa nach Gebrauch, regelmäßig und eine Grundüberholung. Entschieden (03.11.): Ein Gerätetyp kann mehrere Wartungen haben, jede mit eigenem Namen, Intervall und Verfahren, z. B. „kleine Wartung“ alle 6 Monate und „große Wartung“ alle 6 Jahre. Jede Wartung hat am Gerät ihre eigene letzte und nächste Fälligkeit; Prüffristen und Erinnerungen nennen die Wartung beim Namen.

**Umstellung:** Das heutige Datum „Nächste Prüfung“ wird zum abweichenden Datum des Geräts, damit nichts verloren geht. Das Beschaffungsdatum kann als Neudatum dienen. Diese Migration verändert bestehende Daten und braucht deine Freigabe.

**Intervall ändern** (entschieden 02.11.): Die App rechnet die nächste Prüfung aller Geräte des Typs neu, außer bei abweichend festgelegtem Datum. Vorher kommt eine Rückfrage mit der Anzahl betroffener Geräte. Beim Anlegen eines Geräts gibt man Neudatum und optional die letzte Wartung ein; die nächste Prüfung wird angezeigt, nicht eingegeben (außer bei Typen ohne Intervall).

## 5. Verleih, Rückgabe ohne Anmeldung, Übersicht

Vorschlag: Verleihen ist eine eigene Buchung auf einen Leihnehmer. Die Rückgabe läuft über einen öffentlichen Link an der Annahme, der nur genau das erlaubt.

**Verleihen** (Gerätewart, Admin): Geräte scannen, Leihnehmer wählen, dazu optional Ansprechpartner, „Rückgabe bis“ und Kommentar. Mehrere Geräte auf einmal sind möglich. Der Status bleibt, der Ort wird der Leihnehmer.

**Rückgabe ohne Anmeldung** (Zugang *Extern*):

- An der Annahme hängt ein Aushang mit QR-Code, wie heute bei der Registrierung. Der Link enthält einen langen geheimen Schlüssel, den der Admin jederzeit erneuern kann.
- Die Seite „Rückgabe“ fragt: Gerät scannen oder Kennung eingeben, Name des Überbringers, Zustand (in Ordnung, benutzt, defekt) und Kommentar.
- Der Server nimmt nur Geräte an, die gerade bei einem Leihnehmer sind, und bucht sie in die Annahme. Der Verlauf zeigt „Rückgabe durch \<Name>, ohne Anmeldung“.
- Die Seite zeigt nichts außer Kennung und Gerätetyp des gescannten Geräts; andere Buchungen sind über diesen Zugang nicht möglich. Anfragen werden begrenzt wie bei der Anmeldung.
- Restrisiko: Wer den Link hat, kann ein verliehenes Gerät fälschlich als zurückgegeben melden. Der Schaden ist klein, weil der Gerätewart jede Rückgabe in der Annahme sieht und weiterbucht.

**Annahme bearbeiten** (Gerätewart): Liste „In der Annahme“. Was als defekt oder benutzt zurückkommt, bucht die Annahme direkt in die passende Kiste (Sammelstelle für defekte bzw. benutzte Geräte), was in Ordnung ist, ins Lager. Die Knöpfe heißen nach dem Zustand, z. B. „Defekte in die Kiste“ (geändert 03.11.).

**Übersicht „Verliehen“:** je Leihnehmer die Geräte mit „verliehen seit“, „Rückgabe bis“ (überfällig in Rot) und Ansprechpartner. Dazu eine Kachel im Dashboard, z. B. „Verliehen: 7, davon 2 überfällig“. Entschieden 02.11.: Erinnerungs-Mail bei überfälliger Rückgabe über den externen Zeitgeber (E9, geändert 03.11.); zusätzlich zeigen die Seite „Verleih“ und ein roter Zähler im Menü überfällige Geräte. Die Annahme schlägt je Gerät ein Ziel vor (in Ordnung → Lager, benutzt oder defekt → Sammelstelle).

Der abweichende Ablauf für Schläuche wird ergänzt, sobald er beschrieben ist.

## 6. Standorte, die nie leer sein dürfen, und Tausch

Vorschlag: Je Standort und Gerätetyp lässt sich ein Mindestbestand festlegen; „nie leer“ ist der Mindestbestand 1. Wer ein Gerät herausbucht und damit den Mindestbestand unterschreitet, muss im selben Schritt ein Ersatzgerät desselben Typs einbuchen.

**Fächer mit mehreren Gerätetypen** (Vorgabe 02.11.): Ein Fach kann verschiedene Gerätetypen aufnehmen, jeden mit eigener Soll-Anzahl (z. B. 2 Atemschutzgeräte, 2 Masken, 1 Funkgerät). Gleichwertige Typen stehen in einer Zeile mit „oder“ und zählen zusammen. Optional „nur diese Gerätetypen dürfen hinein“. Weniger als Soll in einer Zeile: das Fach ist außer Betrieb. Mehr als Soll: nur Hinweis „zu viel“, keine Sperre (entschieden 02.11.). Beim Tausch muss der Ersatz zur selben Zeile passen.

**Tausch in der App:**

- Zwei Felder, jedes mit Scan-Knopf: „Heraus“ (vorbelegt, wenn man vom Gerät kommt) und „Hinein“.
- Für „Hinein“ schlägt die App einsatzbereite Geräte desselben Typs aus dem Lager vor.
- Das herausgenommene Gerät geht standardmäßig in die Sammelstelle (Status *benutzt*); ein anderes Ziel ist wählbar.
- Beide Buchungen gehen als eine Anfrage an den Server und gelingen nur zusammen.

Dieselbe Regel gilt in allen Buchungswegen: Einzelbuchung, Sammelaktion, Verleih und CSV-Import. Beim Import warnt die Vorschau.

**Ausnahme mit Begründung:** Muss ein Gerät ohne Ersatz heraus (z. B. kein Ersatz da), dürfen Admin und Gerätewart nach Rückfrage trotzdem buchen. Der Standort gilt dann als „außer Betrieb“, solange er unter dem Mindestbestand liegt. Die App zeigt das am Fach, am Fahrzeug („1 Fach außer Betrieb“) und im Dashboard und legt einen Hinweis im Protokoll an. Der Zustand wird nicht von Hand gesetzt, sondern aus dem Bestand berechnet: Sobald wieder genug Geräte eingebucht sind, endet er von selbst, und der Verlauf hält Beginn und Ende fest. Geräteträger dürfen das nicht (Entscheidung E7).

**Offline:** Ein Tausch wird als eine Einheit in die Warteschlange gelegt. Kommt er später beim Server an und ist der Ersatz inzwischen woanders, gilt: **Die Wirklichkeit gewinnt.** Der Tausch hat im Gerätehaus schon stattgefunden. Der Server bucht ihn deshalb trotzdem und legt einen Hinweis für den Gerätewart an, statt ihn abzulehnen.

## 7. Konfigurierbare Mail-Benachrichtigungen

Vorschlag: Jede Person mit dem Recht dazu wählt in ihrem Profil, bei welchen Ereignissen sie eine Mail bekommt. Heute gibt es für Admins nur „alle Änderungen“ an oder aus.

Auswahl per Häkchen:

- defekt gemeldet
- benutzt gemeldet (Einmalgeräte)
- Rückgabe in der Annahme eingegangen
- verliehen
- Mindestbestand unterschritten oder neuer Hinweis
- sonstige Umbuchungen und Statusänderungen
- Stammdaten, Prüfdatum, CSV-Import

Die heutige Einstellung „Log-Mails an“ wird zu „alles angehakt“. Sammelaktionen und Import bleiben je eine Sammelmail. Ein Filter nach Gerätetyp oder Standort ist später möglich. Mit dem externen Zeitgeber (E9) kommen dazu: Erinnerung bei fälliger Wartung, bei überfälliger Rückgabe und optional eine tägliche Zusammenfassung.

## 8. Schlauchlager: Geräte ohne Code

Vorschlag: Ein Gerätetyp wird entweder *einzeln* (wie heute, jedes Gerät mit Kennung) oder *nach Menge* geführt. Bei „nach Menge“ gibt es keine einzelnen Geräte, sondern nur Anzahlen je Standort und Zustand. Schläuche mit Code bleiben normale Einzelgeräte.

Beispiel für den Typ „B-Schlauch 20 m“:

| Standort | einsatzbereit | benutzt |
| --- | --- | --- |
| Lager Gerätehaus | 40 | – |
| HLF 20 | 6 | – |
| Sammelstelle | – | 4 |

**Buchen** heißt dann „6 Stück von A nach B“, mit Plus-/Minus-Zähler statt Scan. Jede Buchung steht im Verlauf des Typs. Größen und Längen (B, C, D; 15 m, 20 m) werden eigene Gerätetypen.

Das passt zu den anderen Themen:

- **Mindestbestand** ist bei Mengen besonders natürlich, z. B. „HLF 20: mindestens 6 B-Schläuche“.
- **Wartung** läuft als Anzahl: benutzt in die Sammelstelle, zur Schlauchwäsche oder Prüfung, zurück ins Lager.
- **Verleih** wird „10 B-Schläuche an Verein X“; die Rückgabe ohne Anmeldung fragt die Anzahl statt eines Scans.
- **Offline:** Mengenbuchungen sind Zu- und Abgänge und lassen sich gut nachträglich verrechnen. Würde ein Bestand negativ, gewinnt die Wirklichkeit, und es entsteht ein Hinweis.

Was nicht geht: Prüffristen je Schlauch, weil ein Schlauch ohne Code nicht unterscheidbar ist. Fristen gibt es dann nur je Typ als Erinnerung, z. B. „alle Schläuche jährlich prüfen“.

Aufwand: Das ist ein zweites Datenmodell neben den Einzelgeräten, mit eigenen Seiten für Bestand, Buchung, Verlauf, Import und Export. Ich empfehle, es erst zu bauen, wenn der Ablauf für Schläuche beschrieben ist.

## 9. Querschnitt

Diese Punkte gelten für alle Themen und müssen in jeder Phase mitgedacht werden.

- **Andere Städte:** Neue Abläufe schalten sich nur ein, wenn eine Stadt sie einrichtet: Wartungsablauf mit einer Sammelstelle, Verleih mit einem Leihnehmer, Mindestbestand mit einem gesetzten Wert, Mengen mit einem Mengentyp. Ohne Einrichtung bleibt alles wie heute; eigene Schalter sind nicht nötig.
- **Alte App-Versionen:** Rechte und neue Status ändern die Bedeutung der API. Vorher sollte die geplante Sperre für veraltete Clients kommen (Antwort 409 `client_outdated`), damit kein Handy mit alter App falsch bucht.
- **Offline:** Die Offline-Kopie muss die Rechte der Sitzung, neue Status, Tausch als Einheit und die Einschränkung bei Einmalgeräten kennen. Datenfilter müssen auch die Kopie filtern.
- **CSV-Import und -Export:** neue Spalten für Neudatum, letzte Wartung und Leihnehmer; „Gültige Werte“ listet die neuen Status und Standort-Aufgaben.
- **Migrationen:** Vieles ist rein ergänzend. Drei Schritte verändern bestehende Daten und brauchen deine Freigabe: Rollen der Konten auf die neuen Rollen umstellen, „Nächste Prüfung“ in ein abweichendes Datum überführen, Beschaffungsdatum als Neudatum übernehmen.
- **Tests:** Ein Test prüft jede Route gegen jedes Recht, wie heute schon für die Routen des Super-Admins.
- **Backend:** Fast jede Phase ändert die REST-API. Jede Änderung kommt wie bisher nach `UEBERGABE-BACKEND.md`.

**Filter in allen Listen** (entschieden 02.11.): Jede Liste, die viele Einträge haben kann, bekommt eine Suche und passende Filter, wie heute schon die Geräteliste und die Standorte.

**Seiten anpassen durch den Admin** (Vorgabe 03.11.): Auf anpassbaren Seiten sieht der Admin einen Knopf „Seite anpassen“. Er öffnet die Einstellungen dieser Seite für die ganze Stadt: Spalten ein- und ausblenden, Eingabefelder ein- und ausblenden, Vorgabewerte. Beispiel Immendingen: verleiht ohne Rückgabefrist, also ohne Spalte „Rückgabe bis“ und ohne das Feld beim Verleihen. Ausgeblendete Felder sind auch für den Server leer bzw. optional; Pflichtfelder lassen sich nicht ausblenden.

**Begriffe in Alltagssprache** (Hinweis von Phil, 03.11.): Entschieden (03.11., E11): Phil prüft die Liste und korrigiert sie. Danach gelten die Wörter fest für alle Städte, in Oberfläche, Anleitung und Mails gleich. Umbenennen je Stadt kommt erst, wenn eine Stadt es wirklich braucht. Phil hat am 04.11. eine Liste geschickt; Holger hat sie in den Mockups geprüft und festgelegt. Im Konzepttext stehen weiter die bisherigen Arbeitsbegriffe; die Tabelle gilt für alles, was Nutzer sehen:

| Bisher | Festgelegt (04.11.) | Wo |
| --- | --- | --- |
| Prüffristen | Termine | Menü, Dashboard, Rechte |
| Annahme | Rückgabe | Standort-Aufgabe, Aushang, Rechte („Rückgaben bearbeiten“) |
| Leihnehmer | Ausgeliehen an | Standort-Aufgabe, Verleih |
| Neudatum | in Betrieb seit | Gerät |
| Kennung | Nummer | Suche, Scan-Felder, Handy |
| Datenfilter | Sieht nur … | Rollen (Super-Admin) |
| Lesen / Schreiben | Ansehen / Ändern | Rollen (Super-Admin) |
| Gerät (Oberbegriff) | Ausrüstung (Mehrzahl „Ausrüstungen“, Typ „Ausrüstungstyp“) | überall: Menü, Listen, Knöpfe, Mails. „Gerätewart“, „Gerätehaus“ und die Typnamen (Atemschutzgerät, Maske …) bleiben. |
| Wartung / Prüfung | nur „Wartung“ als Oberbegriff; die einzelnen Wartungen benennt jede Stadt selbst (z. B. „Jahresprüfung“) | Gerätetyp, Termine, Mails |
| Wartungsintervall | Wartung alle … | Gerätetyp |
| gleitend / festes Raster / zum Monatsende | ab letzter Wartung / ab „in Betrieb seit“ / bis Monatsende | Gerätetyp |
| Sammelstelle, Einmalgerät, Sollbestand, außer Betrieb, Status, umbuchen, einsatzbereit | bleiben unverändert | überall |

## 10. Lebenslauf eines Geräts

*(Grafik „Lebenslauf eines Geräts · 6 Standort-Rollen“ nur im Claude-Dokument: Lager → Fahrzeug → Sammelstelle → Werkstatt → Lager; Lager → Ausgeliehen an → Rückgabe → Lager bzw. Sammelstelle.)*

Das Lager ist Start und Ziel. Benutzte Geräte kommen über Sammelstelle und Werkstatt zurück, verliehene über die Annahme. Der Status steht unter dem Namen jeder Stufe.

**In der App als Grafik** (Vorschlag aus den Mockups, in Prüfung): Eine Seite „Wege der Geräte“ zeigt Standorte mit Aufgabe als Kästen und erlaubte Buchungen als Pfeile, filterbar nach Rolle und Gerätetyp. Am PC lassen sich Wege dort anklicken und bearbeiten (wer darf so buchen, Status beim Ankommen). Im Gerätedetail zeigt „Weg als Grafik“ die Aufenthalte eines Geräts nacheinander, mit Zeitraum-Filter.

**Wege steuern Buchungen** (entschieden 02.11.): Ein Pfeil ist eine erlaubte Buchung, je Weg mit „Wer darf so buchen“, „Gilt für Mehrweg- und/oder Einmalgeräte“ und „Status beim Ankommen“. Ein fehlender Pfeil sperrt die Buchung; der Server prüft das bei jeder Buchung. Ohne eingerichtete Wege bleibt alles frei wie heute. Vorschlag zur Umsetzung: erst nur ansehen mit automatischer Anordnung, danach Bearbeiten am PC („+ Standort“, „+ Weg“, Pfeil anklicken).

## 11. Reihenfolge der Umsetzung

Neun Phasen, jede für sich nutzbar und einzeln live prüfbar. Größe: *klein* = ein Schritt des Agenten, *mittel* = 2–4 Schritte, *groß* = 5 und mehr.

0. **Sperre für veraltete App-Versionen** (klein). Voraussetzung für alles Weitere.
1. **Rechte und Rollen** (groß): Katalog, Rollen je Stadt, Prüfung in jeder Route, Datenfilter je Rolle, Zuweisung durch den Admin, Rollen für Immendingen, Rollen-Seite beim Super-Admin.
2. **Standort-Aufgaben, Status „benutzt“, Seite „Wartung“, Einmalgeräte** (mittel).
3. **Wartungsintervall je Gerätetyp** mit Neudatum, Berechnungsverfahren und Umstellung (mittel).
4. **Verleih, Rückgabe ohne Anmeldung, Übersicht „Verliehen“** (mittel).
5. **Mindestbestand und Tausch**, online und offline (mittel bis groß).
6. **Mail-Auswahl je Person** (klein). Unabhängig, kann auch früher kommen.
7. **Datenfilter je Rolle**: entfällt als eigene Phase, ist Teil von Phase 1.
8. **Schlauchlager nach Menge** (groß). Erst nach der Beschreibung des Ablaufs.

Phase 1 ist die Grundlage für alles, was „nur Gerätewart“ oder „nur benutzt melden“ sagt. Phase 2 ist die Grundlage für 3 bis 5 und 8.

## 12. Offene Entscheidungen

Alle zwölf Fragen sind entschieden (E1–E10 am 02.11.2026, E11 und E12 am 03.11.2026; die Begriffe zu E11 am 04.11. festgelegt).

| Nr. | Frage | Empfehlung |
| --- | --- | --- |
| E1 | Wer verwaltet Rollen: Super-Admin, Admin der Stadt oder niemand (feste Rollen im Code)? | Entschieden (02.11.): Super-Admin legt Rollen je Stadt an, der Admin weist sie zu |
| E2 | Darf der Geräteträger wirklich alles außer Benutzer und Prüffristen, also auch Geräte anlegen und Standorte ändern? | Entschieden (02.11.): Geräteträger = heutiger Benutzer ohne Prüffristen, plus „benutzt melden“ und Tausch; alle Rechte je Rolle einstellbar, auch für weitere Träger-Rollen |
| E3 | Darf der Gerätewart die Einstellungen der Stadt (Farbe, Logo) ändern? | Entschieden (02.11.): nur der Admin |
| E4 | Datenfilter je Rolle (nur bestimmte Standorte oder Typen sichtbar) jetzt oder später? | Entschieden (02.11.): gleich in Phase 1 |
| E5 | Eine oder mehrere Fristen je Gerätetyp? | Entschieden (03.11., geändert): mehrere Wartungen je Gerätetyp von Anfang an |
| E6 | Rückgabe ohne Anmeldung: Aushang mit geheimem Link oder ein fest eingerichtetes Tablet an der Annahme? | Entschieden (02.11.): Aushang mit Link, Tablet später möglich |
| E7 | Darf ein Admin oder Gerätewart den Mindestbestand ohne Ersatz unterschreiten? | Entschieden (02.11.): ja, mit Rückfrage und Hinweis; der Standort ist „außer Betrieb“, solange er unter dem Mindestbestand liegt |
| E8 | Geht ein benutztes Gerät automatisch in die Sammelstelle? | Entschieden (02.11.): ja, wenn die Stadt eine Sammelstelle hat; sonst bleibt das Gerät mit Status „benutzt“ am Standort |
| E9 | Werden Cronjobs nötig (tägliche Zusammenfassung, Mail bei überfälliger Rückgabe)? | Entschieden (03.11., geändert): Ein externer Zeitgeber ruft die App regelmäßig auf. Damit gibt es Mails für fällige Termine (Wartung fällig, Rückgabe überfällig) und optional eine tägliche Zusammenfassung |
| E10 | Schlauchlager: Mengentyp neben Einzelgeräten wie vorgeschlagen? | Entschieden (02.11.): Mengentyp neben Einzelgeräten; Bau erst nach Beschreibung des Ablaufs |
| E11 | Begriffe in Alltagssprache: wer legt sie fest? | Entschieden (03.11.): fest für alle Städte. Am 04.11. nach Phils Liste in den Mockups geprüft und festgelegt (Tabelle in Abschnitt 9) |
| E12 | Zählt eine große Wartung auch als kleine? | Entschieden (03.11.): je Wartung im Gerätetyp einstellbar („enthält auch …“); beim Eintragen bekommen die enthaltenen Wartungen dasselbe Datum. Bei der Rückkehr aus der Werkstatt kreuzt der Gerätewart an, welche Wartung gemacht wurde. |
