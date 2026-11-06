# Live-Test Phase 1: Rollen und Rechte

Nur in der Test-Stadt `https://www.prodad.de/firefighting/test/`, nie in echten Städten. Plan:
[`../PLAN-PHASE-1-ROLLEN.md`](../PLAN-PHASE-1-ROLLEN.md). Je Schritt wird ergänzt, was live geprüft
ist (☑ bestanden, ☒ Fehler, ☐ offen für Holger). Der vollständige Durchlauf aller Rollen steht unter
Schritt 1.8 (unten).

## Schritt 1.1 – Schema und Standard-Rollen (05.11.2026, Build `f054b88c6ce2`)

Migrationen 0029–0035 auf dem Server ausgeführt (mit Sicherung vor der Migration). Sichtbar ändert
sich nichts; geprüft wird, dass alles wie vorher läuft.

- ☑ Deploy endet mit „Deployment erfolgreich“, 7 Migrationen ausgeführt, `build.json` =
  `f054b88c6ce2`, `health` = `{"status":"ok","database":"ok"}`.
- ☑ `GET api/v1/tenant` der Test-Stadt unverändert (Name, Farbe, Logo).
- ☑ Benutzerverwaltung (`benutzer`) lädt alle Konten mit Rolle und Status wie vorher
  (`fe_users` hat die neue Spalte `role_id`).
- ☑ Geräteliste (`geraete`) lädt („69 Geräte“, „50 von 69 angezeigt“), keine Fehler in der Konsole.
- ☐ Neue Stadt anlegen erhält die Rollen *Admin* und *Benutzer*: nur per Test geprüft
  (`RoleRepositoryTest`, `DefaultRolesMigrationTest`); live keine neue Stadt angelegt (Städte werden
  nie gelöscht). Sichtbar erst mit Schritt 1.5 (Reiter „Rollen“).

## Schritt 1.2a – Schreibwege setzen `role_id`, Probelauf (05.11.2026, Build `2b71cb8a9709`)

Ohne Migration; bestehende Konten bleiben unverändert, nur neu angelegte oder geänderte Konten
erhalten ihre Standard-Rolle.

- ☑ Deploy „Deployment erfolgreich“, „Keine offenen Migrationen.“, `build.json` = `2b71cb8a9709`,
  `health` ok.
- ☑ Benutzerverwaltung lädt alle Konten wie vorher, keine Fehler in der Konsole.
- ☐ Rolle wechseln bzw. Freischalten live: nicht geprüft (ein Rollenwechsel beendet heute alle
  Sitzungen des Kontos, auch auf Holgers Geräten); per Test `UserRoleAssignmentTest`.
- ☐ Probelauf `php85 bin/role-assignment-check.php` auf dem Server bzw. einer Kopie: offen für Holger
  (siehe Rückfrage zu Migration 0036).

## Schritt 1.2b – Migrationen 0036–0038 (05.11.2026, Build `a9dde15b667a`)

Vorher Probelauf durch Holger auf dem Server (06:39): 7 Konten bekämen eine Rolle, keines bliebe
ohne, 0037 läuft durch. Deploy mit Sicherung `ff-db-20261105-054801Z-vor-migration-cf326db.sql.gz`,
`0036` (131 ms), `0037` (586 ms), `0038` (871 ms) OK.

- ☑ Deploy „Deployment erfolgreich“, 3 Migrationen ausgeführt, `build.json` = `a9dde15b667a`,
  `health` ok.
- ☑ Anmeldung: bestehende Sitzung gilt weiter (`auth/me` 200, Seiten laden ohne neue Anmeldung);
  Anmeldeseite wird ausgeliefert (`<base href="/firefighting/test/" />`), `auth/login` antwortet
  (leere Anfrage → 400). Anmelden mit Passwort selbst: ☐ offen für Holger (Agenten geben keine
  Passwörter ein).
- ☑ Benutzerverwaltung, Reiter „Konten“: alle 5 Konten mit Rolle und Status wie vorher (`users` 200).
- ☑ Einladungscodes: Liste lädt (`invitation-codes` 200); Code „Live-Test 1.2 (wird widerrufen)“
  (1 Tag, 1 Registrierung) angelegt – Code, QR und Aushang erscheinen – und sofort widerrufen
  („… ist widerrufen“, unter „Abgelaufene, aufgebrauchte und widerrufene“ jetzt 3). Keine Fehler in
  der Konsole.
- ☐ Erneuter Probelauf `php85 bin/role-assignment-check.php` auf dem Server: erwartet 0 zuzuordnen,
  7 mit Rolle (Holger).
- ☐ Registrieren mit Code, Freischalten, Rolle wechseln live: Holger (neue Konten erhalten ihre Rolle;
  per Test `UserRoleAssignmentTest`, der CHECK `chk_fe_users_role_id` lehnt jedes Konto ohne Rolle ab).

## Schritt 1.3a – Rechte im Backend (05.11.2026, Build `4ac60bad2b5e`)

Routen unter Städten prüfen die Rechte der Rolle statt `fe_users.role`. Erwartet: für *Admin* und
*Benutzer* keine sichtbare Änderung.

- ☑ Deploy „Deployment erfolgreich“, „Keine offenen Migrationen.“, `build.json` = `4ac60bad2b5e`,
  `health` ok.
- ☑ Als *Admin* (bestehende Sitzung): alle lesenden Routen 200 (`auth/me`, `auth/sessions`,
  `client-config`, `auth/notifications`, `invitation-codes`, `users`, `device-types`, `locations`,
  `devices`, `devices/{id}`, `devices/{id}/events`, beide Exporte, `device-events`, Vorlage und gültige
  Werte des Imports, `device-summary` mit `notices`, `inspection-due`, `notices`).
- ☑ Als *Admin*: Dashboard (mit „Offene Hinweise 2“), Prüffristen, Protokoll und Geräteliste laden,
  keine Fehler in der Konsole.
- ☑ Als *Admin* mit Feldregeln: M90 Status „in Wartung“ und zurück auf „einsatzbereit“ (`POST
  devices/{id}/state`, beide 200 `applied`), Modell von M90 geändert und auf den alten Wert
  zurückgesetzt (`POST devices/{id}/fields`, 200).
- ☐ Als *Benutzer*: braucht eine Anmeldung mit Passwort (Holger). Erwartet wie bisher: Suchen,
  Umbuchen, Status, Prüffristen gehen; Konsole bzw. Admin-Routen 403 (per Test
  `TenantRoutePermissionsTest` für jede Route).

## Schritt 1.3b – `auth/me` mit Rechten, Sitzungsdauer, Log-Mails (05.11.2026, Build `73499da67883`)

Vorher zweiter Probelauf zu 0036 durch Holger: 0 zuzuordnen, 7 mit Rolle, 0 ohne Rolle, Super-Admin
ohne Rolle. Deploy mit Sicherung `ff-db-20261105-065636Z-vor-migration-e047623.sql.gz`, `0039`
(357 ms) und `0040` (475 ms) OK. Erwartet: für *Admin* und *Benutzer* keine sichtbare Änderung.

