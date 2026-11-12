# Benutzertest mit KI-Personas – Bericht (12.11.2026)

Auftrag Holger, 12.11.2026. Getestet wurde der gebaute Stand `main` (`c223dcb`). Nur testen und berichten: Am App-Code wurde nichts geändert.
Einzelberichte der zwölf Personas: [protokolle/](protokolle/) (`*-bericht.md`, dazu die Protokolle der Treiberbefehle `*.jsonl`).
Bildschirmfotos (nur erfundene Daten): [bilder/](bilder/). Werkzeug: `frontend/tests/usability/` (Treiber, Einrichtung, Anweisung an die Personas).

## Kurzfassung

Alle zwölf Personas haben nahezu alle Aufgaben gelöst, mit Einfachheit 5–6 von 7 (Ausnahmen: Tauschen am Handy 4–5, Rückgabe-Nachweis der Boten 3–4,
Sollbestand beim älteren Gerätewart 4). Es gibt also keine Aufgabe, die die Oberfläche verhindert. Die Probleme liegen bei **fehlender Rückmeldung**
(Rückgabe angekommen? Liste kopiert? Hinweis „wird gesendet“ bleibt stehen), bei **zu kleinen Zielen** (Auswahlpunkte und Kästchen 13–22 px, am
Handy und bei 125 % Zoom besonders), bei **schwer auffindbaren Funktionen** (Sollbestand ganz unten auf „Standorte“, Rückgabe-Aushang ganz unten auf „Verleih“,
Löschen ganz unten im Bearbeiten-Fenster) und bei **einzelnen Begriffen** („nicht verliehen“ für einen Tippfehler, „Bringen“, „Claude-Zugang“, „M5 ist danach“).
Am Handy kostet vor allem das Tauschen-Fenster (lange Ersatzliste, winzige Auswahlpunkte, bei 125 % rechts abgeschnitten) Zeit. Die drei Boten wussten nach der
Rückgabe nicht sicher, ob sie angekommen ist, und würden anrufen. Die Anleitungen deckten die Aufgaben von Gerätewart und Admin nicht ab (mit Einschränkung, siehe Grenzen).

## Top 10 der Probleme

| # | Problem | Betroffen | Schwere | Aufwand |
|---|---|---|---|---|
| 1 | **Rückgabe ohne Beleg:** Nach „Danke!“ gibt es keinen Nachweis (Uhrzeit, Zustand, Hinweis); gibt der Bote dieselbe Nummer noch einmal ein, kommt „nicht verliehen“ wie bei einem Tippfehler (U1) | alle 3 Boten | 3 | S |
| 2 | **Tauschen-Fenster am Handy** (lange Ersatzliste, gewählter Ersatz scrollt aus dem Bild, „M5 ist danach“ erst nach Scrollen und vorbelegt mit „benutzt“, bei 125 % rechts abgeschnitten) (U2) | Geräteträger alle 3 Altersgruppen | 3 | M |
| 3 | **Zu kleine Ziele:** Auswahlpunkte und Kästchen 13–22 px (Tauschen, Rückgabe-Liste, Zustand der Rückgabeseite, Rollen im Freischalten-Fenster, Wartungsliste) (U3) | fast alle, am stärksten ältere | 3 | S–M |
| 4 | **Sollbestand nicht zu finden:** ganz unten auf „Standorte“ (4–6× scrollen); Fächer im Auswahlfeld ohne Fahrzeugname („G1“); zwei Regeln statt einer unklar; LF 10 steht danach sofort „außer Betrieb“ ohne Erklärung (U4) | alle 3 Gerätewarte | 3 | S–M |
| 5 | **„Transportliste kopieren“ ohne Rückmeldung**; richtige Reihenfolge (erst Liste, dann verschieben) sagt niemand; die Liste ist danach leer (U5) | alle 3 Gerätewarte | 3 | S |
| 6 | **Rückgabe-Aushang ganz unten auf „Verleih“** (nach allen verliehenen Geräten, 5× scrollen); Name „Rückgabe-Regal“ erklärt nicht, dass es der Aushang ist (U6) | Admin älter, Gerätewart älter | 3 | S |
| 7 | **Benutzer „Bearbeiten“-Fenster:** „Speichern“, „sperren“ und „löschen“ liegen weit unten; „Claude-Zugang“ ist für Laien unverständlich und macht das Fenster länger; Aktion heißt „löschen“, gesucht wurde „entfernen“ (U7) | alle 3 Admins | 2–3 | S–M |
| 8 | **Termine ohne „diese Woche“:** Seite 1950–3000 px lang, man rechnet selbst (U8) | Geräteträger alle 3 | 2 | S |
| 9 | **Hinweis „Gespeichert – wird gesendet …“ bleibt stehen**, obwohl die Buchung schon angekommen ist (U9) | Geräteträger alle 3 | 2 | S |
| 10 | **Wartung „Alle auswählen“** nimmt defekte Ausrüstung (PA 11) mit; Knopf „Bringen“ passt nicht zu „Zurück ins Lager“ (U10) | alle 3 Gerätewarte | 2 | S |

