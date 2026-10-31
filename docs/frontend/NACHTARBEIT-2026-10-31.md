AUFTRAG NACHTARBEIT – 31.10. ab jetzt bis 01.11. 06:00 Uhr. Holger ist bis morgen früh NICHT erreichbar und kann nichts bestätigen.

## 0. SOFORT: alle Freigaben anstoßen (Holger bestätigt jetzt einmalig)
Bevor du etwas anderes tust, löse nacheinander jede Aktion aus, die eine Bestätigung brauchen könnte:
1. Browser: Tab-Übersicht holen; neuen Tab https://www.prodad.de/firefighting/test/dashboard; Seitentext lesen; Screenshot; per JavaScript document.documentElement.dataset.build lesen; Klick auf „Geräte“ im Menü; danach https://www.prodad.de/firefighting/service/ öffnen (nur lesen).
2. Shell: git status, git fetch, git push --dry-run, gh run list --limit 1, gh run view <id des letzten Laufs>, ssh -T strato-ff (Deploy des aktuellen Stands, ändert nichts), curl auf https://www.prodad.de/firefighting/test/build.json und …/service/api/v1/health, in frontend/: npm run lint, npx playwright test --list, dazu wsl -e true und composer --version.
3. Datei docs/frontend/NACHTARBEIT-2026-10-31.md anlegen: dieser Auftrag wörtlich, darunter „Fortschritt“ und „Zur Prüfung durch Holger“ (leer). Committen, pushen (nur .md, keine CI).
4. Melden: „⏳ ZWISCHENSTAND – alle Freigaben erledigt, ab jetzt arbeite ich ohne Rückfragen“ und SOFORT weiterarbeiten.

## 1. Regeln für die Nacht (gehen den RÜCKFRAGE-Regeln in CLAUDE.md vor)
- Keine Rückfragen, kein Anhalten nach einem Schritt: Nach jedem abgeschlossenen Schritt direkt den nächsten beginnen. Nur am Ende (Abschnitt 5) eine Schlussmeldung.
- Entscheidungen, die du sonst fragen würdest: die Lösung wählen, die am besten zu KONZEPT.md und den Mockups passt und sich am leichtesten wieder ändern lässt. In KONZEPT.md als „Vorläufig (Agent, Nacht 31.10.): …“ eintragen und in der Nachtarbeit-Datei unter „Zur Prüfung durch Holger“ sammeln.
- Was laut CLAUDE.md eine Rückfrage braucht (Migrationen mit UPDATE/DELETE/DROP/Umbenennen, deploy.sh, Deploy-Job, Sicherungen, echte Städte): NICHT tun. Unter „Zur Prüfung durch Holger“ notieren, einen Weg ohne diese Änderung wählen oder den Teil überspringen. Erweiternde API-Änderungen und rein ergänzende Migrationen sind erlaubt (mit UEBERGABE-BACKEND.md und composer test).
- Fortschritt: Nach jedem Schritt Eintrag in der Nachtarbeit-Datei (Uhrzeit, Commit-Betreff, CI-Dauer, Build, live ☑/☒, offen für Holger), im Commit des Schritts mit. Nach einer Zusammenfassung des Kontexts zuerst diese Datei lesen und dort weitermachen.
- CI rot nach 3 Reparaturversuchen: die Commits dieses Schritts mit git revert zurücknehmen (kein --force), pushen, grün abwarten, deployen, im Bericht festhalten, mit dem nächsten unabhängigen Schritt weiter.
- Deploy gescheitert oder Health nicht ok: nichts zurückspielen, sofort anhalten (⛔ ABGEBROCHEN). Das ist der einzige Grund, vor dem Ende anzuhalten.
- Hängt etwas (Browser antwortet nicht, Befehl überschreitet die Zeitgrenze): höchstens 2 neue Versuche, dann den Punkt als offen markieren und weiter.
- Browser: nur Test-Stadt. Unter /service/ NUR LESEN: keine Stadt anlegen, umbenennen oder sperren, keine Einladung, keinen Super-Admin sperren. Keine Passwörter, nicht abmelden. Fehlt eine Anmeldung: Live-Punkt offen lassen, weiter.
- Kontext sparen: lange Ausgaben (Testläufe, Logs) in Dateien umleiten und nur Zusammenfassung/Fehler lesen.
- Uhrzeit mit date prüfen. Ab 05:30 Uhr keinen neuen Schritt beginnen. Den laufenden Schritt nur abschließen, wenn CI, Deploy und Live-Prüfung bis 06:00 sicher reichen; sonst nicht pushen und im Bericht vermerken.