- ☑ Deploy „Deployment erfolgreich“, 2 Migrationen ausgeführt, `build.json` = `73499da67883`,
  `health` ok.
- ☑ Als *Admin* (bestehende Sitzung): `auth/me` 200 mit `permissions`: alle 9 Bereiche (`dashboard`
  und `search` `read`, sonst `write`), alle 9 Aktionen, `restrictions` leer, `filtered` false,
  `version` 64 Hex-Zeichen und bei erneuter Abfrage gleich.
- ☑ Sitzungsdauer *Admin* unverändert: Höchstdauer der Sitzung (`expires_at` − `created_at`) 30 Tage;
  die bestehende Sitzung gilt weiter (keine neue Anmeldung nötig).
- ☑ `auth/sessions`, `auth/notifications`, `client-config`, `users`, `device-summary`, `devices`,
  `device-events`, `notices`, `inspection-due` je 200.
- ☑ Benachrichtigung „Log“ ein- und wieder ausgeschaltet (`POST auth/notifications` true → false, je
  200; Stand danach wie vorher: aus). Die neue CHECK nimmt den Wert an.
- ☑ Dashboard, Einstellungen, Benutzer, Geräte, Protokoll laden, keine Fehler in der Konsole.
- ☐ Als *Benutzer*: braucht eine Anmeldung mit Passwort (Holger). Erwartet: `permissions` mit
  `search`/`due` `read`, `book.move`, `book.status`; Sitzung 90 Tage (Cookie `Max-Age` 7776000).
- ☐ Log-Mails tatsächlich empfangen (Holger, Postfach): erwartet unverändert für Admins mit
  eingeschalteter Option; per Test `UserRepositoryTest` gegen die alte Abfrage.

## Schritt 1.4 – Datenfilter in allen Abfragen und Mails (05.11.2026, Build `3e778842474e`)

Ohne Migration. Erwartet: für *Admin* und *Benutzer* (ohne Datenfilter) keine sichtbare Änderung,
neu nur das Feld `selectable` der Standorte (immer true).

- ☑ Deploy „Deployment erfolgreich“, „Keine offenen Migrationen.“, `build.json` = `3e778842474e`,
  `health` ok.
- ☑ Als *Admin*: `auth/me` mit `filtered` false; `devices` 69 Geräte (wie vorher), `locations`
  9 Standorte, alle `selectable` true, Summe `device_count_total` 69; `device-types` mit Zahlen
  (Atemschutzmaske 25, Funkgerät 20, Atemschutzgerät 15, Live-Testtyp 2 5, Wärmebildkamera 4);
  `device-summary` 69 Geräte, 2 offene Hinweise; `inspection-due` 11 überfällig; `device-events`
  147 Einträge; `notices` 3; beide Exporte 200; M90 mit Verlauf (15 Einträge). Nirgends „anderer
  Standort“.
- ☑ Schreiben: M90 Status „in Wartung“ und zurück auf „einsatzbereit“ (je 200 `applied`, Stand
  danach wie vorher).
- ☑ Geräte, Standorte, Gerätetypen, Protokoll, Prüffristen und Gerätedetail laden, keine Fehler in
  der Konsole.
- ☐ Als *Benutzer*: Anmeldung mit Passwort (Holger). Erwartet wie bisher.
- ☐ **Für Schritt 1.5 offen: echter Live-Test mit einer gefilterten Rolle** in der Test-Stadt, sobald
  der Editor Rollen mit „Sieht nur …“ anlegen kann: Liste, Suche, Scan, Gerätedetail und Verlauf
  („anderer Standort“), Dashboard-Zahlen, Prüffristen, Protokoll und Hinweise, Exporte, Standortwahl
  (Standort der Ebene 1 nur mit Fächern nicht wählbar), Buchen auf Ziel außerhalb (400), Gerät
  außerhalb (404), Log-Mails eines gefilterten Empfängers, leerer Filter lässt sich nicht speichern.
  Bis dahin per Test (`DeviceVisibilityMatrixTest`, Mail-Tests).

## Schritt 1.5a – Rollen-API des Super-Admins (05.11.2026, Build `2cb1a11ff861`)

Freigabe Holger nur für 1.5: unter `/service/` ausschließlich Rollen der Test-Stadt mit „Live-Test“
im Namen anlegen, bearbeiten, kopieren, übernehmen und löschen; am Ende wieder gelöscht.

- ☑ Deploy „Deployment erfolgreich“, „Keine offenen Migrationen.“, `build.json` = `2cb1a11ff861`,
  `health` ok; `permission-catalog` ohne Sitzung 401.
- ☑ Lesend als Super-Admin: Katalog (9 Bereiche, 4 Gruppen), Rollen der Test-Stadt (Admin mit 3
  Konten, Benutzer mit 2 Konten und 3 offenen Codes), Auswahl für den Datenfilter (9 Standorte der
  Ebene 1, 5 Typen).
- ☑ Per API: „Live-Test 1.5a“ anlegen (201, Version 1, Standortfilter), speichern (Version 2),
  veraltete Version → 409 `version_conflict` mit `details.role`, `users` mit Filter → 409 F3
  (`details {areas: [users]}`), leerer Standortfilter → 400 mit Meldung, kopieren (Filter bleibt),
  aus Immendingen übernehmen (nur gelesen; Rechte ja, Filter null), Admin löschen → 409, Benutzer
  löschen → 409, alle drei Live-Test-Rollen gelöscht (200). Danach nur Admin und Benutzer.

## Schritt 1.5b – Seiten „Rollen“ und „Rolle bearbeiten“ (05.11.2026, Build `115ba916adc7`)

- ☑ Deploy „Deployment erfolgreich“, `build.json` = `115ba916adc7`, `health` ok; die offene Seite
  lud die neue Version selbst („Neue Version wird geladen …“).
- ☑ Stadtdetail Test-Feuerwehr mit Reitern „Übersicht“, „Admins und Benutzer“, „Rollen“; Tabelle:
  Admin „fest“, „alles“, nur „Ansehen“; Benutzer „Standard“, „Suchen, Termine, Profil“, „Umbuchen,
  Status setzen“, ohne „Löschen“.
- ☑ „Neue Rolle“ „Live-Test 1.5b“: „Umbuchen“ grau mit „braucht …“, nach „Suchen: Ansehen“ wählbar;
  „Nur ausgewählte“ ohne Auswahl zeigt die Meldung, mit einem Standort weg; Vorschau „Keine Konsole –
  nur die Handy-Seiten.“, „Suchen · Profil“, „1 von 20 Standorten“; gespeichert, zurück zur Liste mit
  „Rolle „Live-Test 1.5b“ angelegt.“, Zeile „1 Standort, alle Ausrüstungstypen“, 0 Personen.
