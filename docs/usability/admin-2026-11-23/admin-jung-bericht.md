# Benutzertest Admin – jung (Sarah, 24, Schriftführerin)

## 1. Kurzprofil

Sarah, 24, Schriftführerin der Feuerwehr Musterstadt, nutzt täglich Handy-Apps und Discord, aber keine Büro-Software. Gerät: PC 1920 × 1080, für Aufgabe 1 ein Android-Handy (Pixel). Hat die Admin-Anleitung (Seiten 1–8) einmal überflogen, vor allem die Bilder zu Benutzern und Einladungscodes. Vorgehen: Menü links bzw. ☰ anschauen, das Naheliegende anklicken, Texte nur überfliegen.

## 2. Aufgaben

| Aufgabe | Ergebnis | Aktionen (gebraucht / kürzester Weg) | Einfachheit | Zitat |
|---|---|---|---|---|
| 1 Erika Probe freischalten, Rolle Geräteträger (Handy) | ja | 5 / 4 | 7 | „Easy, wie bei Discord Rollen vergeben.“ |
| 2 Einladungscode anlegen, Aushang zum Drucken | ja | 7 / 5 | 6 | „Code, QR, Drucken – passt. Nur sehen, wie der Zettel aussieht, will ich vorher.“ |
| 3 Karl Alt wird Gerätewart | umweg | 5 / 3 | 6 | „Warum geht der Name nicht auf? Ach, der kleine Stift.“ |
| 4 Otto Austritt entfernen | ja | 3 / 3 | 7 | „Stift, löschen, fertig. Und es steht sogar (ausgetreten) dran.“ |
| 5 Logo (nur Weg) und Farbe #1a3a6b | umweg | 5 / 4 | 6 | „Hex reinpasten, sieht man sofort – nice.“ |
| 6 Rückgabe-Aushang erneuern | ja | 5 / 4 | 6 | „Erneuern, bestätigen, drucken – easy. Der Text drüber ist mir zu lang.“ |

Ablauf kurz:
- **Aufgabe 1:** ☰ → „Benutzer“ → Karte „1 neue Anmeldung wartet auf Freischaltung“ → „Freischalten …“ → „Geräteträger“ angetippt → „Freischalten“. Danach kam die grüne Meldung „Erika Probe ist freigeschaltet (Geräteträger) und erhält eine E-Mail.“
- **Aufgabe 2:** „Benutzer“ → Reiter „Einladungscodes“ → „Neuer Einladungscode“. Bezeichnung „Dienstabend Dezember“, 14 Tage, Rolle „Benutzer“ gelassen → „Code erzeugen“. Danach erschienen Code, QR-Code, Link und „Aushang drucken“. Ein Klick auf „Aushang drucken“ zeigte im Test nichts Sichtbares (Druckdialog im Test nicht bedienbar). Eine Vorschau des Aushangs auf dem Bildschirm habe ich nicht gefunden.
- **Aufgabe 3:** Zuerst auf den Namen „Karl Alt“ in der Liste geklickt, dabei passierte nichts. Dann den Stift → „Gerätewart“ → „Speichern“. Meldung: „Karl Alt hat jetzt die Rolle „Gerätewart“; sie gilt beim nächsten Aufruf der App.“
- **Aufgabe 4:** Stift bei Otto → „Otto Austritt löschen (ausgetreten) …“ → Rückfrage („lässt sich nicht rückgängig machen“) → „Löschen“. Meldung: „Otto Austritt wurde gelöscht.“
- **Aufgabe 5:** „Einstellungen“ → „Erscheinungsbild der Feuerwehr“, Hex-Wert eingetippt. Die Vorschau wurde sofort blau, dazu der Hinweis „Kontrast 11,28:1“. Danach „Farbe speichern“, Meldung „Akzentfarbe gespeichert“. Mein nächster Klick landete auf „Passwort ändern“ statt auf „Logo hochladen …“, weil die Seite verrutscht war. Danach „Logo hochladen …“: Es öffnet sich die Dateiauswahl, im Test nicht sichtbar. Der Text sagt, dass PNG, JPG und SVG gehen und nicht quadratische Bilder einen Rand bekommen. Eine quadratische PNG wäre also gar nicht nötig.
- **Aufgabe 6:** „Verleih“ → Knopf „Rückgabe-Aushang“ oben (springt hin) → bei „Rückgabe-Regal“ „Aushang erneuern …“ → Rückfrage „Der bisherige Aushang gilt danach nicht mehr“ → „Neuen Aushang erzeugen“. Danach erschienen der Link, „Aushang drucken“ und „Link kopieren“. Auf „Aushang drucken“ geklickt; der Druckdialog ist im Test nicht sichtbar.