Weitere Probleme (U11 ff.) stehen unten.

## Matrix Persona × Aufgabe (Ergebnis / Einfachheit 1–7)

`ja` = ohne Umweg, `um` = gelöst mit Umweg, `–` = nicht prüfbar. Bei Gerätewarten sind die Aufgaben 3–8 der jungen und mittleren Persona aus dem zweiten Durchgang (siehe Grenzen).

**Geräteträger (Handy)**

| Aufgabe | jung (iPhone) | mittel (Pixel 7) | älter (iPhone, 125 %) |
|---|---|---|---|
| 1 zwei Masken benutzt melden | ja / 6 | ja / 6 | ja / 6 |
| 2 PA 3 defekt melden | ja / 6 | um / 6 | ja / 6 |
| 3 Funkgerät HLF → LF | ja / 6 | ja / 6 | um / 5 |
| 4 Maske tauschen | um / 4 | um / 5 | um / 5 |
| 5 offline umbuchen | ja / 6 | ja / 6 | ja / 6 |
| 6 Wartung diese Woche | ja / 5 | ja / 6 | um / 5 |
| 7 Einmalgerät benutzt melden | ja / 6 | um / 6 | ja / 6 |

**Gerätewart (PC, älter zusätzlich Tablet)**

| Aufgabe | jung (1920) | mittel (Laptop) | älter (Laptop 125 %, Tablet) |
|---|---|---|---|
| 1 Sammelstelle → Werkstatt, Liste | um / 5 | um / 5 | ja / 6 |
| 2 Gewartetes zurück ins Lager | ja / 6 | ja / 6 | ja / 5 |
| 3 M41–M45 anlegen | ja / 5 | um / 5 | ja / 5 |
| 4 Sollbestand LF 10 | ja / 5 | ja / 5 | um / 4 |
| 5 zwei Geräte verleihen | ja / 5 | ja / 5 | um / 5 (Tablet) |
| 6 Rückgabe bearbeiten | ja / 6 | ja / 6 | ja / 5 |
| 7 Hinweis erledigen | ja / 6 | ja / 6 | ja / 5 |
| 8 Wartungstermin aller Funkgeräte | ja / 6 | ja / 6 | ja / 6 |

**Admin (PC, einmal Handy)**

| Aufgabe | jung | mittel | älter |
|---|---|---|---|
| 1 freischalten, Rolle Geräteträger | ja / 6 | ja / 6 | ja / 6 |
| 2 Einladungscode und Aushang | ja / 6 | ja / 6 | ja / 6 |
| 3 Kamerad zum Gerätewart | ja / 6 | ja / 6 | ja / 5 |
| 4 Kamerad entfernen | ja / 6 | um / 5 | ja / 5 |
| 5 Logo und Farbe | ja / 5 (Logo: nur Weg) | ja / 6 (Logo: nur Weg) | um / 5 (Logo: nur Weg) |
| 6 Rückgabe-Aushang erneuern | ja / 6 | ja / 6 | ja / 5 |

**Bote (Handy, ohne Anleitung)**

| Aufgabe | jung (Pixel 7) | mittel (iPhone) | älter (Pixel, 125 %) |
|---|---|---|---|
| 1 zwei Geräte zurückgeben (mit Tippfehler) | um / 6 | um / 6 | um / 6 |
| 2 prüfen, ob es angekommen ist | um / 4 | um / 4 | um / 3 |

Der „Umweg“ der Boten bei Aufgabe 1 ist der absichtliche Tippfehler. Aufgabe 2 hat keinen eigentlichen Weg (siehe U1).

## Probleme im Einzelnen

Schweregrad: 1 kosmetisch, 2 klein, 3 groß, 4 verhindert die Aufgabe. Aufwand: S bis etwa einen halben Tag, M bis etwa zwei Tage, L mehr.
„Phils Antworten“: Wer Phil ist und welche Antworten gemeint sind, ist mir nicht bekannt; ich kann das nicht beurteilen und habe es bei jedem Problem offen gelassen (bitte Holger ergänzen).
Fotos: Pfade relativ zu `docs/usability/`.