- ☑ F3: mit „Nur ausgewählte“ sind „Benutzer: Ansehen/Ändern“ und „CSV-Import“ grau („Mit „Sieht nur
  …“ kein Zugriff.“); erst „Benutzer: Ansehen“, dann Filter → Hinweis „darf die Rolle nicht
  verwalten: „Benutzer: Ansehen““, „Speichern“ → „Nicht gespeichert …“, keine Anfrage.
- ☑ Bearbeiten und speichern (Termine dazu): Version 2, Bestätigung „gespeichert“; „Abbrechen“ ändert
  nichts. „Die Änderung gilt sofort für 0 Personen.“
- ☑ Versionskonflikt: Editor offen, Rolle per API geändert, „Speichern“ → Hinweis „inzwischen
  geändert“, das Formular zeigt den neuen Namen.
- ☑ „Kopieren“ öffnet „Live-Test 1.5b (Kopie)“ mit Bestätigung und Standortfilter; „Rolle löschen …“
  mit Rückfrage, zurück zur Liste mit „gelöscht“.
- ☑ „Rolle aus anderer Stadt übernehmen …“: Stadt (nur andere Städte), Rolle „Benutzer“ aus
  Immendingen (nur gelesen), Name „Live-Test 1.5b Übernahme“ → Editor mit Hinweis „nur die Rechte“,
  „Alle Standorte“; danach gelöscht.
- ☑ „Live-Test 1.5b B löschen …“ in der Liste mit Rückfrage; danach nur Admin und Benutzer.
- ☑ Admin „Ansehen“: „Rolle ansehen“, Hinweis „lässt sich nicht ändern“, kein „Speichern“, alle
  Felder gesperrt. Keine Fehler in der Konsole.
- ☒ → behoben: Die Vorschau zeigte für Suchen und Termine (Rechte von *Benutzer*) „Termine“ als
  Konsole und „Dashboard“ in der Leiste; heute hat *Benutzer* nur die Handy-Seiten (F8). Korrektur:
  Suchen und Termine allein sind keine Konsole (Commit „Rollen: Vorschau ohne Konsole bei Suchen und
  Termine (Schritt 1.5b)“, Build `0b5260654530`): ☑ live, Rolle *Benutzer* nur geöffnet, nicht
  gespeichert: „Keine Konsole – nur die Handy-Seiten.“, „Suchen · Termine · Profil“.
- ☐ Schmale Breiten und iPhone (Tabelle als Karten, Editor): offen für Holger; per Browser-Test in
  allen fünf Profilen ohne waagerechten Überstand.
- ☐ **Offen für Schritt 1.6:** Wie eine Rolle mit Filter in der App wirkt, lässt sich erst live
  prüfen, wenn Rollen Konten zugewiesen werden (Liste, Suche, Scan, Verlauf mit „anderer Standort“,
  Dashboard, Prüffristen, Protokoll, Exporte, Standortwahl, Buchen auf Ziel außerhalb 400, Gerät
  außerhalb 404, Log-Mails eines gefilterten Empfängers). Bis dahin per Test
  (`DeviceVisibilityMatrixTest`, Mail-Tests).

## Schritt 1.6 – Rollen zuweisen und Einladungscodes mit Rolle (05.11.2026, Build `f4023178d8aa`)

Rolle „Live-Test Filter“ (Test-Stadt, Suchen: Ansehen, Umbuchen, Status setzen; sieht nur HLF 20 und
Atemschutzmaske) für den Test; Testkonto #7 „Holger Test 1“ (vorher *Benutzer*).

- ☑ Deploy „Deployment erfolgreich“, `build.json` = `f4023178d8aa`, `health` ok.
- ☑ Benutzerverwaltung als *Admin*: Rollenfilter „Alle (5) · Admin (3) · Benutzer (2)“, Spalte
  „Rolle“. Stift an #7: Rollenwahl mit Beschreibung, „Sieht: …“ und „Sieht nur: HLF 20;
  Atemschutzmaske“; nach Wahl „Live-Test Filter“ der Hinweis „Die neue Rolle gilt beim nächsten Aufruf
  der App.“; gespeichert („Holger Test 1 hat jetzt die Rolle „Live-Test Filter“ …“), `users/7` mit
  `role_id` 14, Kontoart `user`. Filter jetzt „Benutzer (1) · Live-Test Filter (1)“, Knopf „Live-Test
  Filter“ zeigt nur #7. Keine Fehler in der Konsole.
- ☑ Einladungscodes: „Neuer Einladungscode“ bietet nur *Benutzer* und „Live-Test Filter“ (ohne
  *Admin*, mit Hinweis); Code „Live-Test 1.6 (wird widerrufen)“ (1 Tag, 1 Registrierung, Rolle
  „Live-Test Filter“) erzeugt – Code und QR erscheinen, Spalte „Rolle nach Freischaltung“ = „Live-Test
  Filter“, `invitation-codes` mit `role {id: 14}` – und sofort widerrufen („… ist widerrufen“; Nutzung
  der Rolle danach 1 Konto, 0 Codes).
- ☑ „Termine: Ansehen“ an „Live-Test Filter“ im Editor des Super-Admins ergänzt: „Rolle „Live-Test
  Filter“ gespeichert.“, Version 2, Bereiche `search`/`due` `read`, Filter unverändert (ein erster
  Versuch von Hand war nicht gespeichert worden, Version blieb 1).
- ☑ **Wirkung des Filters in der App mit #7** (angemeldet als #7 von Holger, nach Neuladen; alles wie
  erwartet):
  - ☑ Suchen: 10 Geräte (M1–M6, M8, M9, M31, M32).
  - ☑ M7 (Atemschutzmaske im Lager Gerätehaus, auch per Scan-Code `ARFK-1007`) und HRT 1 (Funkgerät
    auf HLF 20) nicht auffindbar.
  - ☑ Prüffristen: überfällig M1, M2; in den nächsten 30 Tagen fällig M8.
  - ☑ Standortwahl: nur HLF 20 mit seinen Fächern.
  Damit ist der offene Punkt „echter Live-Test mit einer gefilterten Rolle“ aus 1.4/1.5b für Suchen,
  Scan, Prüffristen und Standortwahl erledigt; Verlauf mit „anderer Standort“, Exporte, Log-Mails
  eines gefilterten Empfängers sowie Buchen auf ein Ziel bzw. Gerät außerhalb (400/404) bleiben
  per Test (`DeviceVisibilityMatrixTest`, Mail-Tests) und folgen im Durchlauf von Schritt 1.8.
- ☑ Aufgeräumt: #7 über den Stift wieder *Benutzer* („… hat jetzt die Rolle „Benutzer“ …“,
  `role_id` 4), „Live-Test Filter löschen …“ mit Rückfrage („gelöscht“); danach nur *Admin*
  (3 Konten) und *Benutzer* (2 Konten, 3 Codes). Keine Fehler in der Konsole.

