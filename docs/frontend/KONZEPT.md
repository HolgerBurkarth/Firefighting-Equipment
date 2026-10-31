# Frontend-Konzept Feuerwehr-Geräteverwaltung

Stand: 30.10.2026. Quelle: Frontend-Brainstorming (claude.ai-Doc) und Entscheidungen im
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
| `suchen` | nur Stadt | Startseite des Users am Handy: Suche, „Code scannen“ (`?scannen=1` öffnet den Scanner), „Zuletzt benutzt“ |
| `prueffristen` | nur Stadt | Prüffristen am Handy: überfällig und in den nächsten 30 Tagen fällig |
| `profil` | nur Stadt | Profil am Handy: Konto mit Abmelden, Anzeigemodus, Passwort ändern, angemeldete Geräte |
| `geraete/<id>` | nur Stadt | Umbuchen/Status eines Geräts; `?standortwahl=1` öffnet die Standortwahl, `?details=1` „Details und Verlauf“ |
| `dashboard` | Stadt und `service` | Startseite der Admin-Konsole bzw. des Super-Admins |
| `staedte` | nur `service` | Städte des Super-Admins (Entscheidung 42) |
| `staedte/<id>` | nur `service` | Stadtdetail des Super-Admins (Entscheidung 44) |
| `einstellungen` | Stadt und `service` | Einstellungen des Admins bzw. des Super-Admins (Entscheidung 46) |

`/` ist nur eine Weiche: ohne Sitzung zur Anmeldung, sonst zur Startseite der Rolle.

### Entscheidungen
1. **Einladung per QR-Code:** Der QR-Code eines Einladungscodes enthält einen fertigen Link
   `/firefighting/<stadt>/registrieren#code=XXXX-XXXX-XXXX`. Die Seite übernimmt den Code aus dem
   Fragment (er erreicht nie die Server-Adresse), entfernt ihn danach aus der Adresszeile und
   funktioniert auch ohne Code (Feld leer). Die Anmeldeseite hat keinen Registrieren-Link. Den
   QR-Code erzeugt die Benutzerverwaltung des Admins (Reiter „Einladungscodes“, Entscheidung 30).
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
  Die rund 30 Sekunden gelten für Suche UND Installation des neuen Service Workers zusammen; ist er
  installiert, aktiviert die App ihn sofort. Grund (Live-Test 31.10.2026): Ein neuer Tab direkt auf
  `einstellungen` (die alte Version kannte die Seite nicht) zeigte nach rund 6 s „konnte nicht
  geladen werden“, obwohl der neue Service Worker kurz danach bereitstand. Die Installation (alle
  Dateien der App vorab laden) dauerte live länger als die 5 Sekunden, die ein gewöhnlicher
  Seitenwechsel abwartet; diese Frist galt auch beim Start. Ein Seitenwechsel wartet weiter
  höchstens 5 Sekunden und bleibt sonst bei der laufenden Version. Nachgeprüft live (31.10.2026,
  Build `b3438d44f157`): ein neuer Tab direkt auf `dashboard` mit älterer Version im Service Worker
  wechselte von selbst auf die neue Version, ohne „konnte nicht geladen werden“, die Seite danach
  vom neuen Service Worker gesteuert.
  Ebenso wartet der Start auf die Registrierung des Service Workers, wenn sie länger als 2 Sekunden
  dauert (bis zu derselben Frist). Grund (Live-Test 31.10.2026, Nacht): Ein offener Tab hatte nach
  dem Deploy die Installation begonnen; beim Neuladen endete `register()` erst danach (fast 7 s),
  und der Start gab ohne Registrierung sofort mit „konnte nicht geladen werden“ auf.
- Neu geladen wird nur, wenn danach sicher die neue Version kommt (der neue Service Worker hat die
  Seite übernommen). Hängt die Übernahme (Chrome hält sie an, wenn der alte Service Worker genau
  dann eine Anfrage bekommt), meldet die App als letzten Ausweg den Service Worker ab und lädt die
  Seite vom Server. Meldet das Abmelden „nicht gefunden“, kennt der Browser die Registrierung aber
  nicht mehr (sie wurde schon früher abgemeldet, ein anderer Tab hält sie nur noch), lädt die App
  ebenfalls neu: Neue Seiten kommen dann ohnehin vom Server. Während des Wechsels steht oben „Neue
  Version wird geladen …“. Beim Zurück-Knopf landet die neue Version auf der richtigen Seite.
- Eine Seite, die nicht unter dem Service Worker steht (vom Server: erster Besuch, Strg+F5, nach
  „Neu laden“), aktiviert einen wartenden neuen Service Worker selbst, wenn sie die aktuelle Version
  ist (Kennung wie `build.json`); sie lädt dabei nicht neu. Andere offene Tabs der alten Version
  übernimmt er; sie laden wie immer erst beim nächsten Seitenwechsel neu, mit angefangener Eingabe
  erscheint dort der Hinweis mit Knopf, die Eingabe bleibt. Grund (Live-Test 31.10.2026, Chrome):
  Ein zweiter Tab (Gerätedetail) hielt den alten Service Worker. „Neu laden“ meldete ihn ab, die
  Seite kam vom Server, aber Chrome holte beim erneuten Registrieren die abgemeldete Registrierung
  samt altem Service Worker zurück; die neue Version wartete weiter, und das nächste gewöhnliche
  Neuladen lieferte wieder die alte. Firefox und WebKit verhalten sich nicht so.
- Schleifensperre (10 Minuten je Tab) nach jedem automatischen Neuladen, danach nur der Hinweis mit
  Knopf. Der Knopf „Neu laden“ setzt sie nicht (ein Klick ist keine Schleife); sonst bliebe eine
  danach erneut alte Seite beim Hinweis, statt sich selbst zu aktualisieren (Live-Test 31.10.2026:
  erst „Jetzt aktualisieren“ half).
- Zeitleiste zur Diagnose: Was die App beim Start und beim Aktualisieren mit dem Service Worker
  erlebt (Registrierung, Kennung des Servers, Prüfungen, Installation, Aktivierung, Übernahme,
  Abmelden, Neuladen), steht je Tab in sessionStorage, höchstens 50 Einträge, und übersteht so ein
  Neuladen. In der Konsole der Entwicklerwerkzeuge (Reiter „Konsole“, auf einer Seite der Stadt):
  `JSON.parse(sessionStorage['ff-update-trace'])`. Je Eintrag Zeit (`at`, UTC), Ereignis
  (`event`, z. B. `start`, `build`, `update`, `skip-waiting`, `controllerchange`, `unregister`,
  `reload`, `reload-needed`, `offline` beim Start ohne Verbindung) und Einzelheit (`detail`, z. B.
  `build=b6a25b03573c` oder
  `controlled, waiting`). Nur feste Ereignisse und Build-Kennungen, keine Adressen und keine
  persönlichen Daten.