### U1 – Rückgabe: kein Beleg, missverständliche Meldung (Schwere 3, S)
- Ort: öffentliche Rückgabeseite, Schritt „Prüfen“ und Seite „Danke!“.
- Beschreibung: Die Danke-Seite nennt nur Nummer und Ablageort. Der gemeldete Zustand („defekt“, Hinweis „Akku defekt“), Uhrzeit und Name fehlen. Gibt man dieselbe Nummer noch einmal ein, kommt „Diese Ausrüstung ist nicht verliehen. Bitte Nummer prüfen.“ – dieselbe Meldung wie bei einem Tippfehler. Dazu klingt „nicht verliehen“ so, als gäbe es die Ausrüstung, aber sie sei nicht ausgeliehen.
- Personas: Bote jung, mittel, älter. Zitate: „Und woher weiß ich jetzt, dass es geklappt hat?“ (jung), „Ob das jetzt geklappt hat? Ich ruf lieber an.“ (älter). Einfachheit der Aufgabe 2: 4, 4, 3.
- Fotos: `bilder/courier-mittel-07-bote2-nochmal.png`, `bilder/courier-aelter-08-b2-nochmal.png`, `bilder/courier-jung-01-bote-tippfehler.png`.
- Ursache (vermutet): Die Seite kennt nach der Rückgabe nur den Zustand „nicht verliehen“; es gibt keinen Beleg und keine Unterscheidung „Nummer unbekannt“ / „schon zurückgegeben“.
- Vorschlag: Danke-Seite mit Beleg („HRT 14 · defekt · Hinweis: Akku defekt · heute 14:02 · von Sabine M. – Diese Meldung ist angekommen, Sie müssen nichts weiter tun“). Bei erneuter Eingabe: „HRT 14 wurde heute um 14:02 zurückgegeben.“ Bei unbekannter Nummer: „Diese Nummer kennen wir nicht – bitte so schreiben wie auf dem Etikett, z. B. HRT 14.“ Leerzeichen und Groß-/Kleinschreibung in der Eingabe tolerieren („HRT16“ → „HRT 16“). Hinweis: Das berührt die öffentliche Rückgabe (`returns/check`), also auch das Backend (Meldungen, „schon zurückgegeben“ ohne Daten zu verraten); ob die Seite wiederholt Auskunft über eine Nummer geben soll, ist eine Datenschutzfrage.
- Phils Antworten: nicht beurteilbar.

### U2 – Tauschen-Fenster am Handy (Schwere 3, M)
- Ort: Umbuchen-Seite, „Tauschen …“.
- Beschreibung: Das Fenster ist sehr lang (17 gleich aussehende Masken als Ersatz). Der gewählte Ersatz scrollt aus dem Bild. Die Statuswahl „M5 ist danach“ erscheint erst nach dem Scrollen und ist mit „benutzt“ vorbelegt, obwohl die Aufgabe „Maske nicht mehr gut“ lautete (Gefahr, es zu übersehen). Der Begriff „M5 ist danach“ ist unklar. Bei 125 % Zoom ist das Fenster rechts abgeschnitten (Standort „Lager Geräteh…“ nicht lesbar). „Speichern“ ist ohne Erklärung gesperrt; „Tauschen …“ steht darunter unscheinbar.
- Personas: Geräteträger jung (Einfachheit 4, 11 Aktionen), mittel (5), älter (5). Zitat jung: „Warum ist hier schon ‚benutzt‘ vorausgewählt? Die Maske ist doch kaputt.“
- Fotos: `bilder/carrier-jung-01-t4-tauschen.png`, `bilder/carrier-aelter-02-g4-dialog.png`, `bilder/carrier-mittel-01-g4-tausch.png`.
- Vorschlag: Oben „M5 ersetzen durch …“ mit dem gewählten Ersatz im Kopf, darunter die Frage „Was ist mit M5?“ als drei große Knöpfe („Benutzt“, „Defekt“, „In Wartung“) ohne Vorauswahl; Ersatzliste als ganze antippbare Zeilen mit Wartungsdatum als Unterscheidung und mit Suchfeld; Knopf „Tauschen“ fest am unteren Rand; Zeilen umbrechen, kein Überstand bei 125 %. Statt „M5 ist danach“: „Zustand von M5“.
- Phils Antworten: nicht beurteilbar.

### U3 – Zu kleine Bedienziele (Schwere 3, S–M)
- Ort: Tauschen-Fenster (13 px), Rückgabe-Liste unter „Verleih“ (13 px), Zustand auf der Rückgabeseite (20 px), Rollenauswahl in „Freischalten“ und „Bearbeiten“ (20 px), Kästchen in der Wartung (20–22 px).
- Personas: fast alle; am deutlichsten ältere und alle am Handy. Zitate: „Wo tippe ich da genau, die Punkte sind winzig.“ (Geräteträger älter), „Die Kästchen sind winzig, da treffe ich mit dem Finger nicht.“ (Gerätewart älter), „Das Kästchen ist winzig.“ (Gerätewart jung).
- Fotos: `bilder/keeper-aelter-18-g6-liste.png`, `bilder/carrier-jung-01-t4-tauschen.png`.
- Ursache: Der Auswahlpunkt bzw. das Kästchen selbst ist 13–22 px groß; wo die ganze Zeile antippbar ist, sieht man das nicht.
- Vorschlag: Mindestgröße 44 px für das tippbare Ziel (ganze Zeile oder Karte antippbar machen und das Auswahlsymbol größer zeichnen); die Zeile bei Auswahl sichtbar hinterlegen. Die Tokens kennen die Mindestgröße 44 px bereits; Kontrolle per Test (kleinste Fläche der Bedienelemente je Seite).
- Phils Antworten: nicht beurteilbar.

