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