## Schritt 1.7a – Aktion `csv.export` für die Exporte (05.11.2026, Build `47480b85b88d`)

- ☑ Deploy „Deployment erfolgreich“, `build.json` = `47480b85b88d`, `health` ok; `devices/export`
  ohne Sitzung 401.
- ☑ Als *Admin* in `test`: `auth/me` nennt `csv.export` unter `permissions.actions`; `GET
  devices/export` 200 (Kopfzeile wie bisher, 69 Geräte), `GET device-events/export` 200.
- ☑ Oberfläche als *Admin*: Geräteliste „Export (CSV)“ ergibt `geraete-test-2026-11-05.csv`,
  Protokoll „Verlauf herunterladen (CSV)“ ergibt `historie-test-2026-08-08-bis-2026-11-05.csv`
  (Blob im Browser abgefangen, nichts heruntergeladen).
- ☑ `GET roles` als *Admin*: Katalog mit `csv.export` in „Mehrere Ausrüstungen“; die Standard-Rolle
  *Benutzer* hat nur `book.move`, `book.status`.
- ☑ Editor des Super-Admins („Neue Rolle“ für die Test-Feuerwehr, nichts gespeichert): Kästchen
  „CSV-Export“ unter „Mehrere Ausrüstungen“, ohne Bereich grau mit „braucht „Ausrüstungsliste:
  Ansehen““. Der Tab lief zuerst wegen der Sperre gegen Neuladeschleifen (10 Minuten je Tab) noch
  mit Build `f4023178d8aa` und zeigte „Neue Version verfügbar.“; nach „Jetzt aktualisieren“ Build
  `47480b85b88d`, gleiches Ergebnis (wie vorgesehen).