### U4 – Sollbestand: nicht zu finden, schwer zu bedienen (Schwere 3, S–M)
- Ort: „Standorte“, ganz unten, und das Fenster „Sollbestand festlegen“.
- Beschreibung: Der Bereich liegt am Ende der langen Seite (4–6× scrollen). Im Fenster stehen Fächer nur als „G1“, „G3“, „Fahrerhaus“ ohne Fahrzeug. Dass „mehrere Typen = gleichwertig, zählen zusammen“ gilt, ist leicht zu überlesen; für Masken und Geräte braucht man zwei Regeln. Nach dem Speichern steht das LF 10 sofort „außer Betrieb“, ohne zu erklären, dass das die Folge der neuen Regel ist.
- Personas: Gerätewart jung (5), mittel (5), älter (4; „Habe ich etwas kaputt gemacht?“).
- Fotos: `bilder/keeper-aelter-11-g4-liste.png`, `bilder/keeper-mittel-05-a4.png`.
- Vorschlag: Oben auf „Standorte“ ein Link bzw. Reiter „Sollbestand“ (oder eigener Menüpunkt); im Fenster Pfad „LF 10 / G1“; unter den Typ-Kästchen der Satz „Mehrere Typen werden zusammen gezählt – für getrennte Zahlen eine zweite Regel anlegen.“; nach dem Speichern: „LF 10 hat zurzeit nur 2 von 4 Masken und gilt deshalb als außer Betrieb.“ (grüne bzw. gelbe Meldung).
- Phils Antworten: nicht beurteilbar.

### U5 – „Transportliste kopieren“ ohne Rückmeldung (Schwere 3, S)
- Ort: Wartung, Sammelstelle.
- Beschreibung: Nach dem Klick passiert sichtbar nichts. Die Reihenfolge (erst Liste kopieren, dann verschieben; danach ist die Sammelstelle leer) wird nirgends gesagt. Der Fahrer braucht eigentlich etwas zum Weitergeben, nicht nur die Zwischenablage.
- Personas: Gerätewart jung, mittel, älter (Einfachheit 5, 5, 6). Zitat: „Die Liste für den Fahrer – kopiert, aber hat es geklappt? Ich sehe nichts.“
- Fotos: `bilder/keeper-jung-01-g1a.png`, `bilder/keeper-aelter-03-g1-liste.png`.
- Vorschlag: Meldung „Liste kopiert (3 Ausrüstungen)“ am Knopf (nach 3 s ausblenden, auch für Bildschirmleser); zusätzlich „Liste drucken“ bzw. Vorschau (Hinweis T20 in `docs/TODO-SPAETER.md` kennt CSV/Druck schon); im Verschieben-Fenster der Satz „Transportliste vorher kopieren?“ bzw. nach dem Verschieben die Liste in der Werkstatt-Gruppe anbieten.
- Phils Antworten: nicht beurteilbar.

### U6 – Rückgabe-Aushang ganz unten auf „Verleih“ (Schwere 3, S)
- Ort: „Verleih“, Abschnitt „Rückgabe“.
- Beschreibung: Der Admin muss durch alle verliehenen Geräte scrollen (5×). Der Name der Stelle („Rückgabe-Regal“ im Testdatensatz) verrät nicht, dass dort der Aushang liegt; „Widerrufen …“ neben „Aushang erneuern …“ ist unklar. Der Gerätewart älter suchte die Rückgabe unter „Wartung“ (sie liegt unter „Verleih“).
- Personas: Admin älter (Einfachheit 5, „Warum steht das unter Verleih ganz unten?“), Gerätewart älter.
- Foto: `bilder/admin-aelter-19-a6-verleih.png`.
- Vorschlag: Oben auf „Verleih“ Reiter oder Sprunglinks „Verliehen“ / „Rückgabe“; Überschrift „Rückgabe-Aushang“ mit einem Satz („So bringen andere Wehren Ausrüstung zurück – Aushang ausdrucken“); „Aushang erneuern …“ und „Aushang abschalten …“ statt „Widerrufen“.
- Phils Antworten: nicht beurteilbar.

### U7 – Benutzer „Bearbeiten“-Fenster (Schwere 2–3, S–M)
- Ort: Benutzer → Stift → Fenster „Bearbeiten“ (auch „Freischalten“).
- Beschreibung: Das Fenster ist höher als ein Laptop-Bildschirm (768 px). „Speichern“ und „sperren …/löschen …“ liegen unten. Der Block „Claude-Zugang Aus/Lesen/Ändern“ ist ohne Erklärung („Hab ich da was verstellt?“), verlängert das Fenster und steht direkt über „sperren/löschen“. Die Aktion heißt „löschen“, gesucht wurde „entfernen“ oder „ausgetreten“. Der Stift in der Zeile hat keinen Text. Im Freischalten-Fenster ist der Anzeigename vormarkiert, Gefahr beim Tippen.
- Personas: Admin jung, mittel (Einfachheit 5, 15 statt 5 Aktionen), älter. Zitat älter: „Ich suche ‚entfernen‘ und finde ‚löschen‘, ganz unten im Bearbeiten-Fenster.“
- Fotos: `bilder/admin-mittel-04-a4-dialog.png`.
- Wichtiger Vorbehalt: Mehrere Admins schrieben, „Scrollen im Fenster“ habe nicht funktioniert und sie seien nur per Tab-Taste nach unten gekommen. Das kann am Test-Treiber liegen (Mausrad an einer festen Mausposition) und ist nicht bestätigt; prüfen.
- Vorschlag: „Abbrechen/Speichern“ fest am unteren Fensterrand; „Konto sperren“/„Konto löschen (ausgetreten)“ als eigener Abschnitt „Konto beenden“ mit Trennlinie; Claude-Zugang einklappen und erklären („Claude ist ein KI-Assistent. Hier legen Sie fest, ob er für dieses Konto Ausrüstung lesen oder ändern darf.“) bzw. nur zeigen, wenn die Stadt ihn nutzt (tut das heute, in der Test-Stadt ist er verfügbar); Beschriftung am Stift („Bearbeiten“ als Text am Handy, Tooltip am PC).
- Phils Antworten: nicht beurteilbar.

