# Live-Test Phase 1: Rollen und Rechte

Nur in der Test-Stadt `https://www.prodad.de/firefighting/test/`, nie in echten Städten. Plan:
[`../PLAN-PHASE-1-ROLLEN.md`](../PLAN-PHASE-1-ROLLEN.md). Je Schritt wird ergänzt, was live geprüft
ist (☑ bestanden, ☒ Fehler, ☐ offen für Holger). Der vollständige Durchlauf aller Rollen folgt mit
Schritt 1.8.

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
  *Benutzer*, Rolle gelöscht, M1 zurück auf HLF 20 / G1, einsatzbereit.)

Nicht in dieser Liste (folgt mit 1.8 bzw. per Test): Verlauf mit „anderer Standort“, Log-Mails eines
gefilterten Empfängers, Buchen auf ein Ziel bzw. Gerät außerhalb (400/404).