- ☐ Offen für Holger: *Benutzer* (#7) erhält beim Export 403 – per Test belegt
  (`TenantRoutePermissionsTest::testStandardRoleUserGetsForbiddenOnExports`); die App bietet *Benutzer*
  keinen Export an. Kommt in die iPhone-Liste nach 1.7c.

## Schritt 1.7b – App nach Rechten (05.11.2026, Build `336343662d28`)

- ☑ Deploy „Deployment erfolgreich“, `build.json` = `336343662d28`, `health` ok.
- ☑ Update: Der Tab hatte noch eine ältere Zwischenversion; nach deren Übernahme sperrte die Sperre
  gegen Neuladeschleifen (10 Minuten je Tab) das zweite Neuladen, Hinweis „Neue Version verfügbar.“,
  nach „Jetzt aktualisieren“ Build `336343662d28` (wie vorgesehen, Zeitleiste `ff-update-trace`).
- ☑ Als *Admin* in `test`: `auth/me` mit 9 Bereichen und 10 Aktionen; die gespeicherte Sitzung
  (`ff-session:test`) enthält sie samt `version`.
- ☑ Menü unverändert (Dashboard, Suchen, Geräte, Standorte, Gerätetypen, Benutzer, Protokoll mit
  Zähler, Einstellungen); Dashboard mit allen Kacheln als Links, „Nicht einsatzbereit“ und „Letzte
  Aktivitäten“.
- ☑ Geräteliste: „Export (CSV)“, „Import (CSV)“, „Neues Gerät“, Kästchen je Zeile. Gerätedetail
  (HRT 1): „Umbuchen / Status ändern“, „Bearbeiten“, „Prüfdatum ändern“, „Code zuordnen“,
  „Aussondern“.
- ☑ Umbuchen: Standort und Status, Link „Gerätedetail (Admin)“; Standortwahl mit „Ganzes Fahrzeug,
  ohne Fach“ (ohne Speichern geschlossen).
- ☑ Einstellungen mit Anzeigemodus, Erscheinungsbild, Benachrichtigung, Passwort, Geräten; Protokoll
  mit beiden Reitern; Benutzer mit Stift und Kästchen; Standorte und Gerätetypen mit „Neu …“, Stift
  und Namen als Links; Handy-Leiste Dashboard, Suchen, Prüffristen, Profil.
- ☑ Eine eigene Rolle live ansehen (nur Handy-Seiten, Standortwahl ohne „Ganzes Fahrzeug“ bei nur
  sichtbaren Fächern): bestanden am 05.11.2026 mit der iPhone-Liste unten (B). Zusätzlich per
  Browser-Test belegt (`tests/e2e/permissions.spec.ts`).

## Schritt 1.7c – Rechte ändern sich während der Sitzung (05.11.2026, Build `08c676252d25`)

CI: Der erste Lauf brach in zwei Teilen der Browser-Tests beim Einrichten des Runners ab (Schritt
„Systempakete der Browser“, letzte Zeile `Get:54 http://azure.archive.ubuntu.com/ubuntu
noble/universe amd64 libflite1 amd64 2.2-6build3 [13.6 MB]`, dann „The operation was canceled.“);
nach einmaliger Wiederholung mit `--failed` grün.

- ☑ Deploy „Deployment erfolgreich“, `build.json` = `08c676252d25`, `health` ok, API-Antworten mit
  `X-Api-Version: 2`.
- ☑ Update: Der offene Tab lud beim Öffnen automatisch die neue Version (Zeitleiste `reload
  automatic` → `start build=08c676252d25`), vom Service Worker gesteuert.
- ☑ Als *Admin*: jede API-Anfrage sendet `X-Client-Api: 2` (`devices`, `locations`, `client-config`,
  `auth/me`, Dashboard-Abfragen); `build.json` ohne.
- ☑ Offline-Kopie in IndexedDB im Format 2 mit `permissions` = `permissions.version` der
  gespeicherten Sitzung (69 Geräte).
- ☑ Zurückkehren in die App (`visibilitychange`, sichtbar): nach mehr als 30 s genau ein `auth/me`
  und das Auffrischen der geladenen Daten; ein zweites innerhalb von 30 s fragt `auth/me` nicht.
  Rechte gleich, also nichts gelöscht, Seite bleibt. Keine Fehler in der Konsole.
- ☑ Neue Rechte bei offener App, Warteschlange mit 403: bestanden am 05.11.2026 mit der
  iPhone-Liste unten (B bis D, Konto #7). Zusätzlich per Test belegt (`permissionsChange.spec.ts`,
  `bookingQueue.spec.ts`, Browser `permissions.spec.ts`).

## Schritt 1.7d – Schnellstart (05.11.2026, Build `9de6683e1f98`)

CI grün im ersten Lauf (Commits „Schnellstart: Startbild als SVG direkt in index.html …“ und
„Live-Test: iPhone-Liste zu Schritt 1.7c …“, alle Teile von 1.7d zusammen deployt).

- ☑ Deploy „Deployment erfolgreich“, `build.json` = `9de6683e1f98`, `health` ok.
- ☑ Update: Der Tab übernahm die neue Version selbst (Zeitleiste `start build=9de6683e1f98`, danach
  vom Service Worker gesteuert).
- ☑ Als *Admin* auf `suchen`: erste Seite nach 0,09–0,10 s, Geräte aus der Offline-Kopie nach 0,10 s,
  `auth/me` und `devices` im Hintergrund; `build.json` blockiert den Start nicht mehr.
- ☑ Startbild: Die App-Seite enthält das SVG direkt (26 Formen, kein `style=`, kein `<style>`), keine
  Anfrage nach einer `.svg`, `assets/*.svg` → 404. Beim schnellen Start erscheint es nicht (sofort
  entfernt). Eine Kopie im Schritt `session` live eingefügt: sichtbar, Farben aus `splash.css`
  (dunkel: Scheibe `rgb(30, 38, 49)`, Fahrzeug `rgb(183, 28, 28)`), wieder entfernt.
- ☑ Keine Fehler und keine CSP-Meldungen in der Konsole.
- ☐ Offen für Holger: Startbild am iPhone bei langsamem Netz bzw. nach dem Schließen der App, hell
  und dunkel; Start als #7 mit gespeicherter Sitzung (fühlbar sofort, grauer Streifen nur offline).

## iPhone-Liste für Holger mit Konto #7 (nach Schritt 1.7c)

Am 05.11.2026 von Holger vollständig bestanden (iPhone als #7, PC als Admin und Super-Admin).

Fasst die offenen Punkte von 1.3a, 1.4, 1.7a, 1.7b und 1.7c zusammen. iPhone: Test-Stadt als App
(Homescreen), angemeldet als #7 „Holger Test 1“. PC: angemeldet als Admin der Test-Stadt und als
Super-Admin. Kein Rollenwechsel in dieser Liste betrifft *Admin*, #7 bleibt also immer angemeldet.

**Vorbereitung (PC, Super-Admin):** Stadtdetail „Test-Feuerwehr“ → Reiter „Rollen“ → „Neue Rolle“
„Live-Test 1.7“: Suchen Ansehen, Termine Ansehen, Aktionen „Umbuchen“ und „Status setzen“; „Sieht nur“
Standorte = nur die Fächer von HLF 20 (nicht HLF 20 selbst), Ausrüstungstypen alle. Speichern.
☑ Rolle „Live-Test 1.7“ angelegt.

**A – #7 als *Benutzer* (Ausgang, 1.3a, 1.7a)**
- ☑ A1 App öffnen: keine Fehlermeldung, Leiste Suchen, Prüffristen, Profil (kein Dashboard).
- ☑ A2 Nirgends ein Export (CSV) zu sehen (1.7a: der Server antwortet *Benutzer* mit 403).
- ☑ A3 Ein Gerät umbuchen und den Status ändern (Standort und Status vorher notieren); die
  Bestätigung erscheint, „Zuletzt benutzt“ zeigt es oben. (M1 von HLF 20 / G1, einsatzbereit, auf
  G2 und in Wartung gebucht.)

**B – Neue Rechte bei offener App (1.7b, 1.7c)**
- ☑ B1 iPhone: App auf „Suchen“ offen lassen.
- ☑ B2 PC (Admin): „Benutzer“ → Stift an #7 → Rolle „Live-Test 1.7“. Im Fenster steht „Die neue
  Rolle gilt beim nächsten Aufruf der App. Handys laden dabei ihre Offline-Kopie neu.“ Speichern.
- ☑ B3 iPhone: App in den Hintergrund (Homescreen), mindestens 30 s warten, wieder öffnen. Ohne
  Neuladen und ohne Anmelden zeigt „Suchen“ nur noch Geräte in den Fächern von HLF 20; „Zuletzt
  benutzt“ nur noch sichtbare.
- ☑ B4 Ein Gerät öffnen → „Anderen Standort suchen …“: nur HLF 20 mit seinen Fächern, ohne „Ganzes
  Fahrzeug, ohne Fach“ (1.7b, `selectable`).
- ☑ B5 Prüffristen: nur Geräte aus den Fächern von HLF 20.
- ☑ B6 Flugmodus an: grauer Streifen „Offline – angezeigt wird der Stand vom …“ mit einer Zeit NACH
  B3 (die Offline-Kopie wurde neu gespeichert), weiterhin nur die gefilterten Geräte. Flugmodus aus.
  (B1–B6: neue Rolle ohne Neuladen und Anmelden übernommen, nur Fächer von HLF 20, kein „Ganzes
  Fahrzeug“, Prüffristen gefiltert, Offline-Kopie neu gespeichert.)

**C – Warteschlange, wenn ein Recht wegfällt (1.7c)**
- ☑ C1 iPhone: Flugmodus an, ein sichtbares Gerät umbuchen („Gespeichert – wird gesendet, sobald
  eine Verbindung besteht …“). App noch nicht wieder online bringen.
- ☑ C2 PC (Super-Admin): Rolle „Live-Test 1.7“ bearbeiten, Aktion „Umbuchen“ entfernen, speichern.
- ☑ C3 iPhone: Flugmodus aus, App in den Vordergrund. Auf „Suchen“ steht die Buchung als nicht
  gesendet mit „Dafür fehlen Ihnen inzwischen die Rechte.“ und „Verwerfen“; „Verwerfen“ antippen.
  Beim Öffnen eines Geräts nur noch der Status, kein Standort. (Offline-Buchung von M2 nach Entzug
  von „Umbuchen“ mit „Dafür fehlen Ihnen inzwischen die Rechte.“, verworfen; danach nur noch Status.)

**D – Seite fällt weg (1.7c)**
- ☑ D1 iPhone: „Suchen“ offen lassen. PC (Super-Admin): an „Live-Test 1.7“ Suchen auf „Kein Zugriff“,
  speichern. iPhone: nach mindestens 30 s im Hintergrund wieder öffnen → still auf „Prüffristen“,
  in der Leiste kein „Suchen“ mehr. (Laut Holger D1–D3 bestanden: ohne „Suchen“ still auf
  „Prüffristen“.)

**E – Aufräumen**
- ☑ E1 PC (Admin): #7 wieder *Benutzer*. iPhone nach 30 s im Hintergrund: wieder alle Geräte, Leiste
  Suchen, Prüffristen, Profil.
- ☑ E2 PC (Super-Admin): „Live-Test 1.7 löschen …“ (geht erst, wenn kein Konto sie mehr hat).
- ☑ E3 Das in A3 umgebuchte Gerät auf Standort und Status von vorher zurücksetzen. (#7 wieder
  *Benutzer*, Rolle gelöscht, M1 zurück auf HLF 20 / G1, einsatzbereit.) Nachtrag 06.11.2026: Laut
  Server stand M1 noch auf HLF 20 / G2, in Wartung (letzter Eintrag die Buchung von #7 am 05.11.,
  15:56); das Zurücksetzen kam nicht an. Vom Agenten als *Admin* nachgeholt (Kommentar „Live-Test 1.7c
  E3 nachgeholt“, Version 3).

Nicht in dieser Liste (folgt mit 1.8 bzw. per Test): Verlauf mit „anderer Standort“, Log-Mails eines
gefilterten Empfängers, Buchen auf ein Ziel bzw. Gerät außerhalb (400/404).

## Schritt 1.8 – Durchlauf aller Rollen (06.11.2026, Build `9de6683e1f98`)

Ohne neuen Code und ohne Deploy. Der Agent meldet sich nicht an: Alles, was als #7 läuft, steht in
der Liste für Holger unten. Die Rollen (V1–V4) hat er angelegt, nachdem Holger sich als Super-Admin
angemeldet hatte.

**Vom Agenten geprüft (als *Admin*, bestehende Sitzung):**

- ☑ `build.json` = `9de6683e1f98`, `health` = `{"status":"ok","database":"ok"}`, keine Fehler in der
  Konsole.
- ☑ *Admin*: `auth/me` mit allen 10 Aktionen. Dashboard „55 von 69“, „6 defekt · 8 in Wartung“,
  „12 überfällig“, „8 in den nächsten 30 Tagen“, 2 offene Hinweise (beide M90).
- ☑ Rollen der Stadt: nur *Admin* (3 Konten) und *Benutzer* (2 Konten, 3 Codes); Konten #4, #6, #8
  *Admin*, #5, #7 *Benutzer*, alle aktiv.
- ☑ M1 auf HLF 20 / G1, einsatzbereit zurückgesetzt (Nachtrag zu E3 oben).
- ☑ *Benutzer* (#7): bestanden mit der iPhone-Liste nach 1.7c (A1–A3), unverändert seitdem.
- ☑ Die drei neuen Rollen: Liste unten am 06.11.2026 von Holger bestanden (H7, H9 übersprungen).

### Liste für Holger (Stand der Daten 06.11.2026)

PC, normales Fenster: angemeldet als Admin der Test-Stadt und als Super-Admin. PC, **Inkognito-Fenster**:
angemeldet als #7 „Holger Test 1“ unter `https://www.prodad.de/firefighting/test/` (eigene Cookies,
die Admin-Sitzung bleibt). Keine der Rollen ist *Admin*, #7 bleibt also immer angemeldet. Prüffristen
gelten für den 06.11.2026; an einem späteren Tag können Geräte von „bald fällig“ nach „überfällig“
wandern. Nach jedem Rollenwechsel im Inkognito-Fenster neu laden (F5), dann gilt die neue Rolle sofort.

**V – Vorbereitung (Super-Admin, Stadtdetail „Test-Feuerwehr“ → „Rollen“ → „Neue Rolle“)**

V1–V4 hat der Agent am 06.11.2026 mit Holgers Super-Admin-Sitzung selbst angelegt und geprüft (IDs
18, 19, 20, je Version 1, keine Fehler in der Konsole); die Rollen bleiben für G, T und H stehen.

- ☑ V1 „Live-Test Gerätewart“ (Vorschlag für Immendingen): Dashboard Ansehen, Suchen Ansehen,
  Ausrüstungsliste Ändern, Standorte Ändern, Ausrüstungstypen Ändern, Termine Ändern, Protokoll Ändern,
  Benutzer und Einstellungen Kein Zugriff; alle 10 Aktionen; „Sieht nur“ alle. Erwartet in „So sieht
  es die Rolle“ (Begriffe der Vorschau schon nach Konzept Abschnitt 9): Menü am PC „Dashboard ·
  Ausrüstung · Standorte · Ausrüstungstypen · Termine · Protokoll · Einstellungen“, Leiste am Handy
  „Suchen · Termine · Dashboard · Profil“, „alle Standorte, alle Ausrüstungstypen“.
- ☑ V2 „Live-Test Geräteträger“: nur Suchen Ansehen, Aktionen „Umbuchen“ und „Status setzen“. Erwartet:
  „Keine Konsole – nur die Handy-Seiten.“, Leiste „Suchen · Profil“.
- ☑ V3 „Live-Test Gerätewart HLF“: wie V1, dazu „Sieht nur“ Standorte = nur „HLF 20“ (Ebene 1, schließt
  die Fächer ein), Ausrüstungstypen alle. Erwartet: „Standorte: Ändern“, „Ausrüstungstypen: Ändern“ und
  „CSV-Import“ grau mit Hinweis (F3); erst nach Umstellen auf Ansehen bzw. Abwählen lässt sich
  speichern. „1 von 20 Standorten, alle Ausrüstungstypen“. (Erst alle Rechte wie V1, dann der Filter:
  Hinweis „Mit „Sieht nur …“ darf die Rolle nicht verwalten: „Standorte: Ändern“,
  „Ausrüstungstypen: Ändern“, „CSV-Import“ …“, „Speichern“ → „Nicht gespeichert …“ ohne Anfrage;
  danach Standorte und Typen Ansehen, ohne CSV-Import gespeichert, Filter `locations` [22].)
- ☑ V4 In der Tabelle „Rollen“ fünf Rollen, die drei neuen mit 0 Personen.

**Ergebnis (Holger, 06.11.2026):** G, T, H und E bestanden. Abweichung beim Testkonto: statt #7
„Holger Test 1“ (Passwort nicht zur Hand) hat Holger #5 „Holger (Test)“ (*Benutzer*) verwendet; überall,
wo unten #7 steht, gilt #5. #7 blieb unverändert *Benutzer*. T4 (iPhone, optional) nicht geprüft;
H7 und H9 übersprungen (freiwillig, per Test belegt: `DeviceVisibilityMatrixTest`).

**G – #7 als „Live-Test Gerätewart“ (PC, Admin: Benutzer → Stift an #7 → Rolle)**

- ☑ G1 Im Fenster nur der Hinweis „Die neue Rolle gilt beim nächsten Aufruf der App. Handys laden
  dabei ihre Offline-Kopie neu.“, kein Hinweis auf Abmelden. Speichern.
- ☑ G2 Inkognito (#7), neu laden: Startseite Dashboard. Menü „Dashboard, Suchen, Geräte, Standorte,
  Gerätetypen, Protokoll“, unten „Einstellungen“; **kein „Benutzer“**.
- ☑ G3 Dashboard wie beim Admin: „55 von 69“, „6 defekt · 8 in Wartung“, „12 überfällig“, „8 in den
  nächsten 30 Tagen“, „Offene Hinweise 2“ mit „Hinweise ansehen“, ohne „Freischaltungen ansehen“.
- ☑ G4 Geräte: „69 Geräte“, Knöpfe „Export (CSV)“, „Import (CSV)“, „Neues Gerät“, Kästchen je Zeile.
  Gerätedetail M90: „Umbuchen / Status ändern“, „Bearbeiten“, „Prüfdatum ändern“, „Code zuordnen“,
  „Aussondern …“ (nichts speichern).
- ☑ G5 Standorte und Gerätetypen mit „Neu …“ und Stift (nichts speichern); Protokoll mit beiden
  Reitern, „Erledigen …“ an einem offenen Hinweis sichtbar (nicht erledigen).
- ☑ G6 Einstellungen: Anzeigemodus, Benachrichtigung „Log“, Passwort, angemeldete Geräte; **ohne**
  Farbe und Logo und ohne den Hinweis „Farbe und Logo der Feuerwehr dürfen Sie nicht ändern.“
- ☑ G7 Adresse `…/firefighting/test/benutzer` eingeben: still zurück auf das Dashboard.

**T – #7 als „Live-Test Geräteträger“**

- ☑ T1 Admin: Rolle von #7 auf „Live-Test Geräteträger“. Inkognito neu laden: Seite „Suchen“, Leiste
  „Suchen, Profil“ (keine „Prüffristen“, kein „Dashboard“), alle 69 Geräte.
- ☑ T2 M90 öffnen: Standort und Status wählbar, kein Link „Gerätedetail (Admin)“ (nichts speichern).
- ☑ T3 Adresse `…/firefighting/test/prueffristen`: still auf „Suchen“.
- ☐ T4 (optional, iPhone als #7) Leiste ebenso „Suchen, Profil“ nach 30 s im Hintergrund.

**H – #7 als „Live-Test Gerätewart HLF“ (Datenfilter, mit Log-Mails)**

- ☑ H1 Admin: Rolle von #7 auf „Live-Test Gerätewart HLF“. Inkognito neu laden: Menü wie G2 (ohne
  „Benutzer“); in „Geräte“ kein „Import (CSV)“ (F3), „Export (CSV)“ und „Neues Gerät“ bleiben.
- ☑ H2 Dashboard: „22 von 25“, „2 defekt · 1 in Wartung“, „6 überfällig“, „2 in den nächsten 30
  Tagen“, „Offene Hinweise 2“. „Nicht einsatzbereit“ nur M5, PA 6 (defekt), M31 (in Wartung).
- ☑ H3 Geräte: „25 Geräte“ (HRT 1–4, HRT 7, M1–M6, M8, M9, M27, M31, M32, M90, M93, PA 1–6, WBK 1);
  Filter „Standort“ bietet nur HLF 20 und seine Fächer. „Export (CSV)“ ergibt 25 Zeilen plus Kopfzeile.
- ☑ H4 Prüffristen: überfällig HRT 1, PA 1, HRT 7, M1, M2, HRT 2; bald fällig M8 (heute), PA 2.
- ☑ H5 Standorte: nur „HLF 20“ mit Fahrerhaus 5, G1 9, G2 2, G3 4, G4 2 (gesamt 25), **ohne** „Neu …“
  und Stift. Gerätetypen: Atemschutzmaske 10, Atemschutzgerät 7, Funkgerät 5, Live-Testtyp 2 2,
  Wärmebildkamera 1, ohne „Neu …“ und Stift.
- ☑ H6 Verlauf mit „anderer Standort“: Gerätedetail M27 → Eintrag vom 02.11. „anderer Standort → HLF 20
  / G1“ (für den Admin „Lager Gerätehaus → …“); ebenso HRT 7 (01.11., vorher LF 10 / Fahrerhaus) und
  WBK 1 (30.10., vorher LF 10).
- ☐ H7 Protokoll → „Verlauf exportieren“, Standard (letzte 90 Tage): 78 Einträge (nur Geräte, die jetzt
  auf HLF 20 stehen), Standorte außerhalb als „anderer Standort“.
- ☑ H8 Gerät außerhalb: Adresse `…/firefighting/test/geraete/74` (M7, Lager Gerätehaus) → „Dieses Gerät
  gibt es nicht (mehr).“; Suchen nach „ARFK-1007“ (Scan-Code von M7) + Enter → „Kein Gerät mit diesem
  Code gefunden.“
- ☐ H9 Ziel außerhalb (400): Inkognito auf „Suchen“, Entwicklerwerkzeuge (F12) → Konsole, einfügen:
  `fetch('api/v1/devices/134',{headers:{'X-Client-Api':'2'}}).then(r=>r.json()).then(d=>fetch('api/v1/devices/134/state',{method:'POST',headers:{'Content-Type':'application/json','X-Client-Api':'2'},body:JSON.stringify({version:d.device.version,location_id:34})})).then(r=>r.json()).then(console.log)`
  (M90 → Lager Gerätehaus). Erwartet `{error: "invalid_request", …}`, M90 bleibt auf HLF 20 / G1.
- ☑ H10 Umbuchen in der App: M90 → „Anderen Standort suchen …“ zeigt nur HLF 20 (mit „Ganzes Fahrzeug,
  ohne Fach“, da die ganze Ebene gewählt ist) und seine Fächer (nichts speichern).
- ☑ H11 Log-Mails: Inkognito → Einstellungen → Benachrichtigung „Log“ einschalten. Dann im normalen
  Fenster als Admin mit M92 (Lager Gerätehaus, einsatzbereit):
  - a) Status „in Wartung“ → **keine** Mail an #7 (M92 ist für #7 nicht sichtbar).
  - b) Umbuchen auf HLF 20 / G1 → Mail an #7, Betreff beginnt mit „Log Test-Feuerwehr: M92
    umgebucht“, Von „anderer Standort“, Nach „HLF 20 / G1“. Inkognito: M92 jetzt in der Liste (26 Geräte), Verlauf „anderer
    Standort → HLF 20 / G1“.
  - c) Zurück auf Lager Gerätehaus, einsatzbereit → Mail an #7 (vorher sichtbar), Nach „anderer
    Standort“. Inkognito: wieder 25 Geräte, `geraete/137` → „Dieses Gerät gibt es nicht (mehr).“
  Ergebnis: a keine Mail, b und c je eine Mail mit „anderer Standort“. Bei b ging die erste Umbuchung
  von M92 nicht durch (vermutlich Bedienfehler), die zweite klappte.
  Mails gehen nie an den Auslöser (#4), wohl aber an andere Admins mit eingeschalteter Option.

**E – Aufräumen**

- ☑ E1 Admin: #7 wieder *Benutzer* (die Benachrichtigung „Log“ schaltet sich dabei aus, *Benutzer* hat
  kein `mail.log`).