### U8 – „Termine“ ohne „diese Woche“ (Schwere 2, S)
- Ort: Handy → Termine.
- Beschreibung: Die Seite hat „Überfällig“ und „In den nächsten 30 Tagen fällig“, ist rund 1950–3000 px lang. Wer wissen will, was diese Woche fällig ist, rechnet selbst. Nach dem Öffnen stand die Seite teilweise unten (Scrollposition der vorigen Seite, nur bei einer Persona beobachtet).
- Personas: Geräteträger jung (5), mittel, älter (5; „Ich wollte nur diese Woche sehen, nicht 30 Tage.“).
- Foto: `bilder/carrier-jung-05-t6-termine.png`.
- Vorschlag: Kopf mit Zahlen („10 überfällig · 3 diese Woche · 7 später“), Abschnitt „Diese Woche“ vor „In den nächsten 30 Tagen“ oder Umschalter „7 Tage / 30 Tage“; Standard-Ansicht kürzer (z. B. zuerst 10, dann „Alle anzeigen“).
- Phils Antworten: nicht beurteilbar.

### U9 – Veralteter Hinweis nach Offline-Buchung (Schwere 2, S)
- Ort: Umbuchen-Seite / Suchen nach einer Offline-Buchung.
- Beschreibung: Nach Rückkehr des Netzes bleibt oben „Gespeichert – wird gesendet, sobald eine Verbindung besteht“ stehen, obwohl darunter „beim Server angekommen“ steht. Verwirrt vor allem ältere Nutzer („ist es nun da oder nicht?“).
- Personas: Geräteträger jung, mittel, älter. Foto: `bilder/carrier-mittel-02-g5-offline.png`.
- Vorschlag: Hinweis nach dem Senden durch „Angekommen um 14:05“ ersetzen oder ausblenden.
- Phils Antworten: nicht beurteilbar.

### U10 – Wartung: „Alle auswählen“ und Knopftext (Schwere 2, S)
- Ort: Wartung, Werkstatt und Sammelstelle.
- Beschreibung: „Alle auswählen“ nimmt auch die defekte Ausrüstung (PA 11) mit; sie würde als „gewartet“ einsatzbereit gesetzt, eine Warnung gibt es nicht (alle drei Gerätewarte mussten sie von Hand abwählen). Der Knopf im Fenster heißt „Bringen“, auch bei „Zurück ins Lager“.
- Personas: Gerätewart jung, mittel, älter.
- Vorschlag: defekte Ausrüstung in der Werkstatt-Liste kennzeichnen („defekt – bleibt in der Werkstatt“) und nicht vorauswählen; Knopf nach Ziel: „Ins Lager bringen“, „In die Werkstatt bringen“, „In die Sammelstelle bringen“.
- Phils Antworten: nicht beurteilbar.

### Weitere Probleme