- Ist ein Formular angefangen (z. B. Umbuchung, Registrierung), erscheint oben der Hinweis „Neue
  Version verfügbar – nach dem Speichern wird aktualisiert“ mit dem Knopf „Jetzt aktualisieren“.
  Aktualisiert wird beim nächsten Seitenwechsel (nach dem Speichern führt die App in der Regel auf
  eine andere Seite) oder per Knopf. Die Eingaben bleiben bis dahin unverändert stehen. Der Knopf
  fragt vorher nach: „Ihre Eingaben auf dieser Seite gehen verloren. Trotzdem aktualisieren?“ mit
  „Abbrechen“ (Standard) und „Aktualisieren“. Das gilt auch, wenn gerade ein Fenster mit Eingaben
  offen ist (z. B. „Bearbeiten“): Der Hinweis bleibt darüber bedienbar, ein Klick darauf schließt
  das Fenster nicht.
- Fenster mit Eingaben (Bearbeiten, Prüfdatum, Code zuordnen, Aussondern, Wieder aufnehmen,
  Standort, Gerätetyp, Benutzer, Einladungscode, Sammelaktionen, Hinweis erledigen) verlieren nie
  unbemerkt etwas: Mit Eingaben schließt ein Klick neben das Fenster es nicht, Escape fragt „Eingaben
  verwerfen?“ mit „Weiter bearbeiten“ (Standard) und „Verwerfen“; „Abbrechen“ schließt ohne
  Rückfrage. Ohne Eingaben schließen Klick daneben und Escape sofort. Grund (Live-Test 31.10.2026):
  Ein Klick auf „Jetzt aktualisieren“ bei offenem Fenster schloss es und verwarf die Eingabe.
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
  wird verarbeitet); „Seite nicht gefunden“ nach einem Deploy; Zurück-Knopf nach einem Deploy;
  „Neu laden“ mit einem zweiten alten Tab (Gerätedetail mit offenem „Bearbeiten“): danach steuert
  die neue Version die Seite, auch nach erneutem Neuladen, der andere Tab behält seine Eingabe und
  wechselt beim nächsten Seitenwechsel auf die neue Version, obwohl die Dateien der alten fehlen. Die
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
Umgesetzt und abgeschlossen am 31.10.2026 (Schritte 1–13, Entscheidung 29). Live geprüft in der
Test-Stadt: jeder Schritt nach seiner Umsetzung (Ergebnisse der Schritte 9–12 unter "Bereiche des
Admins" und in Entscheidung 31), dazu am 31.10.2026 das Update der App nach dem letzten Deploy
(siehe "Updates der App"). Der abschließende Durchlauf nach `LIVETEST-ADMIN-KONSOLE.md` lief am
31.10.2026 im Browser (Claude per Chrome): ☑ B2, B4, C1–C4, D1–D3, E1–E3, E5–E7, G1, G3, G4, H1–H4
(CSV-Inhalt geprüft, nicht das Öffnen in Excel), I3, K2, K4 (volle Fensterbreite). Offen für Holger
(iPhone, schmale Breiten, Handscanner, Mails, Anmelden/Abmelden): A1 iPhone, A2, B1, B3, D4, E4,
F1–F4, G2, I1, I2, K1, K3, K5. Zwei Funde behoben (Einzahl beim Sperren eines Standorts, eigene
Meldung bei vergebener Kennung bzw. vergebenem Scan-Code beim Wiederaufnehmen). Wünsche stehen in
`docs/TODO-SPAETER.md` (bisher T9–T14).

Super-Admin und Admin: Menü links, Inhalt rechts; links unten Einstellungen, Profil, Abmelden.
Startseite ist ein Dashboard mit großen, antippbaren Kacheln, die zur gefilterten Liste führen.
Das Menü des Admins enthält „Suchen“ (die Handy-Seite `suchen`, Entscheidung 19). Umgesetzt
(Schritt 1 der Admin-Konsole): Menü in drei Breiten (Entscheidung 34) statt der früheren
Übergangsleiste auf dem Dashboard; Dashboard mit den Kacheln Einsatzbereitschaft, nicht
einsatzbereit, Prüffristen und offene Hinweise (Hinweise im Protokoll plus wartende
Freischaltungen), darunter „Nicht einsatzbereit“ (defekte zuerst, höchstens 10) und „Letzte
Aktivitäten“ (die letzten 8 Einträge des Verlaufs der Stadt mit Zeit, Gerät, Wer und Von/Nach). Die
Kachel „Prüffristen“ führt zu `prueffristen`; „Offene Hinweise“ führt zu Protokoll (seit Schritt 11)
und Benutzern (seit Schritt 8). Schritt 2: `standorte` als Baum (Fahrzeug, Raum oder Extern mit seinen Fächern,
Symbol, Gerätezahl, Filter, gesperrte nur auf Wunsch) mit „Neuer Standort“ (Name und Art), „Fach
anlegen“, Umbenennen, Sperren (nur leer: der Knopf nennt vorher, was noch dort liegt, dann
Rückfrage) und Entsperren in kleinen Fenstern; `geraetetypen` als Liste mit Name und Gerätezahl
(Spaltenköpfe sortieren, am Handy Karten mit Sortier-Knöpfen), Anlegen, Umbenennen, Deaktivieren
mit Rückfrage und Aktivieren. Gleiche Namen fallen schon beim Tippen auf. „Klick zeigt die Geräte
dort“: Der Name führt zur Geräteliste mit diesem Standort bzw. Typ. Schritt 3: `geraete` mit Suche,
Filtern nach Gerätetyp, Standort (samt Fächern) und Status, „Ausgesonderte anzeigen“, Sortierung
über die Spaltenköpfe (am Handy Karten mit Auswahl und Richtung), „Weitere laden“ in Blöcken zu 50
und „Export (CSV)“ mit denselben Filtern. Filter und Sortierung rechnet der Server, sie stehen in der
Adresse; die Kacheln „Einsatzbereitschaft“ und „Nicht einsatzbereit“ führen dorthin. Das Suchfeld
sucht in Kennung, Scan-Code, Seriennummer, Hersteller und Modell; Gerätetyp und Standort wählt man
über die Auswahllisten (so entschieden nach dem Live-Test, 30.10.2026). Schritt 4: Die Kennung (in
der Liste und auf dem Dashboard) öffnet das Gerätedetail `geraete/<id>/details`: oben Kennung, Typ,
Standort, Status groß und farbig und die nächste Prüfung, darunter Stammdaten und Verlauf (am breiten
Bildschirm nebeneinander). Groß „Umbuchen / Status ändern“ (danach zurück zum Detail mit
Bestätigung), kleiner „Bearbeiten“ (Stammdaten außer Scan-Code), „Prüfdatum ändern“ (leer =
entfernen) und „Code zuordnen“ (Handscanner tippt ins Feld und Enter speichert; am Handy „Mit der
Kamera scannen“, der gelesene Code steht danach im Fenster und wird erst mit „Code speichern“
übernommen; „Code entfernen“). Rot und abseits „Aussondern …“ mit Rückfrage, die das Gerät nennt,
und freiwilligem Grund. Ein ausgesondertes Gerät zeigt stattdessen „Wieder aufnehmen“: Status,
Standort, Kennung, Gerätetyp und „Scan-Code behalten“, vorbelegt mit dem bisherigen Stand; ist der
bisherige Standort inzwischen gesperrt oder der Typ deaktiviert, sagt das Fenster es sofort. Jede
Aktion hat einen freiwilligen Kommentar für den Verlauf. Wurde das Gerät inzwischen geändert, zeigt
die Seite den neuen Stand, das Fenster bleibt offen und sagt es; eigene Eingaben bleiben. Auf der
Umbuchen-Seite führt für Admins ein Link „Gerätedetail (Admin)“ hierher.

| | Admin | Super-Admin |
|---|---|---|
| Menü | Dashboard, Suchen, Geräte, Standorte, Gerätetypen, Benutzer, Protokoll | Dashboard, Städte, Super-Admins |
| Kacheln | Einsatzbereitschaft (z. B. 42 von 45), nicht einsatzbereit, Prüffristen, offene Hinweise; dazu Listen „nicht einsatzbereit“ und „letzte Aktivitäten“ | aktive und gesperrte Städte, offene Admin-Einladungen mit „Erneut senden“ (Link läuft nach 24 h ab), Benutzer gesamt |

### Listen
- Suchfeld oben rechts, filtert schon beim Tippen nach Teiltext; daneben „Neu“.
- Spaltenköpfe sortieren wie im Windows-Explorer (Klick auf-, zweiter Klick absteigend, Pfeil).
- Stift-Symbol zum Bearbeiten in jeder Zeile; Klick auf die Zeile öffnet die Details.
- Am Handy werden Zeilen zu Karten, Sortierung über Knöpfe oben (wie die Spaltenköpfe).
  Auswahllisten (Gerätetyp, Standort, Rolle …) mit Select bzw. Combobox von Reka UI, kein natives
  `<select>` (siehe CLAUDE.md, CSP; TODO-SPAETER.md T8).
- Geräte werden nie gelöscht, sondern ausgesondert (rot, abseits, Sicherheitsrückfrage mit Namen).
  Typen werden deaktiviert, Standorte gesperrt (nur wenn leer, sonst freundlicher Hinweis wie
  „Hier liegen noch 3 Geräte“), Benutzer anonymisiert.

### Bereiche des Admins
- **Geräte:** Liste mit Import- und Export-Button. Detail: oben Kennung, Typ, Standort, Status
  groß und farbig, darunter Stammdaten, unten die Historie. Aktionen: groß „Umbuchen / Status
  ändern“, kleiner „Bearbeiten“, „Code zuordnen“ (QR oder Barcode einscannen), rot und abseits
  „Aussondern“. Ausgesonderte Geräte sind in der Suche am Handy bewusst ausgeblendet; die
  Geräteliste der Admin-Konsole braucht daher einen Filter „Ausgesonderte anzeigen“, von dem aus
  der Admin die Details öffnet und das Gerät wieder aufnimmt.
- **Standorte:** Baum statt Liste, Fahrzeug mit Fächern darunter, Symbol für Fahrzeug, Raum oder
  extern, Zahl der Geräte; Klick zeigt die Geräte dort.
- **Gerätetypen:** einfache Liste mit Name und Gerätezahl; umbenennen, deaktivieren.
- **Benutzer:** oben deutlicher Hinweis „2 neue Anmeldungen warten“; Freischalten im kleinen
  Fenster mit Anzeigename und Rolle. Einladungscodes im eigenen Reiter: der Code erscheint nur
  einmal, groß, mit Kopieren-Button und QR-Code.
  Umgesetzt in Schritt 8 (`benutzer`, Menü „Benutzer“ mit Zähler; Einladungscodes folgen in
  Schritt 9): oben die wartenden Anmeldungen (älteste zuerst) mit „Freischalten“ (Anzeigename,
  vorbelegt mit dem Namen, und Rolle) und „Ablehnen“ (Rückfrage, die Person erhält eine Mail);
  darunter alle Konten als Liste mit Suche (Name, E-Mail) und sortierbaren Spalten, am Handy Karten.
  Der Stift öffnet Anzeigename und Rolle, abseits Sperren (mit Rückfrage) bzw. Entsperren und rot
  Löschen (Rückfrage). Unbestätigte Registrierungen lassen sich ablehnen. Das eigene Konto lässt sich
  nicht sperren, herabstufen oder löschen. Die Kachel „Offene Hinweise“ auf dem Dashboard führt mit
  „Freischaltungen ansehen“ hierher.
  Umgesetzt in Schritt 9: Reiter „Konten“ und „Einladungscodes“ (`benutzer?ansicht=einladungscodes`).
  Die Liste zeigt die aktiven Codes mit Nutzungen („12 von 50 Registrierungen“) und „gültig bis“,
  abgelaufene, aufgebrauchte und widerrufene auf Wunsch; „Widerrufen“ mit Rückfrage (auch ein
  gedruckter Aushang gilt danach nicht mehr). „Neuer Einladungscode“: Bezeichnung (freiwillig, nur
  für den Admin), Gültigkeit in Tagen (Standard 30) und höchstens so viele Registrierungen (Standard
  50). Danach steht der Code einmal groß da mit QR-Code, Link, „Aushang drucken“, „Link teilen“ und
  „Code kopieren“; „Schließen“ (oder Widerrufen) entfernt ihn. Der Aushang zeigt zusätzlich „Jetzt
  registrieren“ und den Satz, dass danach ein Admin das Konto freischaltet; die Bezeichnung steht
  nicht darauf. Live geprüft (31.10.2026, Test-Stadt): Der Aushang passt auf genau eine A4-Seite;
  QR-Code mit der iPhone-Kamera gescannt → Registrierung (Adresse aus Entscheidung 38) → Bestätigung
  → Freischaltung → Anmeldung in Ordnung; „Link teilen“ in Ordnung.
- **Protokoll:** Hinweise zu Konflikten (siehe oben).
  Umgesetzt in Schritt 11 (`protokoll`, Menü „Protokoll“ mit der Zahl der offenen Hinweise): Reiter
  „Hinweise“ und „Verlauf exportieren“ (`protokoll?ansicht=export`). Die Hinweise stehen als Karten,
  neueste zuerst, mit Filter Offen (Standard), Erledigt und Alle (in der Adresse) und „Weitere
  laden“ in Blöcken zu 50; jede Karte nennt Art, Status, Gerät, die Buchung (Stand, Person, Zeit) und
  den anderen Stand. Ein Hinweis (`protokoll/<id>`, Ziel der Hinweis-Mails) erklärt in einem Satz,
  was geschehen ist, und zeigt nebeneinander die Buchung (Person mit E-Mail, wann erfasst, wann beim
  Server eingegangen, gewünschter Standort und Status, Kommentar), den anderen Stand („Überschriebener
  Stand“ bzw. „Stand beim Eingang“, mit Person und Zeit) und das Gerät jetzt mit „Umbuchen / Status
  ändern“ (danach zurück zum Hinweis mit Bestätigung). „Rückfrage per E-Mail“ öffnet das
  Mailprogramm mit Betreff „Rückfrage zu Ihrer Buchung von Atemschutzmaske M1“ und dem Anfang des
  Textes (Zeit und Stand der Buchung); gelöschte Konten haben keinen Link. „Erledigen …“ mit
  freiwilliger Notiz (mehrzeilig, höchstens 500 Zeichen) bzw. „Wieder öffnen“; darunter der Verlauf
  des Hinweises. Damit die Buchung vollständig ist, liefert die API zum Hinweis jetzt auch deren
  Kommentar. „Verlauf exportieren“: Zeitraum (Schnellwahl 30, 90 oder 365 Tage, sonst Von/Bis;
  Standard die letzten 90 Tage, höchstens 366), Gerätetyp, Standort samt Fächern und Reihenfolge,
  Download als CSV wie der Export der Geräteliste; zu viele Einträge meldet der Server mit der Bitte,
  den Zeitraum zu verkleinern. Die Kachel „Offene Hinweise“ führt mit „Hinweise ansehen“ hierher.
  Live geprüft (31.10.2026, Test-Stadt): Hinweis über eine bestätigte Offline-Buchung an M6 erzeugt;
  Liste, Hinweis, „Erledigen …“ mit Notiz, „Wieder öffnen“, Zähler im Menü und „Verlauf exportieren“
  (UTF-8 mit BOM, Spalten, Inhalte) in Ordnung, keine Fehler in der Konsole.
- **Einstellungen:** Anzeigemodus, Erscheinungsbild der Feuerwehr mit Vorschau, Mail-Benachrichtigung,
  Passwort und angemeldete Geräte.
  Umgesetzt in Schritt 12 (`einstellungen`, im Menü unten über dem Namen des Kontos): von oben nach
  unten Anzeigemodus (wie im Profil, gilt für dieses Gerät), „Erscheinungsbild der Feuerwehr“,
  „Benachrichtigung“ und „Passwort“ (am breiten Bildschirm nebeneinander), „Angemeldete Geräte“.
  Akzentfarbe: sechs Farbkreise (Feuerwehrrot als Standard, Dunkelblau, Blau, Grün, Violett,
  Anthrazit) und ein Feld für eine eigene Farbe (#RRGGBB oder Kurzform #RGB); die Prüfung beim
  Tippen rechnet den Kontrast wie der Server („✓ … gut lesbar (Kontrast 6,57:1)“ bzw. „✕ Zu hell …“).
  Die Vorschau (Kopfleiste mit Logo und Name, Gerät, Knopf „Speichern“) zeigt die gewählte Farbe
  sofort, die App selbst erst nach „Farbe speichern“; „Verwerfen“ stellt den gespeicherten Stand
  her. Logo: „Logo hochladen …“ nimmt PNG, JPG, SVG (auch WebP, GIF); die App zeichnet das Bild auf
  512 × 512 PNG (Rand 16 px, nicht quadratische mittig mit durchsichtigem Rand, Entscheidung 37),
  zeigt es in Logo und Vorschau und speichert erst mit „Logo speichern“; zu detailreiche Bilder (PNG
  über 500 KB) und unlesbare Dateien meldet sie vorher. „Logo entfernen …“ mit Rückfrage. Nach dem
  Speichern gelten Farbe und Logo sofort in Menü, Titel und App-Symbol. Benachrichtigung: ein
  Kästchen „Mail bei jeder Änderung an Geräten“ (mit der Adresse; eigene Änderungen ausgenommen),
  wirkt sofort. Passwort und angemeldete Geräte wie im Profil. Die Einstellungen des Super-Admins
  (seine Benachrichtigung) kommen mit dessen Bereich.
  Live geprüft (31.10.2026, Test-Stadt): Akzentfarbe mit Kontrastprüfung, Speichern (Stadtfarbe der
  App und `theme-color`), Logo hochladen (breites Bild → 512 × 512, 5,9 KB, Menü und Symbol im Tab),
  Logo entfernen, Benachrichtigung an/aus in Ordnung, keine Fehler in der Konsole. Dabei gefunden:
  Der Start mit veralteter Version gab nach rund 6 s auf (siehe "Updates der App"), und das Symbol
  auf dem Homescreen schien transparente Ränder zu haben. Nachgeprüft am iPhone („Zum
  Home-Bildschirm“ mit Logo, 31.10.2026): sauber, das `apple-touch-icon` (180 px) ist schon deckend
  weiß. Die Symbole des Manifests (192 und 512 px) sind durchsichtig und nur `any`; Android setzt
  sie verkleinert in einen weißen Kreis. Deckende `maskable`-Symbole sind T13 in
  `docs/TODO-SPAETER.md`. Außerdem (eigene Beobachtung beim Umsetzen): Wählt man während des
  Speicherns der Akzentfarbe eine andere Farbe, setzt die App das Feld nach der Antwort auf die
  gespeicherte zurück (T14, Randfall).

### Admin-Konsole – Entscheidungen
29. **Reihenfolge:** Die Admin-Konsole kommt vor dem Offline-Modus, damit Immendingen starten kann.
    Schritte: Gerüst und Dashboard, Standorte und Gerätetypen, Geräteliste, Gerätedetail, Links in
    den Log-Mails (T6), Gerät anlegen, CSV-Import, Benutzer, Einladungscodes, Sammelaktionen,
    Protokoll, Einstellungen, Abschluss mit Live-Test.
30. **Einladungscodes:** Nach dem Erzeugen (der Code ist nur einmal sichtbar, groß, mit Kopieren und
    QR-Code) zusätzlich „Aushang drucken“: A4-Druckseite mit Name und Logo der Feuerwehr, großem
    QR-Code mit dem Link `registrieren#code=…`, dem Code in Klarschrift, der Adresse, drei Schritten
    „QR-Code mit der Handy-Kamera scannen – Formular ausfüllen – E-Mail bestätigen“, „gültig bis“ und
    Spam-Hinweis; gedruckt über die Druckfunktion des Browsers mit eigenem Druck-CSS. Dazu „Link
    teilen“ (Web Share API, sonst Kopieren). Der QR-Code entsteht in der App (Bibliothek `uqr`: nur
    das Raster, Vue zeichnet daraus ein SVG; die CSP bleibt unverändert). Der Code steht nie in der
    Adresse; ein späterer Nachdruck braucht einen neuen Code (bewusst, der Server kennt nur den Hash).
31. **Mehrfachauswahl** in der Geräteliste (auch „alle auswählen“ der gefilterten Liste) mit zwei
    Sammelaktionen: „Prüfdatum setzen“ und „Umbuchen / Status ändern“, jeweils mit gemeinsamem
    Kommentar. Aussondern ist keine Sammelaktion. Das Backend bekommt dafür Sammel-Endpunkte
    (`POST devices/bulk/inspection-due`, `POST devices/bulk/state`; je Gerät `id`, `version`,
    Zielwerte und eigene `client_ref`). Jedes Gerät wird einzeln geprüft und in eigener Transaktion
    gebucht, mit eigenem Verlaufseintrag; die Antwort nennt das Ergebnis je Gerät (übernommen,
    `version_conflict` mit aktuellem Stand, `rule_violation` …), Teilerfolg ist erlaubt. Die Höchstzahl
    je Anfrage legt das Backend fest; die App teilt größere Auswahlen in Blöcke und zeigt den
    Fortschritt („8 von 12“) und eine Ergebnisliste. Log-Mail: EINE Sammelmail je Empfänger und
    Anfrage („Log – <Stadt> – 12 Geräte: Prüfdatum gesetzt“ mit Liste), nicht je Gerät.
    Umgesetzt in Schritt 10: In der Geräteliste hat jede Zeile (am Handy jede Karte) ein Kästchen,
    der Spaltenkopf wählt alle angezeigten; sind alle angezeigten gewählt und passen mehr, „Alle N
    Geräte der Liste auswählen“ (bis 2.000). Ausgesonderte sind nicht wählbar, ein neuer Filter hebt
    die Auswahl auf. Die Leiste „N Geräte ausgewählt“ bietet „Prüfdatum setzen …“ (leer = entfernen)
    und „Umbuchen / Status ändern …“ (neuer Standort und/oder Status, nur aktive Standorte; bei
    „defekt“ wie beim Umbuchen eine kurze Beschreibung als Pflicht), jeweils mit Kommentar. Das
    Backend nimmt höchstens 50 Geräte je Anfrage; die App sendet Blöcke nacheinander und zeigt „8 von
    12 gesendet …“. Danach steht über der Leiste das Ergebnis („Prüfdatum: 10 Geräte geändert, 1
    unverändert, 1 nicht möglich.“) mit den Geräten, die nicht geändert wurden, und warum; diese
    bleiben ausgewählt (mit dem aktuellen Stand), die übrigen verlassen die Auswahl. Die Sammelmail
    hat den Betreff „Log <Stadt>: 12 Geräte – Prüfdatum geändert“ (passend zu den übrigen Log-Mails)
    und verlinkt für Admins auf die Geräteliste; ändert eine Sammelaktion nur ein Gerät, kommt die
    gewöhnliche Mail zum Gerät. Live geprüft (31.10.2026, Test-Stadt): Prüfdatum für 5 Masken und
    Umbuchen/Status „defekt“ (mit Pflichtkommentar) für 2 Geräte in Ordnung, Verlauf je Gerät in
    Ordnung; „Alle N Geräte der Liste auswählen“ erscheint bei mehr als 50 Geräten; der zweite Admin
    erhielt genau 2 Sammelmails statt 7 einzelner.
32. **Geräte erfassen:** CSV-Vorlage zum Herunterladen (Spalten wie der Import, Semikolon, UTF-8 mit
    BOM für Excel, zwei Beispielzeilen mit den Kennungen `BEISPIEL-1` und `BEISPIEL-2`; die Vorschau
    warnt, solange sie noch in der Datei stehen) und eine zweite Datei „Gültige Werte“ mit den
    vorhandenen Gerätetypen und Standorten (in der Vorlage selbst würden sie den Import stören). Import
    mit Vorschau und Fehlern je Zeile, mit Vorschlag bei Tippfehlern („Meinten Sie ‚HLF 20‘?“).
    Einzelformular mit „Speichern und nächstes“: Typ, Standort und Prüfdatum bleiben, die Kennung zählt
    weiter (M24 → M25); der Scan-Code lässt sich am Handy mit der Kamera einlesen (vorhandener
    Scanner). Umgesetzt in Schritt 6 als eigene Seite `geraete/neu` (Knopf „Neues Gerät“ in der
    Geräteliste): „Speichern und nächstes“ ist der erste Knopf (auch Enter), „Speichern“ öffnet das
    neue Gerät; Enter im Feld Scan-Code speichert nicht (Handscanner), sondern führt weiter.
    Der Import ist in Schritt 7 umgesetzt als Seite `geraete/import` (Knopf „Import (CSV)“ in der
    Geräteliste): Vorlage und „Gültige Werte“ erzeugt der Server (Beispielzeilen mit Gerätetypen und
    Standorten der Stadt, damit sie gültig aussehen; „Gültige Werte“ nur aktive). Die Datei wird
    gewählt und sofort geprüft; die Vorschau zeigt Zusammenfassung, Filter (Fehler zuerst, sonst
    Zeilen mit Warnung), je Zeile Änderungen alt → neu, Fehler und Warnungen sowie die unbekannten
    Gerätetypen und Standorte gesammelt mit „Meinten Sie …?“ (Vorschläge rechnet der Server, nur
    aktive Werte; ein Fach auch über seinen Namen: „G1“ → „HLF 20 / G1“). Übernehmen nur ohne Fehler,
    mit Kommentar für den Verlauf; hat sich seither etwas geändert, prüft die Seite neu und sagt es.
    Nach dem Bearbeiten in Excel wählt man die Datei erneut (die Seite liest sie nicht nach).
33. **Adressen:** Die Detailseite eines Geräts für Admins ist `geraete/<id>/details`;
    `geraete/<id>` bleibt das Umbuchen (Entscheidung 15). Die Geräteliste der Konsole (`geraete`) hat
    den Filter „Ausgesonderte anzeigen“; von dort öffnet der Admin die Details und nimmt das Gerät
    wieder auf.
34. **Menü:** am PC links mit Text, am Tablet eingeklappt auf Symbole, am Handy über ☰ ausklappbar;
    Einträge Dashboard, Suchen, Geräte, Standorte, Gerätetypen, Benutzer, Protokoll; unten
    „Einstellungen“ und „Abmelden“, der Name des Kontos nur als Text (kein eigenes Profil in der
    Konsole: Passwort und angemeldete Geräte stehen in den Einstellungen). Das Menü ersetzt die
    Übergangsleiste auf dem Dashboard; auf den Handy-Seiten behält der Admin die Leiste unten mit
    „Dashboard“.
35. **„Letzte Aktivitäten“** auf dem Dashboard kommen aus einem eigenen lesenden Endpunkt: dem
    Verlauf der ganzen Stadt als JSON (`GET device-events`, neueste zuerst, mit Gerät, Konto, Zeit,
    Von/Nach und Kommentar; nur Admins).
36. **Links in Mails** (T6): Log-Mails verlinken für Admins direkt auf `geraete/<id>/details`,
    Hinweis-Mails auf `protokoll/<id>`; Super-Admins erhalten keinen Link auf ein Gerät (sie sehen
    keine Geräte). Die Mail „Neue Registrierung wartet auf Freischaltung“ verlinkt auf `benutzer`.
    Umgesetzt in Schritt 5: Super-Admins erhalten in Log- und Hinweis-Mails gar keinen Link, die
    Sammelmail eines CSV-Imports verlinkt auf `geraete`. `benutzer` ist seit Schritt 8 die
    Benutzerverwaltung, `protokoll/<id>` seit Schritt 11 der Hinweis (vorher das Dashboard).
37. **Logo:** Die App bringt jedes Bild (PNG, JPG, SVG …) selbst auf 512 × 512 PNG (quadratisch, mit
    Rand) und zeigt die Vorschau per Canvas; der Server prüft weiter genau 512 × 512.
    Umgesetzt in Schritt 12: Der Browser liest die Datei über eine `blob:`-Adresse; dafür erlaubt die
    CSP `img-src 'self' blob:` (kein `data:`, keine fremde Herkunft).
38. **Live-Test der Benutzerverwaltung** (Test-Stadt): „Freischalten“ mit dem vorhandenen Konto
    `holger.burkarth@live.de` (wartet auf Freischaltung); die Registrierung über den Aushang mit
    `holger.burkarth+user1@live.de`.

Später, nicht in diesem Abschnitt: Nachdruck eines Aushangs ohne neuen Code, Sammelauswahl bei mehr
als 2.000 Geräten.

### Bereiche des Super-Admins
- **Städte:** Liste mit Name, Adresse, Status, Zahl der Admins und Benutzer. „Neue Stadt“: Name,
  Kürzel (aus dem Namen vorgeschlagen, Umlaute umgewandelt, Prüfung „frei“ beim Tippen), E-Mail des
  ersten Admins.
- **Super-Admins:** Liste mit Sperren und Entsperren; das geschützte Konto ohne Button.

### Super-Admin – Entscheidungen
Geplant in der Nacht vom 31.10.2026 (Agent, ohne Rückfrage; alle mit „Vorläufig“ markierten Punkte
prüft Holger). API: `CLAUDE.md`, "Städte verwalten und Admins einladen" (fertig und live).

39. **Schritte:** S1 Menü, Dashboard, Städte-Liste und Einstellungen; S2 „Neue Stadt“; S3
    Stadtdetail (umbenennen, sperren/entsperren, Admin einladen, Einladung erneut senden, Benutzer
    ansehen); S4 Super-Admins; S5 Abschluss mit Live-Test (lesend) und `LIVETEST-SUPER-ADMIN.md` für
    die schreibenden Prüfungen. Die Anmeldung unter `/service/` gibt es schon (Entscheidung 2).
40. **Gerüst:** dieselbe Darstellung wie die Admin-Konsole (Menü in drei Breiten, Entscheidung 34),
    Einträge Dashboard, Städte, Super-Admins; unten Einstellungen, der Name des Kontos und Abmelden.
    Oben „Service“ mit „Super-Admin“ und dem Standard-Symbol (unter `service` gibt es keine Stadt,
    daher immer Feuerwehrrot). Vorläufig (Agent, Nacht 31.10.): keine Zähler im Menü.
41. **Dashboard** (Mockup „SuperAdmin“): Kacheln „Städte“ (N aktiv, M gesperrt; führt zu `staedte`),
    „Offene Admin-Einladungen“ (Summe der eingeladenen Admins aller Städte) und „Benutzer gesamt“
    (aktive, nicht gesperrte Konten aller Städte). Darunter die offenen Einladungen (Stadt, E-Mail,
    eingeladen am) mit „Erneut senden“, dann die Städte wie in der Liste. Vorläufig (Agent, Nacht
    31.10.): Die Ablaufzeit einer Einladung („läuft in 6 Std. ab“ im Mockup) zeigt die App nicht, die
    API kennt sie nicht; stattdessen der Satz „Ein Einladungslink gilt 24 Stunden ab dem Senden.“
    Die eingeladenen Konten holt die App je Stadt mit Einladungen (`GET tenants/{id}/users?status=invited`).
42. **Städte-Liste** (`staedte`): Spalten Name, Adresse (`/firefighting/<kürzel>/`), Status, Admins
    und Benutzer; Suche in Name und Kürzel, Spaltenköpfe sortieren, am Handy Karten mit Knöpfen
    „Sortieren“ (wie bei den Gerätetypen). Status: „gesperrt“, sonst „Einladung offen“, solange die
    Stadt keinen aktiven Admin, aber eine offene Einladung hat (wie im Mockup), sonst „aktiv“.
    Admins: aktive, dazu klein „N eingeladen“. Der Name führt zum Stadtdetail.
43. **Neue Stadt** (Fenster `FormDialog`, Knopf „Neue Stadt“ auf Dashboard und Städte-Liste): Name,
    Kürzel und E-Mail des ersten Admins. Das Kürzel folgt dem Namen, bis es von Hand geändert wird.
    Vorläufig (Agent, Nacht 31.10.): Vorschlag ohne „Freiwillige Feuerwehr“, „Feuerwehr“, „FF“,
    „Stadt“, „Gemeinde“ am Anfang (wie im Mockup „Feuerwehr Bad Dürrheim“ → `bad-duerrheim`),
    ä/ö/ü/ß → ae/oe/ue/ss, andere Akzente ohne Zeichen, alles andere wird Bindestrich, höchstens 50
    Zeichen. Prüfung beim Tippen wie `TenantSlug` (Format, Länge, `service`), danach (300 ms) der
    Server (`GET tenants/slug-available`): „Frei. Adresse: www.prodad.de/firefighting/<kürzel>/“
    bzw. „Bereits vergeben“. Knopf „Stadt anlegen und einladen“; danach das Stadtdetail mit grüner
    Meldung (bzw. Hinweis, wenn die Mail nicht gesendet werden konnte: „Einladung erneut senden“).
44. **Stadtdetail** (`staedte/<id>`): Name, Adresse (nur als Text, Entscheidung 47), Status, Zahlen
    und angelegt am; „Umbenennen …“ (Fenster), „Stadt sperren …“ bzw. „Stadt entsperren …“ (je mit
    Rückfrage; Sperren meldet alle Konten der Stadt ab, die Daten bleiben), „Admin einladen …“
    (Fenster mit E-Mail). Darunter die Konten der Stadt nur zum Ansehen (Name, E-Mail, Rolle, Status,
    registriert; Suche, ohne gelöschte); eingeladene Admins mit „Einladung erneut senden“.
45. **Super-Admins** (`super-admins`): Liste mit Name, E-Mail, Status; „Sperren …“ (Rückfrage, meldet
    das Konto überall ab) bzw. „Entsperren …“. Das geschützte Konto trägt „geschützt“ und hat keinen
    Knopf, das eigene Konto „Sie“ und keinen Knopf (der Server lehnt beides ohnehin ab). Anlegen,
    Passwort und Schutz bleiben Sache der Kommandozeile (Hinweis unter der Liste).
46. **Einstellungen** (`einstellungen`): Anzeigemodus, Benachrichtigung (Mails zu Geräten aller Städte,
    ohne Links), Passwort und angemeldete Geräte; kein Erscheinungsbild.
47. **Keine Geräte, kein Wechsel in eine Stadt:** keine Links in eine Stadt (Adressen nur als Text),
    keine Geräte, keine Benutzerverwaltung einer Stadt außer dem Ansehen. Konten einer Stadt sehen
    keine Seite des Super-Admins (es gibt sie unter einer Stadt nicht).

Stand (31.10.2026, Nacht): S1 umgesetzt und live geprüft (lesend): Menü, Dashboard (Kacheln, offene
Einladungen mit „Erneut senden“, Städte), `staedte` mit Suche und Sortierung (am Handy Karten),
Einstellungen. S2 umgesetzt und live geprüft (nur Prüfung beim Tippen, ohne Anlegen): „Neue Stadt“ auf
Dashboard und Städte-Liste. S3 umgesetzt: Stadtdetail `staedte/<id>` (der Name in der Liste führt
hin).

## User am Handy
Umgesetzt und abgeschlossen am 30.10.2026 (Schritte 1–10). Wünsche aus den Live-Tests stehen in
`docs/TODO-SPAETER.md` und werden erst nach den Hauptabschnitten geplant.

- **Start:** großes Suchfeld, Button „Code scannen“, darunter „Zuletzt benutzt“. Leiste unten:
  Suchen, Prüffristen, Profil.
- **Umbuchen:** oben Gerät mit aktuellem Standort und Status. Standort: aktueller und zuletzt
  benutzte zur Auswahl, dazu „Anderen Standort suchen …“ (nach dem Live-Test deutlich als
  Schaltfläche: umrandet in der Stadtfarbe, volle Breite, Lupe links, Pfeil rechts, mindestens
  48 px hoch, darunter mehr Abstand zum Status; Hover am PC hinterlegt mit Randbalken links).
  Status über drei große Tasten, vorausgewählt ist der jetzige Zustand. Kommentar freiwillig; bei
  „defekt“ rot hervorgehoben mit Bitte um Beschreibung. Unten groß „Speichern“.
- **Standortauswahl:** bildschirmfüllend, Suchfeld oben. Ohne Eingabe alle Standorte als Karten;
  ein Fahrzeug klappt mit Antippen der ganzen Zeile auf. Eine aufgeklappte Gruppe hebt sich ab
  (Abstand, Rahmen in der Stadtfarbe, Schatten, getönte Kopfzeile); Fächer darunter eingerückt,
  als erster Eintrag **„Ganzes Fahrzeug, ohne Fach“** (entschieden: ein Gerät darf auf ein ganzes
  Fahrzeug gebucht werden, auch wenn es Fächer hat; das Backend erlaubt das bereits). Mit
  Suchbegriff eine flache Trefferliste, Fächer mit ihrem Fahrzeug darunter. Gesperrte Standorte
  fehlen. Ein Tipp wählt und führt zurück zum Umbuchen.
  Umgesetzt: Die Standortwahl ist eine Ansicht der Umbuchen-Seite (Adresse `?standortwahl=1`). So
  bleiben Status und Kommentar stehen, ein Update der App lädt dabei nicht neu, und der Zurück-Knopf
  des Handys schließt nur die Auswahl. Zu Beginn ist die Gruppe des gewählten Standorts
  aufgeklappt. Jeder Standort zeigt die Zahl seiner Geräte (Fahrzeug einschließlich der Fächer).
  Räume und externe Standorte mit Unterteilung haben als ersten Eintrag „Ganzer Raum, ohne Fach“
  bzw. „Ganzer Standort, ohne Fach“. Die Suche verknüpft Wörter mit UND über den ganzen Pfad
  („hlf g1“), ohne Groß-/Kleinschreibung und Akzente. Mit echten Daten am Handy geprüft
  (29.10.2026).
- **Profil:** von oben nach unten Konto (Name, Rolle, „Abmelden“), Anzeigemodus, Passwort und
  angemeldete Geräte. Umgesetzt: Der Anzeigemodus (System, Hell, Dunkel wie im Mockup
  „Einstellungen“) wirkt sofort und gilt nur für dieses Gerät; bei „System“ steht dabei, wie das
  Gerät gerade eingestellt ist. „Passwort ändern“ klappt ein Formular auf: aktuelles Passwort und
  neues Passwort mit Live-Prüfung wie bei der Registrierung. Ein falsches aktuelles Passwort steht
  rot am Feld (das Feld wird geleert), ein vom Server abgelehntes neues Passwort am neuen Feld; nach
  zu vielen Fehlversuchen ist „Passwort speichern“ mit Countdown gesperrt (wie beim Anmelden). Nach
  dem Ändern bleibt nur dieses Gerät angemeldet, die Seite sagt es. „Angemeldete Geräte“ zeigt
  dieses Gerät zuerst („Dieses Gerät“, seit wann angemeldet), dann die anderen mit „zuletzt aktiv“
  und Anmeldedatum, je mit „Abmelden“; darunter „Alle anderen Geräte abmelden“ mit Rückfrage. Die
  Benachrichtigung „Log“ für Admins steht in den Einstellungen der Admin-Konsole (Schritt 12).
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
    geladene Liste wird später die lokale Kopie für den Offline-Modus. Umgesetzt (`src/data/`): Die
    Blöcke werden nacheinander geladen; ändert sich der Bestand währenddessen, lädt die App die
    Liste einmal neu. Bei einem Fehler bleibt die bisherige Liste stehen. Schnelle Seitenwechsel
    (innerhalb von 5 Sekunden) laden nicht doppelt. Abmelden und eine abgelaufene Sitzung (401)
    löschen die Liste.
15. **Gerät antippen** öffnet direkt die Umbuchen-Ansicht (Mockup „UserUmbuchen“), unten ein Link
    „Details und Verlauf“ (Stammdaten, Historie). Admins nutzen dieselbe Umbuchen-Ansicht.
    Umgesetzt: „Details und Verlauf“ ist wie die Standortwahl eine Ansicht der Umbuchen-Seite
    (`?details=1`); angefangene Eingaben bleiben, der Zurück-Knopf führt zum Umbuchen. Oben der
    aktuelle Standort, Status und die nächste Prüfung (überfällig rot), darunter alle Stammdaten
    (leere als „–“), dann der Verlauf, neueste zuerst, je 20 Einträge mit „Weitere Einträge
    laden“. Je Eintrag: was geschah, wann (Aktionszeit in der Ortszeit: „heute 14:02“, „gestern
    18:30“, sonst „12.10.2026, 09:15“), von wem, was sich geändert hat („alt → neu“) und der
    Kommentar. Nicht übernommene Offline-Buchungen sind gestrichelt abgesetzt und zeigen das
    Gewünschte, den Grund und, wenn sie später ankamen, den Eingang beim Server. Auch ausgesonderte
    Geräte haben den Link.
16. **„Zuletzt benutzt“:** geöffnete oder umgebuchte Geräte, nur auf diesem Handy, höchstens 8,
    neueste oben; die Angaben kommen immer aus der aktuellen Geräteliste. Abmelden löscht die Liste
    (eine abgelaufene Sitzung nicht: Nach der erneuten Anmeldung ist sie wieder da). Gespeichert wird
    je Stadt und Konto, ein anderes Konto auf demselben Handy sieht sie nicht. Je Gerät steht klein
    unter dem Standort, wann es zuletzt umgebucht oder sein Status geändert wurde (egal von wem;
    `state_changed_at` aus `GET devices`, in der Ortszeit des Handys): unter 1 Stunde „vor 5 Min.“
    (unter 1 Minute „gerade eben“), sonst „heute 14:02“, „gestern 18:30“, ältere „12.10.2026“.
    Nach einer eigenen Buchung sofort aktuell (die Antwort der Buchung ersetzt das Gerät in der
    Liste).
17. **„Code scannen“:** zuerst Vergleich mit dem Scan-Code, dann mit der Kennung (ohne Groß- und
    Kleinschreibung, wie im Backend). Kein Treffer: Hinweis „Kein Gerät mit diesem Code gefunden“,
    der gescannte Text steht danach im Suchfeld. Die Kamera liest QR- und Barcodes über eine
    Bibliothek (der eingebaute Scanner fehlt in Safari); die CSP wird nur so weit wie nötig
    erweitert. Umgesetzt: großer Knopf „Code scannen“ unter dem Suchfeld. Der Scanner füllt den
    Bildschirm (Kamerabild mit Rahmen, „Halten Sie den Barcode oder QR-Code in den Rahmen.“) und ist
    eine Ansicht von „Suchen“: Der Zurück-Knopf des Handys, „Abbrechen“ und der Pfeil oben schließen
    ihn, die Kamera geht dabei immer aus. Beim ersten erkannten Code geht die Kamera aus und das
    Gerät öffnet sich; Zurück führt dann zu „Suchen“. Ohne Treffer steht der Code im Suchfeld mit dem
    Hinweis. Ist die Kamera nicht erlaubt, nicht vorhanden oder belegt oder lässt sich das
    Lesemodul nicht laden, erscheint eine Meldung mit „Erneut versuchen“; kann der Browser die
    Kamera gar nicht verwenden (z. B. ohne HTTPS), nur der Hinweis, den Code ins Suchfeld zu tippen.
    Die Kennung wird dabei genau wie im Backend kleingeschrieben (auch bei
    seltenen Buchstaben, gemeinsame Testfälle mit der Datenbank). Bei mehr als 2.000 Geräten fragt
    die App den Server (`scan_code`, dann die Kennung). Live auf dem iPhone 16 Pro geprüft
    (30.10.2026): alle 8 Testcodes (QR, GS1-DataMatrix mit und ohne GS, Code 128, unbekannter Code)
    wie erwartet, die Kamera geht beim Schließen aus.
18. **Kommentar:** Bei „defekt“ Pflicht, mindestens 5 Zeichen (prüft nur die Oberfläche). Bei
    „einsatzbereit“ und „in Wartung“ freiwillig. Umgesetzt: Pflicht nur, wenn der Status auf
    „defekt“ wechselt (ein neuer Defekt); ein schon defektes Gerät z. B. in die Werkstatt umbuchen
    geht ohne Kommentar. Der Kommentar ist einzeilig (Zeilenumbrüche werden zu Leerzeichen),
    höchstens 500 Zeichen.
19. **Admins** dürfen alle Handy-Seiten öffnen (`suchen`, `prueffristen`, `profil`, `geraete/…`);
    ihre Startseite bleibt `dashboard`. In der Leiste unten steht für sie zusätzlich „Dashboard“
    (vorne), damit sie zurück in die Admin-Konsole kommen.
20. **Suchfelder:** lokal über Kennung, Scan-Code, Seriennummer, Hersteller, Modell, Gerätetyp und
    Standortpfad; mehrere Wörter mit UND verknüpft; höchstens 50 Treffer, darüber der Hinweis
    „weiter eingrenzen“. Dafür liefert `GET devices` zusätzlich `serial_number`, `manufacturer`
    und `model`.
21. **Zuletzt benutzte Standorte** (Umbuchen): die letzten 3 eigenen Ziele, lokal je Konto, beim
    Abmelden gelöscht. Umgesetzt: zur Auswahl stehen der aktuelle Standort und diese Ziele, soweit
    nicht gesperrt, dazu ein in der Standortwahl gewählter („gewählt“) und „Anderen Standort
    suchen …“.
22. **Nach dem Speichern** geht es zurück dorthin, woher man kam („Suchen“ oder „Prüffristen“),
    mit grüner Bestätigung auf dieser Seite (z. B. „M1 → HLF 20 / G2 · einsatzbereit“); nach
    einem direkt geöffneten Link zu „Suchen“. Umgesetzt: Die Bestätigung bleibt auf „Suchen“ bis
    zur nächsten Eingabe stehen, auf „Prüffristen“ bis zum Verlassen der Seite, und übersteht ein
    Update beim Seitenwechsel. Hat sich das Gerät inzwischen
    geändert (jemand anderes hat gebucht), zeigt die Ansicht den neuen Stand; die eigene Auswahl
    bleibt, nicht geänderte Felder übernehmen den neuen Stand, gespeichert wird erst nach erneutem
    Tippen auf „Speichern“. Jede Buchung trägt eine `client_ref`; wird sie nach einem
    Verbindungsfehler erneut gesendet, wirkt sie nicht doppelt.
23. **Auffrischen** beim Zurückwechseln in die App höchstens alle 30 Sekunden.
24. **Enter im Suchfeld** (z. B. USB-Barcode-Scanner am PC) wirkt wie ein Scan: Ein eindeutiger
    Treffer öffnet das Gerät. Umgesetzt: zuerst Scan-Code bzw. Kennung genau, sonst genau ein
    Treffer der Suche; bei mehreren Treffern schließt sich nur die Tastatur, ohne Treffer erscheint
    „Kein Gerät mit diesem Code gefunden.“ und der Text bleibt im Suchfeld.
25. **Prüffristen:** Antippen eines Geräts öffnet die Umbuchen-Ansicht. Umgesetzt: zwei
    Abschnitte „Überfällig“ und „In den nächsten 30 Tagen fällig“ mit Anzahl, sortiert nach
    Prüfdatum (am stärksten überfällig zuerst). Je Gerät die Karte wie bei „Suchen“, darunter
    „Prüfung: 01.10.2026 · seit 28 Tagen überfällig“ (rot) bzw. „· heute fällig“, „· morgen
    fällig“, „· in 4 Tagen fällig“ (gelb), wie im Mockup „AdminHandy“. Berechnet aus der geladenen
    Geräteliste (nach einer eigenen Buchung sofort aktuell, „heute“ in der Ortszeit des Handys);
    bei mehr als 2.000 Geräten vom Server (höchstens 200 je Abschnitt). Geräte ohne Prüfdatum
    erscheinen hier nicht.
26. **Komprimierung:** STRATO komprimiert die Ausgabe nicht (live geprüft). Der Build erzeugt daher
    komprimierte Fassungen aller Dateien (`.gz`, ggf. `.br`); PHP liefert sie aus, wenn
    `Accept-Encoding` es erlaubt (`Content-Encoding`, `Vary: Accept-Encoding`, ETag je Fassung).
    Umgesetzt: Brotli und gzip für alle Skripte und Stile (auch Service Worker), je nur, wenn die
    Fassung kleiner ist; Schriften sind schon komprimiert, die App-Seite ist klein und wird von PHP
    angepasst. Der Build schrumpft beim Laden von rund 350 KB auf rund 115 KB (Brotli). Browser
    ohne Brotli bekommen gzip, Programme ohne `Accept-Encoding` die Datei unkomprimiert.
27. **Kamera-Scanner:** `zxing-wasm` (nur Lesemodul, MIT); immer dieselbe Bibliothek, auch wo der
    Browser einen eigenen `BarcodeDetector` hat (gleiche Ergebnisse bei GS1-Trennzeichen). Die
    WASM-Datei liegt im Build (kein CDN); CSP nur `script-src 'wasm-unsafe-eval'`. Umgesetzt:
    Lesemodul und WASM-Datei (rund 950 KB, komprimiert rund 320 KB) laden erst beim ersten Öffnen
    des Scanners; danach liegt die Datei im Cache des Browsers (Name mit Hash). Gelesen werden alle
    Formate, der Inhalt unverändert (GS1 mit Trennzeichen GS wie der Scan-Code im Backend). In den
    Service-Worker-Cache kommt sie erst mit dem Offline-Modus.
28. **Später** (sehr große Bestände bzw. Offline-Abschnitt): genauer Filter für die Kennung und
    `ids=` in `GET devices`, Laden „geändert seit“ statt über `offset`.

## Noch offen (Praxis, vor dem Livegang)
- Was steht auf den Hersteller-Barcodes? (Dazu T7 in `docs/TODO-SPAETER.md`: GS1 mit und ohne
  überflüssiges GS gleich behandeln.)
- Handy-Ansichten auch im Dunkelmodus entwerfen (Farben für Dunkel: siehe Mockup „Einstellungen“).

## Später (nicht Version 1)
- Rückfrage und Antwort in der App statt per E-Mail.
- Web Push (iPhone ab iOS 16.4, nur bei installierter App; Server per Composer-Bibliothek,
  durch Ereignisse ausgelöst, keine Cronjobs).