- ☑ E2 Super-Admin: die drei Rollen „Live-Test …“ löschen; danach nur *Admin* und *Benutzer*.
- ☑ E3 M92 wieder Lager Gerätehaus, einsatzbereit (nach H11 c schon so); Inkognito-Fenster schließen.
- ☑ Agent danach lesend (06.11.2026, Build `59dc745c6182`): #5 *Benutzer*, #7 *Benutzer*; Rollen der
  Stadt nur *Admin* (3 Konten) und *Benutzer* (2 Konten, 3 Codes); M92 (ID 137) Lager Gerätehaus,
  einsatzbereit, Version 8.

## Schritt 1.8a – `ClientApi::MIN` = 2 (06.11.2026, Build `bc3fe040a178`)

Deploy am Freitagmorgen (rund 07:00 Uhr), außerhalb eines Dienstabends. CI des Commits grün (14 min,
der Lauf wartete auf den vorigen).

- ☑ Deploy „Deployment erfolgreich“, `build.json` = `bc3fe040a178`, `health` ok (das Deploy-Skript
  fragt ohne `X-Client-Api` und kommt weiter durch).
- ☑ `GET api/v1/tenant` der Test-Stadt: mit `X-Client-Api: 1` → 409 `client_outdated`, „Diese
  Version der App ist veraltet – bitte aktualisieren.“, `details {"min_client_api": 2}`; mit 2 und
  ohne Header → 200.