## 2. Teil A: Super-Admin-Bereich
Grundlagen: KONZEPT.md „Rollen und Rechte“ und „Bereiche des Super-Admins“, Mockup docs/frontend/mockup/SuperAdmin.dc.html, API in CLAUDE.md „Städte verwalten und Admins einladen“.
- Zuerst den Plan mit Schritten und nummerierten Entscheidungen in KONZEPT.md (wie bei der Admin-Konsole), dann Schritt für Schritt umsetzen.
- Umfang:
  - Anmeldung unter /firefighting/service/ (prüfen, was es gibt).
  - Städte-Liste mit Status und Zählern.
  - „Neue Stadt“: Kürzel aus dem Namen vorgeschlagen, Umlaute umgewandelt, Prüfung beim Tippen über tenants/slug-available.
  - Stadtdetail: umbenennen, sperren/entsperren mit Rückfrage, Admin einladen, Einladung erneut senden, Benutzer nur ansehen.
  - Super-Admins: sperren/entsperren, geschütztes Konto ohne Knopf.
  - Keine Geräte, kein Wechsel in eine Stadt.
- Tests: Unit- und Browser-Tests mit API-Attrappe wie bisher; Konten einer Stadt sehen keine Seiten des Super-Admins.
- Live: nur lesend (Liste, Detail der Test-Stadt, Super-Admins), falls eine Super-Admin-Sitzung im Browser besteht. Alle schreibenden Prüfungen als offene Liste in docs/frontend/LIVETEST-SUPER-ADMIN.md für Holger.

## 3. Teil B: Offline-Modus
Grundlagen: KONZEPT.md „Offline-Modus“; API in CLAUDE.md „Offline-Buchungen (API)“ und client-config; das Backend ist fertig.
- Zuerst den Plan mit Schritten und Entscheidungen in KONZEPT.md. Jeder Schritt lässt die App online voll funktionsfähig wie bisher; nach keinem Deploy ist etwas halb fertig sichtbar.
- Vorschlag für die Reihenfolge:
  1. Sitzung offline behalten (nur eine echte 401 beendet sie), grauer Streifen „offline“, lokale Kopie der Daten (IndexedDB) für Suchen, Gerät und Prüffristen.
  2. Warteschlange für Umbuchen/Status (client_ref, occurred_at), automatisch senden bei Verbindung, Uhr-/Haken-Symbol je Buchung.
  3. Ergebnis je Buchung in der App (übernommen, überschrieben, nur Verlauf, abgelehnt). Grenzen aus client-config: Daten älter als max_age_days → offline buchen gesperrt („Daten zu alt …“); Buchung älter als queue_max_age_days → je Buchung „trotzdem senden oder verwerfen?“ (confirmed_stale); clock_implausible verständlich melden.
  4. Abmelden löscht alle lokalen Daten des Kontos, mit deutlicher Warnung bei noch nicht gesendeten Buchungen.
- Tests: Unit-Tests für Warteschlange und Regeln; Playwright mit context.setOffline und fester Uhr. Dateien mit Service Worker/Offline in allen fünf Profilen und unter WSL.
- Live: Online-Verhalten unverändert prüfen (Umbuchen eines Testgeräts M90–M99, Ergebnis, zurücksetzen; Warteschlange leer). Echtes Offline kann der Browser nicht: Liste docs/frontend/LIVETEST-OFFLINE.md für Holger (iPhone im Flugmodus).

## 4. Nächtlicher CI-Lauf
Nach 03:20 Uhr: gh run list --workflow=ci.yml --event=schedule --limit=1. Rot: vor dem nächsten Schritt Ursache untersuchen und beheben (Regeln wie oben). Kein Lauf vorhanden: im Bericht vermerken.

## 5. Schluss (06:00 Uhr oder wenn A und B fertig sind)
- Nichts aus docs/TODO-SPAETER.md vorziehen; das planen wir gemeinsam.
- Bleibt Zeit: Testlücken der neuen Teile schließen, Doku nachziehen.
- Dann EINE Schlussmeldung „✅ FERTIG – ich halte an“:
  - je Schritt: Commit, CI-Dauer, Reparaturen/Reverts, Build, live ☑/☒;
  - „Zur Prüfung durch Holger“: vorläufige Entscheidungen, übersprungene Punkte;
  - offene Live-Punkte: iPhone, Mails, /service/ schreibend, Offline;
  - Ergebnis des nächtlichen CI-Laufs.
