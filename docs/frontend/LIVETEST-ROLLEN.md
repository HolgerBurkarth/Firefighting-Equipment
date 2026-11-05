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