| ID | Ort | Beschreibung | Personas | Schwere | Vorschlag | Aufwand |
|---|---|---|---|---|---|---|
| U11 | Umbuchen, Status „defekt“ | PA 3 bleibt nach „defekt“ im Fahrzeug (HLF 20 / G3); die Bestätigung sagt nicht, dass jemand sie ausbauen soll. | Geräteträger alle 3 | 2 | Hinweis „Bitte aus dem Fahrzeug nehmen“; Standort-Vorschlag Werkstatt | S |
| U12 | Neue Ausrüstung, Standortauswahl | Die Liste zeigt zuerst nur 10 Einträge, Enter im Standortfeld öffnet sie wieder statt zu speichern, „Speichern“/„Speichern und nächstes“ liegen bei 768 px unter dem Bildschirm; Meldung zeigt mehrfach „M41 angelegt“, obwohl die Nummer schon weiterzählt. | Gerätewart mittel (26 Aktionen), jung, älter | 2–3 | Suche in der Auswahl, Fokus nach der Wahl zurück ins Feld „Nummer“, Knöpfe am unteren Rand festhalten, Meldung mit der Nummer des gerade angelegten | M |
| U13 | Verleih-Fenster | Nummern nur einzeln eintippen; kein Auswählen aus der Liste; „Hinzufügen“ bleibt nach dem Hinzufügen grau; „Rückgabe bis“ nimmt am PC nur das Format des Browsers (Eingabe „20.11.2026“ scheiterte bei zwei Personas). | Gerätewart jung, mittel, älter | 2 | Platzhalter „TT.MM.JJJJ“ und deutsche Eingabe zulassen; Suche mit Mehrfachauswahl | M |
| U14 | Einladungscode | Gültigkeit nur in Tagen (30), kein Datum, obwohl der Code „für den nächsten Dienstabend“ gedacht ist; Rolle vorbelegt „Benutzer“ (nach Freischaltung nur ein Vorschlag, das merkt man nicht). | Admin jung, mittel | 1–2 | Schnellwahl „7 Tage / 30 Tage / bis Datum“; Hinweis am Rollenfeld | S |
| U15 | Einstellungen | Farbe nur als Hex-Feld, Farbkreise nicht sofort gesehen; nach „Farbe speichern“ verschieben sich die Elemente (Knöpfe verschwinden), ein Admin öffnete versehentlich „Passwort ändern“. | Admin jung, mittel, älter | 2 | Farbkreise oben, Layout nach dem Speichern stabil halten | S |
| U16 | Aushang drucken, Logo | Druckdialog und Dateiauswahl lassen sich im Test nicht bedienen; nicht beurteilbar. Hinweistexte waren verständlich. | alle Admins | – | per Hand prüfen (Browser mit Drucker, echte PNG) | – |
| U17 | Protokoll | „Nur Historie (neuerer Stand vorhanden)“ ist unverständlich; „Stand beim Eingang: Erfasst heute 10:40“ ist gleich der Eingangszeit und wirkt wie ein Fehler; nach „Erledigen“ sagt niemand, ob die Ausrüstung noch geändert werden soll. | Gerätewart jung, mittel, älter | 2 | Klartext: „Ihre Buchung kam zu spät, der Stand wurde nicht geändert“; Zeile ausblenden, wenn gleich | S |
| U18 | Tablet, Menü | Menü nur als ☰-Symbol ohne Text; ein Fehlklick öffnete „Protokoll“. | Gerätewart älter | 2 | „Menü“ als Text neben das Symbol | S |
| U19 | Termine/Suche | Suche „F 1“ liefert 54 Treffer (nach Teilwort in vielen Feldern); Einmalgerät ist in der Karte nicht als solches gekennzeichnet; „Benutzt melden“ muss man selbst finden. | Geräteträger mittel, älter | 1 | Genau-Treffer zuerst und abgesetzt; Chip „Einmalgerät“ | S |
| U20 | Rückfrage „Benutzt melden“ | „Es kommt in die Sammelstelle Sammelstelle.“ – doppelt, wenn die Stelle „Sammelstelle“ heißt (Name in diesem Testdatensatz selbst vergeben, aber in echten Städten wahrscheinlich ähnlich). | Geräteträger alle 3 | 1–2 | Satz nur mit Namen: „Es kommt zur Sammelstelle „Kleiderkammer“.“; bei gleichem Namen ohne Wiederholung | S |
| U21 | Benutzt melden, erster Klick | Beim ersten schnellen Klick erschien keine Rückfrage (vermutlich 400-ms-Schutz des Bestätigungsknopfs bzw. Seite im Aufbau); wirkt „tot“. | Geräteträger älter | 1 | Knopf während der Sperre sichtbar „gleich bereit“ zeigen | S |
| U22 | Rückgabeseite | Die Bildanleitung oben braucht Platz, Name, Zustand und „Zurückgeben“ liegen erst nach dem Scrollen im Bild; „Prüfen“ wirkt grau/zweitrangig (verwirrt: „Prüfen“ = die Ausrüstung wird geprüft?); nach „Weitere Ausrüstung“ steht die Seite wieder oben, Fokus nicht im Nummernfeld; Fehlermeldung liegt bei 125 % am unteren Rand. | Boten alle 3 | 1–2 | Bilder einklappbar oder unter das Formular; Knopf „Nummer suchen“; Fokus ins Nummernfeld | S |
| U23 | Anleitung | Die Anleitungen für Gerätewart und Admin (erste 4 Seiten gelesen) behandelten Wartung, Verleih, Sollbestand, Benutzer, Einladungen und Einstellungen nicht. Die Personas nutzten die Anleitung kaum. | Gerätewart und Admin, alle Altersgruppen | 2 | Anleitungen um diese Kapitel ergänzen (siehe Grenzen: nur 4 Seiten gelesen) | M |

## Was gut funktioniert hat

