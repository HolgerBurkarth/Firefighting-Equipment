# Frontend-Konzept Feuerwehr-Geräteverwaltung

Stand: 27.10.2026. Quelle: Frontend-Brainstorming (claude.ai-Doc) und Entscheidungen im
Frontend-Chat. Verbindlich für alle Agenten, die am Frontend arbeiten. Das Backend ist in
`CLAUDE.md` und `README.md` beschrieben; die REST-API v1 ist vollständig und live.

Das Mockup liegt in `docs/frontend/mockup/` (siehe dortige `LIESMICH.md`).

## Technische Entscheidungen

| Thema | Entscheidung |
|---|---|
| Framework | Vue 3 mit Vite, als Progressive Web App (PWA) |
| Sprache | TypeScript (strikt) |
| UI | Eigenes Design nach dem Mockup mit zentralen Design-Variablen (CSS Custom Properties); für Dialoge, Auswahllisten, Reiter, Menüs u. Ä. die Bibliothek **Reka UI** (ohne eigenes Aussehen). Keine große Komponenten-Bibliothek (kein Vuetify/Quasar). |
| Node.js | lokal v24 (LTS), npm 11; auf STRATO **kein** Node.js |
| Komponenten-/Logiktests | Vitest |
| Browser-Tests | Playwright mit Chromium, Firefox und **WebKit** (Safari-Technik), auch Handy-Format und Offline-Abläufe |
| Build und Auslieferung | GitHub Actions: bei jedem Push auf `main` alle Tests (PHP und Frontend); nur wenn alle grün sind, Build des Frontends und Ablage von Code plus Build im Branch `deploy`. Der Server zieht `deploy` statt `main`. |
| Deploy auf den Server | weiterhin von Hand per SSH: `bash scripts/deploy.sh` (zieht künftig `deploy`) |
| Live-Tests | nur von Hand in der Test-Stadt `/firefighting/test/`, nie in echten Städten; keine automatischen Live-Tests |
| Sprache der Oberfläche | nur Deutsch |

### Einbindung unter `/firefighting/<stadt>/`
- `/firefighting/<bereich>/api/v1/...` und `/firefighting/<stadt>/manifest.webmanifest` gehen an PHP.
- Alle anderen Adressen unter `/firefighting/<bereich>/` liefern die App-Seite (`index.html`);
  die App entscheidet per Routing (History-Modus), welche Ansicht erscheint.
- Ein Build für alle Städte: Den Basispfad (`/firefighting/<bereich>/`) ermittelt die App zur
  Laufzeit aus der Adresse. `service` ist der Super-Admin-Bereich.
- Seiten für Mail-Links (Namen stehen fest in `src/Http/AppUrls.php`):
  `passwort-zuruecksetzen`, `email-bestaetigen`, `einladung-annehmen`. Das Token steht hinter `#`
  und wird per POST an die API geschickt. Jede dieser Seiten mit Hinweis auf den Spam-Ordner.
- Manifest, Service Worker und App-Symbol gelten je Stadt (Logo der Stadt); die offenen Punkte
  unter "Noch offen" in `CLAUDE.md` (`start_url`, `background_color`, Standard-Symbol,
  `apple-touch-icon`) werden mit dem Grundgerüst geklärt.

## Rollen und Rechte

| Rolle | Arbeitet meist am | Darf |
|---|---|---|
| Super-Admin | PC | Städte anlegen, umbenennen, sperren; Admins einladen; Benutzer einer Stadt nur ansehen; andere Super-Admins sperren. Sieht keine Geräte und kann nicht in eine Stadt wechseln. |
| Admin (je Stadt) | PC, auch Handy | Geräte, Standorte, Typen und Benutzer verwalten, Hinweise bearbeiten, Erscheinungsbild der Stadt einstellen |
| User | Handy | Geräte suchen, umbuchen, Status setzen, Prüffristen ansehen, CSV exportieren |

- Jede Rolle sieht nur, was sie darf: ausblenden statt ausgrauen.
- Zielgruppe ohne IT-Kenntnisse: wenig Verschachtelung, Alltagssprache, große Bedienelemente
  (mindestens 44 px).

## Offline-Modus
- Lokale Kopie der Daten; offline gemachte Buchungen landen in einer Warteschlange und gehen bei
  Verbindung automatisch raus.
- Liegt die letzte Synchronisierung länger als 7 Tage zurück, ist offline Buchen gesperrt
  („Daten zu alt, bitte mit dem Internet verbinden“).
- Konflikte: der Letzte gewinnt, gemessen an der Aktionszeit auf dem Handy; der Server prüft die
  Uhrzeit auf Plausibilität (Backend fertig).
