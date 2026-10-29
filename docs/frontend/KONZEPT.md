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
- Manifest, Service Worker und App-Symbol gelten je Stadt (Logo der Stadt). Mit dem Grundgerüst
  geklärt (Einzelheiten in `CLAUDE.md`, "Erscheinungsbild (API)"): `start_url` = Startseite der
  Stadt, `background_color` = heller Hintergrund `#f3f4f6`, Standard-Symbol vom Backend,
  `apple-touch-icon` zuerst Standard-Symbol, nach dem Laden der Stadt deren Logo; unter `service`
  kein Manifest.

## Anmeldung und Registrierung

API und Frontend sind fertig, alle Seiten unten sind umgesetzt (API: `CLAUDE.md`, "Benutzer und
Anmeldung", "Passwort vergessen", "Einladung, Registrierung und E-Mail-Bestätigung", "Städte
verwalten und Admins einladen"; Frontend: `CLAUDE.md`, "Frontend").

### Seiten
| Adresse | Bereich | Zweck |
|---|---|---|
| `anmelden` | Stadt und `service` | Anmeldung; Logo, Name und Farbe der Stadt (unter `service`: „Super-Admin“), Link „Passwort vergessen?“, **kein** Link zum Registrieren |
| `registrieren` | nur Stadt | Registrierung mit Einladungscode, Name, E-Mail, Passwort |
| `email-bestaetigen` | nur Stadt | Mail-Link nach der Registrierung |
| `passwort-vergessen` | Stadt und `service` | Link zum Zurücksetzen anfordern |
| `passwort-zuruecksetzen` | Stadt und `service` | Mail-Link, neues Passwort setzen (Link 1 Stunde gültig; abgelaufen → „Neuen Link anfordern“) |
| `einladung-annehmen` | nur Stadt | Mail-Link für neue Admins: Name und Passwort |
| `suchen` | nur Stadt | Startseite des Users am Handy (vorerst Platzhalter) |
| `prueffristen` | nur Stadt | Prüffristen am Handy (vorerst Platzhalter) |
| `profil` | nur Stadt | Profil am Handy (vorerst Platzhalter mit Abmelden) |
| `geraete/<id>` | nur Stadt | Umbuchen/Status eines Geräts (vorerst Platzhalter) |
| `dashboard` | Stadt und `service` | Startseite von Admin bzw. Super-Admin (vorerst Platzhalter) |

`/` ist nur eine Weiche: ohne Sitzung zur Anmeldung, sonst zur Startseite der Rolle.

### Entscheidungen
1. **Einladung per QR-Code:** Der QR-Code eines Einladungscodes enthält einen fertigen Link
   `/firefighting/<stadt>/registrieren#code=XXXX-XXXX-XXXX`. Die Seite übernimmt den Code aus dem
   Fragment (er erreicht nie die Server-Adresse), entfernt ihn danach aus der Adresszeile und
   funktioniert auch ohne Code (Feld leer). Die Anmeldeseite hat keinen Registrieren-Link. Die
   Erzeugung des QR-Codes kommt später mit der Benutzerverwaltung des Admins.
2. **Nach der Anmeldung:** User → `suchen`, Admin → immer `dashboard` (PC und Handy), Super-Admin
   → `dashboard` unter `/service/`. Läuft die Sitzung ab (401), geht es zur Anmeldung und danach
   zurück zur vorherigen Seite (`anmelden?weiter=…`; nur Pfade innerhalb der App, die die Rolle
   öffnen darf, sonst die Startseite der Rolle). Ruft man angemeldet die Anmeldeseite auf, erscheint
   einfach das Formular (eine neue Anmeldung ersetzt die alte Sitzung).
3. **Neues Passwort:** EIN Feld mit Auge-Symbol (anzeigen/verbergen), darunter Live-Prüfung:
   10 bis 128 Zeichen, keine Teile von Name, E-Mail oder Stadt (Verfahren wie `PasswordPolicy` im
   Backend). Absenden erst, wenn die Live-Prüfung bestanden ist; daher darf das Frontend
   nachweislich nie strenger sein als der Server: Eine gemeinsame Datei mit Testfällen
   (`tests/Fixtures/password-policy-cases.json`, Grenzfälle wie Umlaute, ß/ss, NFC, Stoppwörter,
   genau 4 Zeichen, Länge 10/128) läuft in PHPUnit gegen `PasswordPolicy` und in Vitest gegen die
   Prüfung des Frontends. Die Sperrliste prüft nur der Server (422 `weak_password`); seine Meldung
   erscheint verständlich am Feld, die Liste wird nicht ins Frontend kopiert. Auch die
   Anmeldeseite hat das Auge-Symbol.
4. **Tokens aus Mail-Links** (`#token=…`) wie der Einladungscode: aus dem Fragment lesen, per POST
   senden, danach aus der Adresszeile entfernen.
5. **Registrieren:** Felder Einladungscode, Name, E-Mail-Adresse, Passwort (Live-Prüfung mit Name,
   E-Mail und Stadt). Der Code darf in jeder Schreibweise eingegeben werden (klein, mit Leerzeichen,
   ohne Bindestriche) und erscheint danach als `XXXX-XXXX-XXXX`. Die App prüft vor dem Absenden nur
   den Aufbau (12 Zeichen aus dem Alphabet ohne 0/O/1/I/L), genau wie der Server (gemeinsame
   Testfälle `tests/Fixtures/invitation-code-cases.json`); ob der Code gilt, sagt nur der Server
   (Meldung am Feld). Danach: Meldung des Servers, „Link 24 Stunden gültig“, Hinweis auf Spam-Ordner
   und Kontakte, Link „Bestätigungsmail erneut senden“.
6. **E-Mail bestätigen per Knopf:** Die Seite sendet das Token erst nach „E-Mail-Adresse
   bestätigen“, nicht schon beim Öffnen; Virenscanner mancher Mailprogramme öffnen Links vorab und
   würden die Adresse sonst ohne den Menschen bestätigen. Link abgelaufen oder benutzt, oder Seite
   ohne Link geöffnet: Formular „Neuen Bestätigungslink anfordern“ (E-Mail-Adresse). Danach geht es
   zur Anmeldung; bis zur Freischaltung meldet sie „wartet auf Freischaltung“.
7. **Ungespeicherte Eingaben:** Ein Code oder Token aus einem Link zählt wie eine Eingabe, solange
   er nicht verwendet ist (nach einem Neuladen stünde er nicht mehr in der Adresse). Alle Formulare
   melden ihre Eingaben (siehe "Updates der App").
8. **Einladung annehmen** (neue Admins, Link aus der Mail des Super-Admins, 24 Stunden gültig):
   Felder Name und Passwort, Knopf „Konto einrichten“. Die Live-Prüfung kennt Name und Stadt, die
   E-Mail-Adresse nur der Server (er prüft sie zusätzlich, Meldung am Feld). Danach entsteht keine
   Sitzung (wie nach „Passwort vergessen“): Die App führt zur Anmeldung mit dem Hinweis „Ihr Konto
   ist eingerichtet …“. Einladung abgelaufen oder benutzt bzw. Seite ohne Link: Hinweis, beim
   Super-Admin eine neue Einladung anzufordern (nur er kann sie senden; ein Formular wie bei der
   E-Mail-Bestätigung gibt es daher nicht).

### Weitere Regeln
- Nur eine echte Antwort des Servers entscheidet, ob jemand angemeldet ist (401 = nicht
  angemeldet). Bei einer Störung der Sitzungsprüfung (keine Verbindung, unlesbare Antwort, 5xx)
  führt die App nie zur Anmeldung: Sie zeigt oben „Keine Verbindung zum Server, neuer Versuch
  läuft automatisch“, versucht es selbst erneut (nach 2, 4, 8, dann alle 15 Sekunden und sofort,
  wenn der Browser wieder online ist) und macht nach Erfolg normal weiter.
- Jeder Fehlercode der API erscheint in Alltagssprache, der Code nur klein für den Support:
  falsches Passwort, gesperrtes Konto, E-Mail nicht bestätigt (mit „Mail erneut senden“),
  „wartet auf Freischaltung“, zu viele Versuche (mit Wartezeit aus `Retry-After`), Link abgelaufen
  oder benutzt (mit Weg zu einem neuen Link), ungültiger Einladungscode, Stadt gesperrt oder
  unbekannt, keine Verbindung. Eingaben bleiben bei Fehlern erhalten.
- Nach Registrierung, „Passwort vergessen“ und neuem Bestätigungslink: Hinweis auf den
  Spam-Ordner und darauf, `feuerwehr-app@prodad.de` zu den Kontakten hinzuzufügen.
- `autocomplete` passend für Passwort-Manager und Handys: `username` (E-Mail des Kontos),
  `current-password` beim Anmelden, `new-password` beim Setzen, `name` beim Namen.
- Bedienelemente mindestens 44 px, alles per Tastatur und Bildschirmleser bedienbar (Reka UI wo
  sinnvoll, z. B. `Label`).

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
- Die App merkt sich die letzte vom Server bestätigte Sitzung (Konto, Rolle, Stadt) lokal und
  arbeitet offline damit weiter. Nur eine echte 401 vom Server beendet sie; keine Verbindung,
  unlesbare Antworten oder Serverfehler (5xx) nie.
- Abmelden löscht dann auch alle lokal gespeicherten Daten des Kontos (Kopie der Daten,
  Warteschlange). Liegen noch Buchungen in der Warteschlange, warnt die App vorher deutlich
  (z. B. „3 Buchungen wurden noch nicht gesendet und gehen beim Abmelden verloren“).

## Fehlerbehandlung
- Fehler in Alltagssprache, gut sichtbar (z. B. farbiger Hinweis oben: „Keine Verbindung, wird
  erneut versucht“).
- App bleibt bedienbar, verbindet automatisch neu; der Hinweis wird bei Erfolg grün und verschwindet.
- Eingaben gehen nie verloren.
- Fehlercodes höchstens klein für den Support. Bei 401 meldet die App ab.

## Updates der App
Entscheidung: Updates kommen automatisch, aber nie mitten in einer Eingabe.
- Eine neue Version (neuer Build nach einem Deploy) übernimmt nicht von selbst: Ihr Service Worker
  wartet, bis die App ihn aktiviert. Bis dahin läuft die geladene Version vollständig weiter (auch
  ihre nachgeladenen Teile kommen aus dem Cache), danach lädt die App die Seite neu.
- Geprüft wird beim Start, wenn die App wieder in den Vordergrund kommt (z. B. Handy entsperrt,
  Tab gewechselt) und etwa stündlich. Offline wird nicht geprüft; eine gescheiterte Prüfung zeigt
  keinen Fehler.
- Meldet der Server eine neue Version, der Browser aber noch keinen neuen Service Worker, fragt die
  App nach kurzen, wachsenden Pausen erneut (0,5 s, 1 s, 2 s, 4 s), danach erst wieder beim nächsten
  Anlass; keine Endlosschleife. Grund (Prüflauf unter Last): Der Browser legt eine Prüfung mit einer
  noch laufenden zusammen; begann diese vor dem Deploy, findet keine von beiden die neue Version.
- Ist eine neue Version da und kein Formular angefangen, aktualisiert die App beim nächsten
  Seitenwechsel unbemerkt: Statt in der alten Version weiterzublättern, lädt sie die Zielseite neu.
  Nicht, wenn nur die Adresszeile bereinigt wird (z. B. `#token=…` entfernt).
- Beim Start: Kommt die Seite aus dem Service Worker (also womöglich veraltet), fragt die App vor der
  ersten Seite die Kennung des Servers ab (höchstens 2 Sekunden, offline nicht). Ist eine neuere
  Version da, lädt sie sofort einmal neu, mit der vollständigen Adresse samt `#token=…` bzw.
  `#code=…`; das Fragment entfernt erst die neue Version. Grund (Live-Test): Ein Mail-Link öffnete
  sich in einem Browser mit einer App von vor Schritt 4, die die Seite noch nicht kannte, und zeigte
  „Seite nicht gefunden“. Eine frisch vom Server geladene Seite prüft beim Start nicht (sie ist
  aktuell).
- „Seite nicht gefunden“ prüft vor der Anzeige immer zuerst auf eine neue Version (vielleicht kennt
  erst sie die Seite) und lädt sie dann sofort, sofern nichts ungespeichert ist.
- Beim Start mit veralteter Version und vor „Seite nicht gefunden“ fragt die App bis rund 30 Sekunden
  nach der neuen Version (Pausen weiter 8 s, 15 s) und zeigt solange „Seite wird geladen …“, nie kurz
  „nicht gefunden“. Gelingt es nicht, erscheint „Die neue Version konnte nicht geladen werden.“ mit
  dem Knopf „Neu laden“: Die App lädt die Adresse samt `#token=…` bzw. `#code=…` direkt vom Server.
- Neu geladen wird nur, wenn danach sicher die neue Version kommt (der neue Service Worker hat die
  Seite übernommen). Hängt die Übernahme (Chrome hält sie an, wenn der alte Service Worker genau
  dann eine Anfrage bekommt), meldet die App den Service Worker ab und lädt die Seite vom Server.
  Während des Wechsels steht oben „Neue Version wird geladen …“. Beim Zurück-Knopf landet die neue
  Version auf der richtigen Seite.
- Schleifensperre in allen Fällen gleich (10 Minuten je Tab), danach nur der Hinweis mit Knopf.
- Ist ein Formular angefangen (z. B. Umbuchung, Registrierung), erscheint oben der Hinweis „Neue
  Version verfügbar – nach dem Speichern wird aktualisiert“ mit dem Knopf „Jetzt aktualisieren“.
  Aktualisiert wird beim nächsten Seitenwechsel (nach dem Speichern führt die App in der Regel auf
  eine andere Seite) oder per Knopf. Die Eingaben bleiben bis dahin unverändert stehen. Der Knopf
  fragt vorher nach: „Ihre Eingaben auf dieser Seite gehen verloren. Trotzdem aktualisieren?“ mit
  „Abbrechen“ (Standard) und „Aktualisieren“.
- Jedes Formular meldet „ungespeicherte Eingaben“ an einer zentralen Stelle: etwas eingegeben und
  noch nicht gespeichert. Nach dem Speichern oder Leeren gilt es nicht mehr als angefangen.
- Keine Schleife: Nach einem automatischen Neuladen lädt die App 10 Minuten lang nicht erneut von
  selbst neu (je Tab). Meldet die neue Version sofort wieder eine neue, erscheint stattdessen der
  Hinweis „Neue Version verfügbar“ mit dem Knopf.
- Hat ein anderer Tab die neue Version schon aktiviert, aktualisiert sich dieser Tab ebenso beim
  nächsten Seitenwechsel. Fehlen nach einem Deploy Teile der alten Version (Browser ohne Service
  Worker), lädt die App die Zielseite einmal neu.
- Hinweise für die Anmeldeseite (z. B. „Passwort geändert“) überstehen das Neuladen.
- Erkennung: Ob eine neue Version da ist, folgt nicht nur aus dem Service Worker, sondern bei jeder
  Prüfung auch aus dem Vergleich der Kennung der laufenden Version mit der, die der Server gerade
  ausliefert (Datei `build.json` im Build, ohne Cache). Verschieden heißt Update, egal in welchem
  Zustand der Service Worker ist. Grund (Fehler im Live-Test): Eine per Strg+F5 geladene Seite steht
  nicht unter dem Service Worker. Wird nach einem Deploy der neue Service Worker aktiv (z. B. weil
  der Browser einen anderen Tab schlafen gelegt hatte) und übernimmt die Seite, sah das wie eine
  Erstinstallation aus; die Seite blieb dauerhaft bei der alten Version.
- Abgedeckte Fälle (Browser-Tests mit zwei echten Builds): ohne Eingabe automatisch beim
  Seitenwechsel; mit Eingabe Hinweis und Rückfrage; erster Besuch, Deploy noch vor der Installation
  des Service Workers; Seite per Strg+F5 geladen, neue Version übernimmt sie; zwei Tabs, einer
  aktualisiert und der andere folgt beim nächsten Seitenwechsel; zwei Tabs, einer geschlossen;
  Mail-Link mit alter Version im Service Worker (neue Version lädt sofort, Token bleibt erhalten und
  wird verarbeitet); „Seite nicht gefunden“ nach einem Deploy; Zurück-Knopf nach einem Deploy. Die
  sporadischen Fälle (zusammengelegte Prüfung, hängende Übernahme) prüfen Unit-Tests und ein
  Prüflauf unter Last (3 × 480 Läufe).
- Veraltete App gegen geänderte API: Die App nennt bei jedem Aufruf ihre Vertragsnummer
  (`X-Client-Api`). Später kann der Server eine zu alte App ablehnen („Diese Version ist veraltet –
  bitte aktualisieren“); Plan und Regeln in CLAUDE.md, "Versionierung der API für das Frontend".

## Hinweise für Admins (Menüpunkt „Protokoll“)
- Überschriebene, verspätete und nach Rückfrage gesendete Offline-Buchungen erscheinen als offene
  Hinweise, gezählt auf dem Dashboard.
- Jeder Hinweis zeigt Gerät, beide Benutzer, beide Stände und Zeiten; der Admin korrigiert den
  Status direkt von dort und erledigt den Hinweis mit optionaler Notiz (kann wieder geöffnet werden).
- Rückfrage per E-Mail-Link mit vorausgefülltem Betreff, z. B. „Rückfrage zu Ihrer Buchung von Maske M1“.

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
- **Anrede:** in der ganzen App und in allen Mails „Sie“, nie „du“.
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
- **Darstellung:** Suchen, Prüffristen und Profil mit Kopfleiste in der Stadtfarbe (Name der Stadt,
  „Hallo <Anzeigename>“) und der Leiste unten; Umbuchen, Standortwahl und Details bildschirmfüllend
  mit weißer Kopfzeile und Zurück-Knopf, ohne Leiste. Zurück führt zur vorigen Seite der App, nach
  einem direkt geöffneten Link zu „Suchen“. Am PC eine Spalte in der Mitte (höchstens 600 px).

### User am Handy – Entscheidungen
13. **Offline kommt später, die Grundlage jetzt:** Der Offline-Modus wird ein eigener Abschnitt
    danach. Jetzt wird online gebaut, aber offline-fähig: Alle Daten laufen über eine zentrale
    Datenschicht, keine Seite ruft die API direkt auf. Jede Buchung (Umbuchen/Status) trägt ab
    sofort eine eindeutige `client_ref`.
14. **Suche:** Die App lädt alle Geräte der Stadt einmal (in Blöcken zu 200) und sucht lokal bei
    jedem Tastendruck. Aufgefrischt wird beim Öffnen, beim Zurückwechseln in die App und nach einer
    eigenen Buchung. Ab etwa 2.000 Geräten sucht die App automatisch auf dem Server (`q`). Die
    geladene Liste wird später die lokale Kopie für den Offline-Modus.
15. **Gerät antippen** öffnet direkt die Umbuchen-Ansicht (Mockup „UserUmbuchen“), unten ein Link
    „Details und Verlauf“ (Stammdaten, Historie). Admins nutzen dieselbe Umbuchen-Ansicht.
16. **„Zuletzt benutzt“:** geöffnete oder umgebuchte Geräte, nur auf diesem Handy, höchstens 8,
    neueste oben; die Angaben kommen immer aus der aktuellen Geräteliste. Abmelden löscht die Liste.
17. **„Code scannen“:** zuerst Vergleich mit dem Scan-Code, dann mit der Kennung (ohne Groß- und
    Kleinschreibung, wie im Backend). Kein Treffer: Hinweis „Kein Gerät mit diesem Code gefunden“,
    der gescannte Text steht danach im Suchfeld. Die Kamera liest QR- und Barcodes über eine
    Bibliothek (der eingebaute Scanner fehlt in Safari); die CSP wird nur so weit wie nötig
    erweitert.
18. **Kommentar:** Bei „defekt“ Pflicht, mindestens 5 Zeichen (prüft nur die Oberfläche). Bei
    „einsatzbereit“ und „in Wartung“ freiwillig.
19. **Admins** dürfen alle Handy-Seiten öffnen (`suchen`, `prueffristen`, `profil`, `geraete/…`);
    ihre Startseite bleibt `dashboard`. In der Leiste unten steht für sie zusätzlich „Dashboard“
    (vorne), damit sie zurück in die Admin-Konsole kommen.
20. **Suchfelder:** lokal über Kennung, Scan-Code, Seriennummer, Hersteller, Modell, Gerätetyp und
    Standortpfad; mehrere Wörter mit UND verknüpft; höchstens 50 Treffer, darüber der Hinweis
    „weiter eingrenzen“. Dafür liefert `GET devices` zusätzlich `serial_number`, `manufacturer`
    und `model`.
21. **Zuletzt benutzte Standorte** (Umbuchen): die letzten 3 eigenen Ziele, lokal je Konto, beim
    Abmelden gelöscht.
22. **Nach dem Speichern** geht es zurück zu „Suchen“ mit grüner Bestätigung (z. B.
    „M1 → HLF 20 / G2 · einsatzbereit“).
23. **Auffrischen** beim Zurückwechseln in die App höchstens alle 30 Sekunden.
24. **Enter im Suchfeld** (z. B. USB-Barcode-Scanner am PC) wirkt wie ein Scan: Ein eindeutiger
    Treffer öffnet das Gerät.
25. **Prüffristen:** Antippen eines Geräts öffnet die Umbuchen-Ansicht.
26. **Komprimierung:** STRATO komprimiert die Ausgabe nicht (live geprüft). Der Build erzeugt daher
    komprimierte Fassungen aller Dateien (`.gz`, ggf. `.br`); PHP liefert sie aus, wenn
    `Accept-Encoding` es erlaubt (`Content-Encoding`, `Vary: Accept-Encoding`, ETag je Fassung).
27. **Kamera-Scanner:** `zxing-wasm` (nur Lesemodul, MIT); immer dieselbe Bibliothek, auch wo der
    Browser einen eigenen `BarcodeDetector` hat (gleiche Ergebnisse bei GS1-Trennzeichen). Die
    WASM-Datei liegt im Build (kein CDN); CSP nur `script-src 'wasm-unsafe-eval'`.
28. **Später** (sehr große Bestände bzw. Offline-Abschnitt): genauer Filter für die Kennung und
    `ids=` in `GET devices`, Laden „geändert seit“ statt über `offset`.

## Noch offen (Praxis, vor dem Livegang)
- Standortauswahl mit echten Daten prüfen.
- Was steht auf den Hersteller-Barcodes?
- Handy-Ansichten auch im Dunkelmodus entwerfen (Farben für Dunkel: siehe Mockup „Einstellungen“).

## Später (nicht Version 1)
- Rückfrage und Antwort in der App statt per E-Mail.
- Web Push (iPhone ab iOS 16.4, nur bei installierter App; Server per Composer-Bibliothek,
  durch Ereignisse ausgelöst, keine Cronjobs).