- **Suche und Umbuchen am Handy:** Suche nach Nummer, Karten, Standortwahl mit Suchfeld und „zuletzt“-Ziel; „Benutzt melden“ mit klarer Rückfrage (Abbrechen als Standard) und grüner Bestätigung „M1 → Sammelstelle · benutzt“.
- **Pflichtfeld bei „defekt“:** „Was ist defekt?“ erscheint von selbst.
- **Offline:** Streifen „Offline – angezeigt wird der Stand vom …“, Buchung wird gespeichert, nach Netz-Rückkehr automatisch gesendet, grüner Haken „beim Server angekommen“.
- **Termine:** Rot/gelb mit „seit N Tagen überfällig“; **Tauschen** in einem Schritt mit Bestätigung „M5 ⇄ M14 · getauscht“.
- **Gerätewart:** Sammelauswahl mit Filter für das Wartungsdatum („Sammelauswahl ist top“), „Speichern und nächstes“ mit weiterzählender Nummer, Rückgabe bearbeiten mit klaren Knopftexten („Benutzt in die Sammelstelle“) in 4–5 Aktionen, Hinweis mit Buchung links und Stand rechts.
- **Admin:** Dashboard-Link „Freischaltungen ansehen“, Rollenkarten mit Kurzbeschreibung, Rückfragen beim Löschen („nicht rückgängig“) und beim Erneuern des Aushangs („der alte gilt nicht mehr“), Hinweistexte bei Logo und Einladungscode.
- **Rückgabeseite:** Vier Bilder oben erklären den Ablauf sofort, keine Anmeldung nötig, der Name wird für das zweite Gerät gemerkt, grünes Häkchen nach „Prüfen“, „In Ordnung“ vorausgewählt.
- **Lesbarkeit bei 125 %:** Schrift und Kontrast waren auf Handy und Laptop gut lesbar; Probleme bei 125 % sind der Überstand (U2) und die Länge der Seiten.

## Grenzen des Tests

- **KI-Personas statt Menschen.** Die Personas sind Sprachmodelle: Sie lesen den Text der Seite und die Liste der Bedienelemente („look“) zuverlässiger als echte Menschen, tippen nie daneben und werden nie müde. Dass sie fast alles lösen, heißt nicht, dass Menschen es ebenso leicht schaffen. Probleme mit Größe, Anordnung und Rückmeldung finden sie nur über Bildschirmfotos, Zahlen (Größe der Ziele) und ihre Rolle; echte Fehlklicks, Ablenkung und Eile fehlen.
- **Nicht gemessen:** Handschuhe, Sonne, Lärm, Zeitdruck, Zittern, Sehschwäche (nur 125 % Zoom als Näherung), Handschriften, Bildschirmleser.
- **Umgebung:** Die echte PHP-Anwendung mit lokaler Testdatenbank (nicht der Live-Server) mit erfundenen Daten; statt der geforderten Attrappe läuft das echte Backend, weil sich damit Zustand und Meldungen genau wie live verhalten. Der Browser ist durchgehend Chromium (auch für „iPhone“: iPhone-13-Maße und Mobil-Emulation; Playwright-WebKit unter Windows schneidet rechts 20 px ab). Kein echtes Safari, kein echtes Telefon, keine Kamera, kein Druckdialog, keine Dateiauswahl (Logo, Aushang drucken nicht prüfbar). Kein Service Worker; Offline über abgeschaltetes Netz, ohne Neuladen.
- **Gemeinsame Daten:** Pro Welle liefen bis zu vier Personas gleichzeitig auf demselben Datenstand. Dadurch kam es zu Störungen, die nicht der App zuzurechnen sind: Die Admin-Persona entfernte das Konto des gleichzeitig arbeitenden Gerätewarts, der dabei abgemeldet wurde (Fehler im Testaufbau, behoben durch ein eigenes Konto „Otto Austritt“); bei Gerätewart jung (Aufgaben 3–8) und Gerätewart mittel (3–8) wurde deshalb ein zweiter Durchgang mit frischen Daten gefahren (`keeper-jung-b`, `keeper-mittel-b`); die Meldung „0 geändert, 2 nicht möglich“ beim Verleih (Gerätewart mittel) und „M41 schon vergeben“ entstanden durch die parallel arbeitende Persona und sind **nicht** als Fehler der App gewertet.
- **Treiber:** Das Scrollen im Fenster (Mausrad) funktionierte in Dialogen möglicherweise nicht wie mit echter Hand (siehe U7). Alle Zahlen „Aktionen“ sind die des Treibers (Klicks, Eingaben, Tasten, Scrollen, Zurück); das Eintippen eines Datums im Browserformat war im Treiber eingeschränkt (U13 teilweise Treibergrenze).
- **Anleitung:** Die Personas lasen nach Vorgabe „höchstens 4 Seiten“; die Aussage „deckt die Aufgaben nicht ab“ (U23) gilt für diese Seiten.
- **Testdaten:** Namen wie „Rückgabe-Regal“ und „Sammelstelle“ stammen vom Aufbau. Sie haben „Sammelstelle Sammelstelle“ und die Unklarheit „Rückgabe-Regal“ verstärkt.
- **Einzelfälle:** Je Persona nur ein Durchgang; die Quote „Erfolg“ ist kein Maß. Aussagen mit nur einer Person (z. B. U21) sind Hinweise, keine Befunde.
- **Aufgaben mit Vorwissen:** Die Persona kannte durch die Aufgabenbeschreibung Wörter wie „Sammelstelle“ oder „benutzt“. Das war gewollt, ersetzt aber nicht die Frage, ob Neulinge diese Wörter verstehen.