- Buchungen, die länger als 7 Tage in der Warteschlange liegen, werden nicht automatisch gesendet:
  die App fragt je Buchung „trotzdem senden oder verwerfen?“.
- Beide Limits stehen getrennt in der Server-Config; die App liest sie beim Synchronisieren.
- Status jeder Buchung sichtbar: Uhr-Symbol = wartet, Haken = beim Server angekommen. Oben ein
  grauer Streifen, solange das Gerät offline ist.
- Der buchende Kamerad erhält das Ergebnis (übernommen, überschrieben, abgelehnt) direkt in der App.

## Fehlerbehandlung
- Fehler in Alltagssprache, gut sichtbar (z. B. farbiger Hinweis oben: „Keine Verbindung, wird
  erneut versucht“).
- App bleibt bedienbar, verbindet automatisch neu; der Hinweis wird bei Erfolg grün und verschwindet.
- Eingaben gehen nie verloren.
- Fehlercodes höchstens klein für den Support. Bei 401 meldet die App ab.

## Hinweise für Admins (Menüpunkt „Protokoll“)
- Überschriebene, verspätete und nach Rückfrage gesendete Offline-Buchungen erscheinen als offene
  Hinweise, gezählt auf dem Dashboard.
- Jeder Hinweis zeigt Gerät, beide Benutzer, beide Stände und Zeiten; der Admin korrigiert den
  Status direkt von dort und erledigt den Hinweis mit optionaler Notiz (kann wieder geöffnet werden).
- Rückfrage per E-Mail-Link mit vorausgefülltem Betreff, z. B. „Rückfrage zu deiner Buchung von Maske M1“.

## Endgeräte

| Gerät | Systeme | Layout |
|---|---|---|
| Handy | Android, iOS, Huawei | Mobile First; Menü ausklappbar, Listen als Karten, User mit Leiste unten |
| Tablet | Android, iPadOS | Admin-Menü eingeklappt auf Symbole |
| PC | Windows, macOS, Linux | Menü links, Inhalt rechts, Tabellen |

- Aktuelle Versionen von Chrome, Edge, Safari, Firefox (etwa die letzten zwei Jahre), ohne
  Sonderanpassungen. Auf iPhone/iPad nutzen alle Browser die Safari-Technik: dort am meisten testen.
- Huawei ohne Google-Dienste: App läuft; HarmonyOS NEXT auf echtem Gerät testen.
- Die Admin-Konsole funktioniert vollständig auch am Handy (z. B. Codes am Gerät einscannen).
- QR- und Barcodes mit der Handy-Kamera über eine Bibliothek, die beides erkennt (der eingebaute
  Scanner fehlt in Safari). Am PC tippt ein USB-Barcode-Scanner wie eine Tastatur.

## Oberfläche: Grundsätze
- **Statusfarben** fest und überall gleich, immer mit Symbol und Wort: ✓ einsatzbereit (grün),
  ◐ in Wartung (gelb), ✕ defekt (rot).
- **Formulare** prüfen beim Tippen: Fehler rot umrandet mit kurzem Tipp darunter; passt alles,
  grün mit Häkchen.
- **Hover am PC** deutlich: Menüpunkte und Tabellenzeilen kräftig hinterlegt mit Randbalken links,
  Kacheln heben sich an, Buttons und Stift-Symbol färben sich.
- **Anzeigemodus** wählbar: System (Standard), Hell, Dunkel; gespeichert pro Gerät.
- **Erscheinungsbild je Stadt**: Akzentfarbe für Kopfleiste und Buttons, Logo im Kopf, auf der
  Anmeldeseite und als App-Symbol. Die App prüft den Kontrast und warnt bei zu hellen Farben
  (Backend prüft ebenfalls, mindestens 4,5:1). Standard ohne eigene Farbe: Feuerwehrrot `#b71c1c`
  (wie `AccentColor::DEFAULT` und das Standard-App-Symbol), nicht das Dunkelblau aus dem Mockup.

## Admin-Konsole
Super-Admin und Admin: Menü links, Inhalt rechts; links unten Einstellungen, Profil, Abmelden.
Startseite ist ein Dashboard mit großen, antippbaren Kacheln, die zur gefilterten Liste führen.

| | Admin | Super-Admin |
|---|---|---|
| Menü | Dashboard, Geräte, Standorte, Gerätetypen, Benutzer, Protokoll | Dashboard, Städte, Super-Admins |
| Kacheln | Einsatzbereitschaft (z. B. 42 von 45), nicht einsatzbereit, Prüffristen, offene Hinweise; dazu Listen „nicht einsatzbereit“ und „letzte Aktivitäten“ | aktive und gesperrte Städte, offene Admin-Einladungen mit „Erneut senden“ (Link läuft nach 24 h ab), Benutzer gesamt |