- ☑ App als *Admin*: Der offene Tab lief noch mit `9de6683e1f98` (sendet schon 2) ohne Hinweis und
  Fehler weiter; beim Wechsel auf „Geräte“ kam `bc3fe040a178`. Keine Fehler in der Konsole.
- ☐ Eine App mit 1 (04./05.11.) live: nicht mehr vorhanden bzw. nicht nachstellbar; das Verhalten
  (Hinweis, sofortiges Update, Warteschlange wartet) ist per Test belegt (`client.spec.ts`,
  `appUpdate.spec.ts`, `bookingQueue.spec.ts`), siehe auch LIVETEST-OFFLINE V4.

## Schritt 1.8b – Rollen für Immendingen (Anlegen durch Holger, nach 1.8a)

Freigabe Holger 06.11.2026: Holger legt die beiden Rollen selbst als Super-Admin an (Stadtdetail
„Feuerwehr Immendingen“ → Reiter „Rollen“ → „Neue Rolle“), Konten werden vorerst keine zugewiesen.
Der Agent ändert in Immendingen nichts und prüft danach nur lesend (`GET tenants/1/roles`). Ausgang
(06.11.2026, gelesen): *Admin* (1 Konto) und *Benutzer* (0 Konten, 0 Codes), je Version 1.