## Wiederholung

`node frontend/tests/usability/reset.mjs` setzt die lokale Testdatenbank (`config/config.test.php`) zurück und legt die Daten, Rollen und Konten an; ein lokaler Server mit `php -q -S localhost:4300 frontend/tests/usability/support/router.php`, dann je Persona `node frontend/tests/usability/driver.mjs --persona <name> --role <carrier|keeper|admin|courier> --device <iphone|pixel|tablet|laptop|desktop> [--zoom 1.25] --port <p> --out <ordner>` und Steuerung mit `node frontend/tests/usability/ut.mjs <port> <befehl>`. Die Anweisung an die Personas steht in `frontend/tests/usability/PERSONAS.md`. Die Datenbank der Tests (`firefighting_test`) wird dabei geleert; ein späterer `composer test` leert sie ohnehin vor jedem Test.

## Wiederholung nach den Verbesserungen (12.11.2026, Runde 15)

Stand: Build `cc8f6df5aa46` (Punkte U1–U7, U9–U11, U17, U20, U22). Wiederholt mit den **älteren** Personas (iPhone/Pixel/Laptop, 125 % Zoom) für
die geänderten Aufgaben, je ein Durchgang, gleiche Anweisung (`frontend/tests/usability/PERSONAS.md`), frisch zurückgesetzte lokale Daten.
Berichte und Bildschirmfotos: [wiederholung/](wiederholung/). Grenzen wie oben (KI-Personas, ein Durchgang, Chromium).

| Persona / Aufgabe | Einfachheit vorher | nachher |
|---|---|---|
| Bote: 1 zwei Geräte zurückgeben (mit Tippfehler) | 6 | 6 |
| Bote: 2 prüfen, ob es angekommen ist | **3** | **7** |
| Geräteträger: 2 PA 3 defekt melden | 6 | 6 (Bestätigung „Bitte … herausnehmen“ sehr gut) |
| Geräteträger: 4 Maske tauschen | 5 | 5 (Liste bei 312 × 531 px eng, siehe unten) |
| Geräteträger: 5 offline umbuchen, Ankommen prüfen | 6 | 6 („Angekommen um …“ statt „wird gesendet“) |
| Geräteträger: 7 Einmalgerät benutzt melden | 6 | 6 |
| Gerätewart: 1 Sammelstelle → Werkstatt, Liste | 6 | 4 (Kopieren im Test-Browser nicht möglich, s. u.) |
| Gerätewart: 2 Gewartetes zurück ins Lager | 5 | 6 |
| Gerätewart: 4 Sollbestand LF 10 | **4** | **5** |
| Gerätewart: 6 Rückgabe bearbeiten | 5 | 6 |
| Gerätewart: 7 Hinweis erledigen | 5 | 6 |
| Admin: 4 Kamerad entfernen | 5 | 5 |
| Admin: 6 Rückgabe-Aushang erneuern | 5 | 6 |

Bestätigt: Beleg und unterscheidbare Meldungen der Rückgabe (U1), Sprunglink und Folgemeldung beim Sollbestand (U4), Warnsatz zu defekter
Ausrüstung und Kopier-Hinweis in der Wartung (U5/U10), „Angekommen um …“ (U9), „Bitte … herausnehmen“ (U11), Klartext im Protokoll (U17).

Neue bzw. offene Befunde der Wiederholung (nicht Teil dieser Runde, nach `docs/TODO-SPAETER.md`):
- „Transportliste kopieren“: Im Test-Browser scheitert das Kopieren (kein Zugriff auf die Zwischenablage); die Meldung steht dann doppelt (Seite und Fenster).
  Im echten Browser live geprüft (12.11.2026, Build `2923baad9b70`): „✓ Liste kopiert (8 Ausrüstungen)“ erscheint am Knopf, der Erfolgsfall
  funktioniert also; ein Ausweg „Liste anzeigen“ für Browser ohne Zugriff auf die Zwischenablage fehlt weiterhin (T20).
- Tauschen bei 312 × 531 px (125 % am Handy): die feste Fußleiste lässt nur rund einen Ersatz sichtbar; „Tauschen“ ist gesperrt, der Grund steht nur im Satz darüber.
- Uhrzeit in „Angekommen um …“ ist die Erfassungszeit, wird als Ankunftszeit gelesen (der Hinweis stimmt in der Regel auf wenige Sekunden).
- „Aushang abschalten …“ klingt für eine Persona nach „Strom aus“ („Link ungültig machen“ wäre klarer); „Claude-Zugang (KI-Assistent)“ sieht eingeklappt wie eine Überschrift aus; „Konto beenden“ liegt erst nach Scrollen.
- Rückgabeseite: Name, Zustand und „Zurückgeben“ liegen nach dem Suchen unter dem Bildrand; der Name muss für jedes Gerät neu getippt werden; „nicht verliehen“ ist für Boten unklar.
- Mausrad im Bearbeiten-Fenster: das erste Scrollen blieb ohne Wirkung, nach einem Klick ins Fenster ging es (Treiber, Playwright-Test mit Mausrad grün).