## 3. Probleme

**P1 – Name in der Benutzerliste ist nicht anklickbar** (Schweregrad 2)
- Ort: Benutzer → Konten, Zeile „Karl Alt“.
- Was passiert ist: Ich habe auf den fett gedruckten Namen geklickt und nichts ist passiert. Bearbeiten geht nur über den kleinen Stift ganz rechts, rund 1 000 px vom Namen entfernt.
- Erwartet: Wie in jeder App öffnet ein Klick auf Name oder Zeile die Person.
- Foto: `shots/admin-jung-08-a3-kontenliste.png`
- Vorschlag: Ein Klick irgendwo in der Zeile öffnet „… bearbeiten“, wie in der Ausrüstungsliste. Oder den Stift mit Text versehen: „Bearbeiten“.

**P2 – Seite verrutscht nach „Farbe speichern“ → Fehlklick** (Schweregrad 2)
- Ort: Einstellungen → Erscheinungsbild der Feuerwehr.
- Was passiert ist: Nach dem Speichern verschwinden „Farbe speichern“ und „Verwerfen“, und oben erscheint die grüne Meldung. Alles darunter verschiebt sich. Mein nächster Klick in Richtung „Logo hochladen …“ öffnete stattdessen „Passwort ändern“.
- Erwartet: Die Knöpfe bleiben stehen, z. B. gesperrt, bis wieder etwas geändert wird.
- Foto: `shots/admin-jung-13-a5-logo-klick.png`
- Vorschlag: Die Meldung an der Stelle der Knöpfe zeigen, nicht oben einfügen. Oder Platz für die Meldung frei halten.

**P3 – Keine Vorschau des Aushangs vor dem Drucken** (Schweregrad 2)
- Ort: Benutzer → Einladungscodes, nach „Code erzeugen“. Ebenso Verleih → Rückgabe-Aushang.
- Was passiert ist: „Aushang drucken“ geht direkt in den Druck. Wie der Zettel aussieht, wusste ich nur aus der Anleitung. Im Test war nach dem Klick nichts zu sehen.
- Erwartet: Erst sehen, was auf den Zettel kommt (vor allem: steht die Rolle oder meine Bezeichnung drauf?).
- Fotos: `shots/admin-jung-06-a2-code-erzeugt.png`, `shots/admin-jung-07-a2-nach-drucken.png`
- Vorschlag: Ein Knopf „Aushang ansehen“ mit Vorschau und darin „Drucken“, oder ein kleines Vorschaubild neben dem Knopf.

**P4 – Zähler beim Code „Dienstabend November“ passt nicht** (Schweregrad 2)
- Ort: Benutzer → Einladungscodes, Spalte „Genutzt“.
- Was passiert ist: Dort stand „0 von 50 Registrierungen“, obwohl die Karte bei Erika sagte: „Registriert … mit dem Aushang „Dienstabend November““.
- Erwartet: „1 von 50“.
- Foto: `shots/admin-jung-06-a2-code-erzeugt.png`
- Hinweis: Das kann an den Testdaten liegen. Ich habe es nur so gesehen.
- Vorschlag: Prüfen, ob der Zähler bei der Registrierung hochzählt.

**P5 – Rollen „Benutzer“ und „Geräteträger“ kaum zu unterscheiden; vorausgewählt ist „Benutzer“** (Schweregrad 2)
- Ort: Fenster „Erika Probe freischalten“, Liste „Rolle“.
- Was passiert ist: Bei beiden steht „Sieht: Suchen, Termine, Profil“. Bei „Benutzer“ steht „Wie bisher; bekommt jede neue Stadt“, und das sagt mir nichts. Weil „Benutzer“ vorausgewählt war, hätte ich beinahe einfach auf „Freischalten“ getippt.
- Erwartet: Eine kurze Beschreibung, was die Person darf.
- Foto: `shots/admin-jung-03-a1-freischalten-dialog.png`
- Vorschlag: Bei „Benutzer“ etwa „Bucht um und meldet Status“ statt „Wie bisher; bekommt jede neue Stadt“. Den Unterschied zu „Geräteträger“ (darf benutzt melden und tauschen) als „Darf:“-Zeile zeigen.