**Rolle 1 – Gerätewart**

- Name: `Gerätewart`
- Kurzbeschreibung: `Pflegt die Ausrüstung: anlegen, bearbeiten, Termine, Protokoll; ohne Benutzer und Einstellungen`
- 1 · Ansichten und Bereiche:
  - Dashboard: **Ansehen**
  - Suchen und Ausrüstung ansehen: **Ansehen**
  - Ausrüstungsliste: **Ändern**
  - Standorte: **Ändern**
  - Ausrüstungstypen: **Ändern**
  - Termine: **Ändern**
  - Protokoll und Hinweise: **Ändern**
  - Benutzer: **Kein Zugriff**
  - Einstellungen der Stadt: **Kein Zugriff**
- 2 · Aktionen (alle 10 angehakt):
  - Buchen: Umbuchen, Status setzen
  - Ausrüstung verwalten: Ausrüstung anlegen, Stammdaten bearbeiten, Aussondern und wieder
    aufnehmen, Code zuordnen
  - Mehrere Ausrüstungen: Sammelaktionen, CSV-Import, CSV-Export
  - Benachrichtigungen: Log-Mails empfangen
- 4 · Sieht nur …: Standorte **Alle Standorte**, Ausrüstungstypen **Alle Ausrüstungstypen**
- Erwartet in „So sieht es die Rolle“: Menü am PC „Dashboard · Ausrüstung · Standorte ·
  Ausrüstungstypen · Termine · Protokoll · Einstellungen“, Leiste „Suchen · Termine · Dashboard ·
  Profil“, „alle Standorte, alle Ausrüstungstypen“. Sitzung 7 / 30 Tage.

**Rolle 2 – Geräteträger**

- Name: `Geräteträger`
- Kurzbeschreibung: `Sucht Ausrüstung, bucht um und setzt den Status; ohne Termine`
- 1 · Ansichten und Bereiche: Suchen und Ausrüstung ansehen **Ansehen**; alle anderen acht
  **Kein Zugriff**
- 2 · Aktionen: nur Umbuchen und Status setzen
- 4 · Sieht nur …: **Alle Standorte**, **Alle Ausrüstungstypen**
- Erwartet: „Keine Konsole – nur die Handy-Seiten.“, Leiste „Suchen · Profil“, „Darf: Umbuchen,
  Status setzen“. Sitzung 30 / 90 Tage. Eigene Rechte (Benutzt melden, Tauschen, Einmalgeräte) erst
  mit Phase 2.

**Danach in der Tabelle „Rollen“:** Admin (1 Person), Benutzer (0), Gerätewart (0), Geräteträger (0).

- ☐ Holger: beide Rollen angelegt.
- ☐ Agent (nur lesend): Rechte, Beschreibung, Filter und 0 Personen wie oben.

## Zwischenschritt vor 1.9 – ganze Zeile bzw. Karte öffnet den Link (06.11.2026, Build `daf7d505d486`)

Wunsch Holger beim Anlegen der Rollen: überall, wo der Name einer Zeile ein Link ist, wirkt ein Klick
irgendwo in der Zeile wie auf den Namen (gemeinsamer Baustein `vRowLink`, Regel in CLAUDE.md). CI des
Commits grün (9:40 min, alle Browser-Tests in acht Teilen).

- ☑ Deploy „Deployment erfolgreich“, `build.json` = `daf7d505d486`, `health` ok.
- ☑ Test-Stadt (Admin, nur lesend): Standorte – Zeile „Atemschutzwerkstatt“ (Ebene 1) →
  `geraete?standort=36`, Fach „Fahrerhaus“ → `geraete?standort=27`, Mauszeiger Hand. Gerätetypen –
  Zeile „Atemschutzgerät“ → `geraete?typ=9`; der Stift öffnet nur das Fenster (abgebrochen).
  Geräteliste – das Kästchen wählt nur aus, ein Klick auf den Standort öffnet das Gerätedetail.
  Dashboard „Letzte Aktivitäten“ – Klick auf die Zeit von M92 → `geraete/137/details`.
- ☑ Super-Admin (nur lesend): Dashboard, Zeile „Test-Feuerwehr“ (Status) → `staedte/2`; „Städte“,
  Adresse → `staedte/2`; Rollen der Test-Stadt: *Admin* → „Rolle ansehen“, *Benutzer* → „Rolle
  bearbeiten“ (nichts gespeichert, „Kopieren“ nicht angetippt).
- ☑ Update beim Seitenwechsel: Test-Stadt sofort; unter `service` im selben Tab griff die Sperre
  „reloaded recently“ (10 min), „Jetzt aktualisieren“ lud die neue Version. Keine Fehler in der Konsole.
- ☐ Offen für Holger: am iPhone bzw. in Handy-Breite eine Karte antippen (Gerätetypen, Städte, Rollen);
  per Browser-Test in allen fünf Profilen belegt.