- Dieselbe Übersicht steht am Ende der Nachtarbeit-Datei.

---

# Fortschritt

- 31.10. 15:57 – Schritt 0 (Freigaben): Browser (Dashboard der Test-Stadt, Build `9db091bee881`,
  Geräte, `/service/` lesend: Super-Admin-Sitzung vorhanden, dort bisher nur Platzhalter-Dashboard),
  Shell (git, gh, Deploy ohne neue Commits „Deployment erfolgreich“, Health ok, Lint grün,
  638 Browser-Tests gelistet, WSL und Composer vorhanden). Commit „Nachtarbeit 31.10.: Auftrag und
  Fortschritt“ (nur `.md`, keine CI).

- 31.10. 16:25 – Teil A geplant: KONZEPT.md „Super-Admin – Entscheidungen“ 39–47 (Schritte S1–S5).
- 31.10. S1 Super-Admin: Menü, Dashboard, Städte-Liste, Einstellungen. Commit „Super-Admin S1:
  Menü, Dashboard, Städte-Liste und Einstellungen“. Lokal: Lint, Typprüfung, 1100 Unit-Tests,
  Browser-Tests (6 Dateien Chromium/WebKit 171, `superAdmin.spec.ts` alle fünf Profile 50) grün.
  CI 5:06 grün (Browser-Tests in 8 Teilen, PHP übersprungen), Deploy ok, Build `c99efc2b76c4`,
  Health ok. Live (lesend, Super-Admin-Sitzung): ☑ Dashboard (2 aktiv, 0 gesperrt, 0 Einladungen,
  5 Benutzer, Städte-Tabelle), ☑ Städte, ☑ Einstellungen, ☑ keine Konsolenfehler.
  Fund: Der seit Schritt 0 offene Tab zeigte beim Öffnen „Die neue Version konnte nicht geladen
  werden“ (Zeitleiste: register() kam erst nach 6,7 s, der Start gab ohne Registrierung sofort auf).
- 31.10. Korrektur Updates: Commit „Updates: Start wartet auf eine späte Registrierung des Service
  Workers“. Unit-Tests (2 neu), `appUpdate.spec.ts`/`serviceWorker.spec.ts` in allen fünf Profilen
  und unter WSL grün.
- 31.10. S2 „Neue Stadt“: Commit „Super-Admin S2: Neue Stadt mit Kürzel-Vorschlag und Prüfung“.
  Lokal: Lint, Typprüfung, Unit-Tests, `superAdmin.spec.ts` alle fünf Profile grün. Schreibend live
  nicht geprüft (nur Test-Stadt erlaubt) → `LIVETEST-SUPER-ADMIN.md`.
  CI (Korrektur + S2) 5:24 grün, Deploy ok, Build `1e0bc9a58faa`, Health ok. Live: ☑ „Neue Stadt“
  Prüfung beim Tippen („Feuerwehr Test“ → `test` vergeben, „… Probe Süd“ → `test-probe-sued` frei),
  abgebrochen ohne Anlegen; ☑ keine Konsolenfehler. Korrektur Updates: Der Fund trat beim Wechsel
  auf diese Version noch einmal auf (der alte Code lief noch); wirksam erst ab dem nächsten Deploy.
- 31.10. S3 Stadtdetail: Commit „Super-Admin S3: Stadtdetail mit Umbenennen, Sperren und
  Einladungen“. Lokal: Lint, Typprüfung, 1108 Unit-Tests, `superAdmin.spec.ts` alle fünf Profile
  (120) grün.

# Zur Prüfung durch Holger

- Entscheidung 40: keine Zähler im Menü des Super-Admins.
- Entscheidung 41: Ablaufzeit einer Einladung („läuft in 6 Std. ab“) wird nicht angezeigt (API kennt
  sie nicht), stattdessen „Ein Einladungslink gilt 24 Stunden ab dem Senden.“; „eingeladen seit“ ist
  das Anlagedatum des Kontos (ein erneutes Senden ändert es nicht).
- Entscheidung 43: Kürzel-Vorschlag ohne „Freiwillige Feuerwehr“, „Feuerwehr“, „FF“, „Stadt“,
  „Gemeinde“ am Anfang.
- Das Konto-Feld „(Super-Admin)“ des alten Platzhalter-Dashboards entfällt; Abmelden steht im Menü.