**P6 – Gesperrter Knopf sieht aktiv aus** (Schweregrad 2)
- Ort: Verleih → In der Rückgabe, Knopf „In Ordnung ins Lager …“.
- Was passiert ist: Der Knopf ist gesperrt, solange nichts angehakt ist, wirkt aber als gefüllter, graublauer, großer Knopf wie ein Hauptknopf.
- Erwartet: Ein deutlich blasser Knopf oder ein Satz wie „Erst Ausrüstung anhaken“.
- Foto: `shots/admin-jung-16-a6-aushang-bereich.png`
- Vorschlag: Gesperrte Knöpfe blass bzw. umrandet zeigen. Daneben einen Hinweis „Wählen Sie zuerst Ausrüstung aus“.

**P7 – Knopf „Speichern“ in der Farbvorschau wirkt echt** (Schweregrad 1)
- Ort: Einstellungen → Vorschau.
- Was passiert ist: Ein großer roter bzw. blauer Knopf „Speichern“ steht direkt neben dem Farbfeld. Ich war kurz unsicher, ob ich dort speichere.
- Foto: `shots/admin-jung-11-a5-einstellungen.png`
- Vorschlag: Den Knopf in der Vorschau „Beispiel-Knopf“ nennen oder die Vorschau grau umranden und „nur Vorschau“ dazuschreiben.

**P8 – Langer Erklärtext beim Rückgabe-Aushang** (Schweregrad 1)
- Ort: Verleih → Rückgabe-Aushang.
- Was passiert ist: Zwei Absätze über Rückgabestelle und Sammelstelle. Ich habe sie übersprungen und war dann kurz unsicher, welche der zwei Zeilen („Rückgabe-Regal“, „Sammelstelle“) ich erneuern soll. Entschieden habe ich nach „Aushang gültig seit …“.
- Foto: `shots/admin-jung-16-a6-aushang-bereich.png`
- Vorschlag: Je Zeile einen kurzen Satz, z. B. „Rückgabe-Regal: Zurückgebrachtes wartet hier auf Prüfung“ und „Sammelstelle: kommt direkt in die Wartung“. Den langen Text einklappen.

## 4. Was gut funktioniert hat

- Die wartende Anmeldung steht am Handy ganz oben auf „Benutzer“, groß und gelb hinterlegt, mit „Freischalten …“ direkt daneben. Die grüne Bestätigung nennt die Rolle.
- Der Name ist im Freischalt-Fenster schon vorausgefüllt, die Rollen haben große Flächen, die man gut antippen kann.
- Der Hinweis im Code-Fenster, warum es keine Admin-Codes gibt, ist verständlich.
- Nach „Code erzeugen“ stehen Code, QR-Code und Knöpfe groß und deutlich da, dazu die Warnung „Der Code erscheint nur jetzt“.
- „… löschen (ausgetreten) …“ passt genau zur Situation. Die Rückfrage sagt klar, was passiert und dass es nicht rückgängig geht.
- Das Hex-Feld mit Live-Vorschau und Kontrast-Prüfung. Das Menü färbt sich nach dem Speichern sofort blau.
- Auf „Verleih“ springt der Knopf „Rückgabe-Aushang“ direkt zum Abschnitt. Die Rückfrage beim Erneuern erklärt, dass der alte Aushang ungültig wird.

## 5. Unklare Begriffe

- „Wie bisher; bekommt jede neue Stadt“ (Rolle Benutzer) → keine Ahnung, „wie bisher“ im Vergleich zu was? Welche Stadt?
- „Akzentfarbe“ → habe ich als „Hauptfarbe der App“ verstanden.
- „Rückgabestelle“ und „Sammelstelle“ beim Aushang → Rückgabestelle = Regal, wo Zurückgebrachtes abgelegt wird; Sammelstelle = da kommt Kaputtes hin. Den Unterschied musste ich mir aus dem langen Text zusammenreimen.
- „Anzeigename“ → klar, wurde erklärt.
- „Gilt beim nächsten Aufruf der App“ → verstanden als „Karl muss die App neu öffnen“.

## Technische Hinweise

- Der Druckdialog und die Dateiauswahl für das Logo sind im Test-Browser nicht sichtbar. Was danach passiert, konnte ich nicht prüfen.
- Bilder liegen unter `shots/admin-jung-01…18-*.png`.