### Listen
- Suchfeld oben rechts, filtert schon beim Tippen nach Teiltext; daneben „Neu“.
- Spaltenköpfe sortieren wie im Windows-Explorer (Klick auf-, zweiter Klick absteigend, Pfeil).
- Stift-Symbol zum Bearbeiten in jeder Zeile; Klick auf die Zeile öffnet die Details.
- Am Handy werden Zeilen zu Karten, Sortierung über ein Auswahlfeld oben.
- Geräte werden nie gelöscht, sondern ausgesondert (rot, abseits, Sicherheitsrückfrage mit Namen).
  Typen werden deaktiviert, Standorte gesperrt (nur wenn leer, sonst freundlicher Hinweis wie
  „Hier liegen noch 3 Geräte“), Benutzer anonymisiert.

### Bereiche des Admins
- **Geräte:** Liste mit Import- und Export-Button. Detail: oben Kennung, Typ, Standort, Status
  groß und farbig, darunter Stammdaten, unten die Historie. Aktionen: groß „Umbuchen / Status
  ändern“, kleiner „Bearbeiten“, „Code zuordnen“ (QR oder Barcode einscannen), rot und abseits
  „Aussondern“.
- **Standorte:** Baum statt Liste, Fahrzeug mit Fächern darunter, Symbol für Fahrzeug, Raum oder
  extern, Zahl der Geräte; Klick zeigt die Geräte dort.
- **Gerätetypen:** einfache Liste mit Name und Gerätezahl; umbenennen, deaktivieren.
- **Benutzer:** oben deutlicher Hinweis „2 neue Anmeldungen warten“; Freischalten im kleinen
  Fenster mit Anzeigename und Rolle. Einladungscodes im eigenen Reiter: der Code erscheint nur
  einmal, groß, mit Kopieren-Button und QR-Code.
- **Protokoll:** Hinweise zu Konflikten (siehe oben).
- **Einstellungen:** Anzeigemodus, Erscheinungsbild der Feuerwehr mit Vorschau, Mail-Benachrichtigung,
  Passwort und angemeldete Geräte.

### Bereiche des Super-Admins
- **Städte:** Liste mit Name, Adresse, Status, Zahl der Admins und Benutzer. „Neue Stadt“: Name,
  Kürzel (aus dem Namen vorgeschlagen, Umlaute umgewandelt, Prüfung „frei“ beim Tippen), E-Mail des
  ersten Admins.
- **Super-Admins:** Liste mit Sperren und Entsperren; das geschützte Konto ohne Button.

## User am Handy
- **Start:** großes Suchfeld, Button „Code scannen“, darunter „Zuletzt benutzt“. Leiste unten:
  Suchen, Prüffristen, Profil.
- **Umbuchen:** oben Gerät mit aktuellem Standort und Status. Standort: aktueller und zuletzt
  benutzte zur Auswahl, dazu „Anderen Standort suchen …“. Status über drei große Tasten,
  vorausgewählt ist der jetzige Zustand. Kommentar freiwillig; bei „defekt“ rot hervorgehoben mit
  Bitte um Beschreibung. Unten groß „Speichern“.
- **Standortauswahl:** bildschirmfüllend, Suchfeld oben. Ohne Eingabe alle Standorte als Karten;
  ein Fahrzeug klappt mit Antippen der ganzen Zeile auf. Eine aufgeklappte Gruppe hebt sich ab
  (Abstand, Rahmen in der Stadtfarbe, Schatten, getönte Kopfzeile); Fächer darunter eingerückt,
  als erster Eintrag **„Ganzes Fahrzeug, ohne Fach“** (entschieden: ein Gerät darf auf ein ganzes
  Fahrzeug gebucht werden, auch wenn es Fächer hat; das Backend erlaubt das bereits). Mit
  Suchbegriff eine flache Trefferliste, Fächer mit ihrem Fahrzeug darunter. Gesperrte Standorte
  fehlen. Ein Tipp wählt und führt zurück zum Umbuchen.

## Noch offen (Praxis, vor dem Livegang)
- Standortauswahl mit echten Daten prüfen.
- Was steht auf den Hersteller-Barcodes?
- Handy-Ansichten auch im Dunkelmodus entwerfen (Farben für Dunkel: siehe Mockup „Einstellungen“).

## Später (nicht Version 1)
- Rückfrage und Antwort in der App statt per E-Mail.
- Web Push (iPhone ab iOS 16.4, nur bei installierter App; Server per Composer-Bibliothek,
  durch Ereignisse ausgelöst, keine Cronjobs).
