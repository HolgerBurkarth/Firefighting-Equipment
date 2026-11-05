# Plan Phase 1: Rollen und Rechte

Stand: 05.11.2026 · Freigegeben durch Holger am 05.11.2026 (Entscheidungen siehe unten, sie gehen
den Vorschlägen im Text vor). Entwurf vom 04.11.2026.

Grundlage: [`AENDERUNGSKONZEPT-2026-11.md`](AENDERUNGSKONZEPT-2026-11.md) Abschnitt 1 und 9,
Mockups der Seiten 1 und 2 in [`aenderungskonzept/mockups/`](aenderungskonzept/mockups/LIESMICH.md)
(`Main`, `Rolle-bearbeiten`, `Benutzer`, `Benutzer-Rolle`, `Einladungscodes`), die Ergänzung
„Zugang über Claude“ vom 04.11.2026 und der heutige Code (`Routes::tenant()`, `Access`,
`UserRepository`, `SessionLifetime`). Oberflächentexte nach der Begriffs-Tabelle im Konzept,
Abschnitt 9 (z. B. „Ausrüstung“, „Termine“, „Ansehen/Ändern“, „Sieht nur …“).

Mit ⚠ markiert: Schritte, die bestehende Daten ändern oder vor dem Bau eine Entscheidung brauchen.

## Kurzfassung

- Der Server prüft künftig **Rechte** statt der Rolle `admin`/`user`. Eine Rolle ist ein
  Datensatz je Stadt (Name, Beschreibung, Bereiche mit Stufe, Aktionen, Einschränkung, Datenfilter).
- `fe_users.role` bleibt als **Kontoart** (`superadmin`, `admin`, `user`): `admin` heißt „hat die
  Rolle Admin“. So gelten die heutigen Schutzregeln (letzter Admin, Sitzungsdauer, CHECKs)
  unverändert weiter; neu ist `fe_users.role_id`.
- Die Prüfung bleibt an derselben Stelle in `Application::handle` (nach der Sitzung, vor dem
  Handler). Jede Route unter einer Stadt deklariert ihr **Erfordernis** (`Requirement`) statt
  `Access::Admin`/`Authenticated`.
- **Datenfilter** („Sieht nur …“) setzt das Repository durch: Jede Methode, die Geräte, Ereignisse
  oder Hinweise liest, verlangt ein `DeviceVisibility`-Argument (kein Standardwert). Ein Test prüft
  jede Abfrage mit und ohne Filter.
- Für die Standard-Rollen *Admin* und *Benutzer* ändert sich nichts; neue Rollen gibt es erst, wenn
  der Super-Admin sie anlegt. Immendingen wird nicht angefasst.
- Neun Schritte (1.1–1.9), davon zwei groß; eine Migration ändert bestehende Daten (⚠ 1.2).

## Entscheidungen (05.11.2026)

Freigabe durch Holger am 05.11.2026. Wo hier nichts anderes steht, gelten die Vorschläge des Plans.
Diese Entscheidungen gehen dem übrigen Text vor; Abschnitte, die davon abweichen, gelten als
angepasst.

| Nr. | Entscheidung |
| --- | --- |
| F1 | Ein Rollenwechsel beendet die Sitzungen des Kontos nur, wenn die Rolle *Admin* dazukommt oder wegfällt (also wenn sich die Kontoart `admin` ↔ `user` ändert). Jeder andere Wechsel wirkt ab der nächsten Anfrage (`Permissions` wird je Anfrage gebildet). CLAUDE.md („Rollenwechsel beendet alle Sitzungen“) wird mit Schritt 1.6 entsprechend angepasst. |
| F2 | Wie vorgeschlagen: Die Standard-Rolle *Benutzer* bekommt kein `csv.export`. Präzisiert am 05.11.2026 (Holger): Die Vorgabe „jede Route wie heute“ galt für den Umbau in 1.3, nicht für diese gewollte Änderung. Umgesetzt in **Schritt 1.7**: neue Aktion `csv.export` für `GET devices/export` und `GET device-events/export`; *Admin* hat sie automatisch, *Benutzer* nicht (dann 403), eigene Rollen können sie bekommen. Vorlage und gültige Werte des Imports bleiben bei `csv.import`. Tests: `TenantRoutePermissionsTest` mit der neuen Aktion, Rolle *Benutzer* 403. `UEBERGABE-BACKEND.md` ergänzen; die „✅ FERTIG“-Meldung zu 1.7 nennt ausdrücklich, dass sich hier das Verhalten für *Benutzer* ändert. |
| F3 | Die vier Rechte `locations` Ändern, `device_types` Ändern, `csv.import` und `users` (Ansehen und Ändern: „Benutzer verwalten“) gibt es nur für Rollen ohne Datenfilter „Sieht nur …“. Im Editor der Rollen sind sie bei Rollen mit Datenfilter ausgegraut, mit Hinweis. Der Server lehnt eine Rolle mit Datenfilter und einem dieser Rechte mit 409 `rule_violation` ab (beim Speichern der Rolle, in beide Richtungen: Filter setzen bei vorhandenem Recht ebenso). |
| F4 | Wie vorgeschlagen: Einladungscodes behalten Bezeichnung, „Gültig (Tage)“ und „Registrierungen höchstens“; kein Nachdruck bestehender Codes, keine Teile des Codes gespeichert (die korrigierten Mockups `Einladungscodes` folgen dem). |
| F5 | Auch andere Rollen als *Admin* dürfen Benutzer verwalten, wenn sie das Recht `users` Ändern haben (nur ohne Datenfilter, F3). Es gelten alle Schutzregeln: Nur ein Admin vergibt oder entzieht die Rolle *Admin*, und nur ein Admin sperrt oder löscht Admins (bzw. ändert deren Anzeigenamen oder Rolle). Niemand ändert sich selbst. Der letzte aktive Admin bleibt. Zusätzlich (Vorschlag Abschnitt 5): Wer eine Rolle zuweist, freischaltet oder einen Einladungscode mit Rolle anlegt, braucht alle Rechte dieser Rolle. |
| F6 | Mehrfachauswahl in der Benutzerliste bietet nur „Rolle zuweisen …“. |
| F7 | Reiter „Übersicht“ im Stadtdetail des Super-Admins = der heutige Kopf der Stadt (Name, Status, Zahlen, Umbenennen, Sperren). |
| F8 | Wie vorgeschlagen: Der Eintrag „Dashboard“ der Handy-Leiste erscheint, sobald die Rolle eine Seite der Konsole hat; ohne `dashboard` führt er zur ersten erlaubten Seite. |
| F9 | Wie vorgeschlagen: `mail.log` ist eine Aktion der Rolle; die persönliche Einstellung (an/aus) bleibt zusätzlich. |
| F10 | Wie vorgeschlagen: „Rolle aus anderer Stadt übernehmen“ übernimmt die Rechte, nicht die Datenfilter. |
| F11 | Wie vorgeschlagen: Sitzungsdauer aus den Rechten (kurz/lang, Abschnitt 5), keine Einstellung je Rolle. |
| F12 | Zugang über Claude (wie im Chat zum Claude-Konnektor entschieden): Für Konten ohne Rolle *Admin* gibt ein Admin der Stadt Claude Zugriff; für Konten mit Rolle *Admin* gibt nur der Super-Admin den Zugriff; Super-Admins erhalten keinen Zugriff; niemand gibt sich den Zugriff selbst. Umgesetzt erst in der MCP-Phase. Phase 1 plant nur so, dass es passt: `fe_users.claude_access` und `fe_sessions.channel` (Abschnitt 1) kommen dann als reine Erweiterung; nichts in Phase 1 darf diese Spalten oder Werte belegen. |
| F13 | Wie vorgeschlagen: Prüfdatum einzeln ändern = `due` Ändern, keine eigene Aktion. |
| F14 | Wie vorgeschlagen: Adressen (`geraete`, `prueffristen`, Mail-Links) bleiben; die CSV nimmt später alte und neue Überschriften an. Die Umstellung der Begriffe bleibt ein eigener späterer Schritt (Abschnitt 8). |

Weiterhin nur nach Rückfrage („❓ RÜCKFRAGE“):

- Migration 0036 (Schritt 1.2) ändert bestehende Daten: vor dem Ausführen Probelauf auf einer Kopie
  und die genauen Änderungen zeigen.
- `ClientApi::MIN` = 2: entschieden wird in Schritt 1.8, bevor Immendingen eigene Rollen bekommt
  (Schritt 1.7 hebt nur `X-Client-Api` auf 2, MIN bleibt 1; siehe Schritt 1.7 unten).
- Die Rollentabelle für Immendingen (Abschnitt 6) bleibt ein Vorschlag; echte Städte werden nicht
  angefasst.

### Schritt 1.7d „Schnellstart“ (Wunsch Holger 05.11.2026, nach 1.7c, vor 1.8)

Erst umsetzen, wenn 1.7c live ist (der Sofortstart braucht die gespeicherte `permissions.version`).
Präzisiert am 05.11.2026 (Holger): Ziele 1, 3 und 5 (Update-Prüfung im Hintergrund, paralleles
Laden, Messung) hängen nicht an 1.7c; Ziel 2 (Start mit gespeicherter Sitzung und Offline-Kopie)
erst nach 1.7c; Ziel 4 (Startbild) erst, wenn Holger das Mockup freigibt. Reihenfolge: 1.7b → 1.7c →
1.7d.
Mockup Seite 9 [`Startbild.dc.html`](aenderungskonzept/mockups/Startbild.dc.html), von Holger
freigegeben am 05.11.2026 (Ziel 4 wird trotzdem erst mit 1.7d nach 1.7c gebaut). Es gelten die
Vorgaben von Ziel 4 unten; wo der Begleittext des Mockups abweicht (dort „nach rund 0,3 s“ bzw.
„unter 0,5 s“), gilt der Plan: sichtbar erst nach rund 0,4 s.

**Gemessen am 05.11.2026** in `test` (Anmeldeseite, Handy-Breite):

- Erster Besuch: rund 4,5 s bei nur 166 KB. Die Anfragen laufen nacheinander: Seite →
  Programmteile → `tenant`/`auth/me` → Seite → Logo.
- Weitere Starts mit Service Worker: rund 2,6 s. Davon wartet `checkOnStart` bis zu 2 s
  (`START_CHECK_TIMEOUT_MS`) auf `build.json` bzw. die Registrierung, bevor die erste Seite erscheint.
- Antworten von STRATO: rund 0,2–0,3 s je Anfrage.

**Ziele**

- **Start wartet nicht auf die Update-Prüfung**: Die Seite erscheint sofort, die Prüfung läuft im
  Hintergrund, das Update kommt beim nächsten Seitenwechsel (`installUpdateGuard`, wie heute).
  Ausnahmen bleiben „Seite nicht gefunden“ (`checkBeforeNotFound`) und `client_outdated`. Alle Fälle
  in `appUpdate.spec.ts` bleiben grün. Setzt ein Test das alte Verhalten beim Start voraus
  (Kandidaten: „erster Besuch, Deploy noch vor der Installation“, „Strg+F5“, „Mail-Link mit alter
  Version“, „neue Kennung, aber kein neuer Service Worker“, „neuer Service Worker installiert
  langsam“), wird je Fall begründet, warum die neue Erwartung richtig ist, statt ihn nur anzupassen
  (Begründung im Test und in der „✅ FERTIG“-Meldung). Eine alte Version beim Start ist zulässig, weil
  die API nur erweitert wird (siehe CLAUDE.md "Versionierung der API für das Frontend") und
  `client_outdated` weiterhin sofort aktualisiert. CLAUDE.md ("Updates") und KONZEPT.md "Updates der
  App" im selben Schritt nachziehen.
- **Angemeldete starten sofort** mit der gespeicherten Sitzung und der Offline-Kopie vom Gerät (wie
  heute bei gestörtem Netz, `usingStoredSession`); `auth/me` und das Laden der Geräte laufen im
  Hintergrund. Eine echte Ablehnung führt wie heute zur Anmeldung (`onStoredSessionRejected`), neue
  Rechte wie in 1.7c. Gilt nur, wenn `permissions.version` gespeichert ist; sonst wie heute erst
  nach `auth/me`.
- **Parallel laden**: Die Zielseite (Programmteil der Route) lädt gleichzeitig mit `tenant` und
  `auth/me` (Vue Router lädt Seiten sonst erst nach dem Wächter). Das Logo blockiert nicht.
  Öffentliche Seiten (z. B. die künftige Rückgabe ohne Anmeldung, Mockup `Handy-Rueckgabe`) brauchen
  kein `auth/me`. Zu prüfen beim Bau: ob die Weiche `/` ohne gespeicherte Sitzung gleich zur
  Anmeldung kann und ob ein Vorladen von `api/v1/tenant` aus `index.html` ohne Inline-Skript hilft.
- **Startbild** nach dem Mockup:
  - steht in `index.html` (außerhalb von `#app`), Bild (SVG) und Stil als eigene Dateien des Builds,
    ohne Ausnahme in der CSP (per Test, wie die übrigen CSP-Regeln);
  - erst nach rund 0,4 s sichtbar, nur per CSS (Verzögerung einer Animation); ein schneller Start
    zeigt es also nie; die App entfernt es, sobald die erste Seite steht;
  - Ladebalken zuerst ohne Stand, danach in drei Schritten aus der App („App wird geladen“,
    „Anmeldung wird geprüft“, „Ausrüstung wird geladen“), gesetzt als Attribut am Startbild (kein
    `style=""`), die Breite je Schritt im Stylesheet;
  - hell und dunkel, `prefers-reduced-motion` beachten (dann ohne Bewegung);
  - Name und Farbe der Stadt aus dem letzten Besuch nur ohne Inline-Skript. Vorschlag: `main.ts`
    setzt sie als Erstes aus localStorage (Farbe als CSS-Variable per CSSOM); mit Service Worker
    läuft das vor den 0,4 s. Beim ersten Besuch bzw. ohne gespeicherten Stand „Feuerwehr“ in der
    Standardfarbe. PHP setzt nichts ein (das Frontend liefert PHP ohne Datenbank aus).
- **Messen**: ein Messskript (Ablauf wie oben, Handy-Breite; Zeitpunkte über `performance.mark` der
  App, z. B. „erste Seite“ und „Suchen mit Daten“), Vorher/Nachher in der „✅ FERTIG“-Meldung.
  Erster Besuch ohne Anmeldung per Playwright gegen `test`; angemeldet nur mit Claude in Chrome (der
  Agent meldet sich nicht an) oder durch Holger. Ziel: Ein angemeldeter Benutzer mit Service Worker
  sieht „Suchen“ mit Daten in unter 1 s; ein erster Besuch zeigt die Anmeldeseite deutlich schneller
  als heute.

**Tests**: `appUpdate.spec.ts` (alle Fälle, siehe oben), Sofortstart mit gespeicherter Sitzung und
späterer Ablehnung bzw. neuen Rechten (`session.spec.ts`, `offline.spec.ts`), Startbild (erscheint
nicht bei schnellem Start, erscheint nach 0,4 s mit `page.clock`, Schritte, dunkel, reduzierte
Bewegung, keine CSP-Verletzung), Unit-Tests für die Reihenfolge des Starts. Browser- und
breitenabhängig: betroffene Dateien in allen fünf Profilen, Service Worker zusätzlich unter WSL.

**Risiken**: Mit gespeicherter Sitzung sieht ein inzwischen gesperrtes Konto kurz seine
Offline-Kopie (wie heute offline; jede Anfrage bekommt 401, dann Anmeldung). Eine alte Version läuft
bis zum nächsten Seitenwechsel (abgefangen über `client_outdated` und `X-Api-Version`).

### Schritt 1.9 „Konto wechseln“ (Konzept E14, Mockups Seite 8)

Mockups `Konto-wechseln` (Admin-Konsole) und `Handy-Konto-wechseln` (Profil) in
[`aenderungskonzept/mockups/`](aenderungskonzept/mockups/LIESMICH.md).

- **Kontoliste auf dem Gerät**: Wer in mehreren Städten ein Konto hat, sieht eine Liste auf dem
  Gerät. localStorage (ein Schlüssel für alle Städte, da die Liste städteübergreifend ist) enthält je
  Eintrag nur Kürzel, Name der Stadt, Anzeigename und Rollenname; keine Tokens, keine E-Mail, keine
  IDs außer der Konto-ID zum Zuordnen der Warteschlange (`ff-queue-owners`, IndexedDB `queue`). Ein
  Eintrag kommt bei jeder bestätigten Sitzung dazu bzw. wird aktualisiert; Abmelden in einer Stadt
  entfernt ihn.
- **Ort in der App**: Admin-Konsole unten im Menü der Name des Kontos als Knopf, darüber öffnet sich
  die Liste; am Handy der Abschnitt „Konto wechseln“ im Profil.
- **Wechseln** ist eine volle Navigation auf `/firefighting/<kürzel>/`. Die Sitzungen bleiben
  getrennt (Cookies gelten je Pfad). Mit ungespeicherten Eingaben fragt die App erst: „Ihre Eingaben
  auf dieser Seite gehen verloren. Trotzdem wechseln?“, Standard „Abbrechen“ (`ConfirmDialog`).
- **Kennzeichen je Eintrag**: „aktuell“, „angemeldet“ oder „neu anmelden“ (aus der gespeicherten
  Sitzung der Stadt, `ff-session:<kürzel>`; ohne sie „neu anmelden“). Bei „neu anmelden“ führt
  Antippen zur Anmeldung dieser Stadt mit Rückkehr. Nicht gesendete Buchungen eines Kontos zeigt der
  Eintrag als Hinweis.
- **„+ Weitere Stadt hinzufügen …“**: Eingabe von Adresse oder Kürzel; geprüft wie `TenantSlug`
  (`slugProblem`), dann zur Anmeldung dieser Stadt.
- **Super-Admin**: `service` gehört nicht in die Liste und zeigt keine.
- **Tests**: Browser-Tests mit zwei Städten (zwei Bereiche im selben Testserver), Unit-Tests der
  Liste.

## 1. Datenmodell

Neue Tabellen mit `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, feste Werte
`ascii_bin`, Zeitstempel in UTC, eine DDL je Migration. Nummern ab `0029` (letzte heute: `0028`).

| Nr. | Datei | Inhalt | Ändert Daten? |
| --- | --- | --- | --- |
| 0029 | `create_fe_roles` | `id`, `tenant_id` (NOT NULL, FK `fe_tenants`), `system_key` (`ascii_bin`, NULL oder `admin`/`user`, CHECK), `name` (VARCHAR(50), eindeutig je Stadt, `utf8mb4_unicode_ci`), `description` (VARCHAR(200) NULL), `restrict_single_use` (TINYINT, 0; wirkt erst ab Phase 2), `filter_locations` und `filter_device_types` (TINYINT, 0 = alle sichtbar), `created_at`, `updated_at`, `updated_by` (FK `(updated_by)` → `fe_users`, NULL = Kommandozeile/Migration). UNIQUE `(tenant_id, system_key)` (je Stadt höchstens eine Admin- und eine Benutzer-Rolle; NULL mehrfach), UNIQUE `(id, tenant_id)` für zusammengesetzte Fremdschlüssel. | nein |
| 0030 | `create_fe_role_areas` | `role_id`, `tenant_id`, `area` (`ascii_bin`, CHECK Format `^[a-z_]{1,30}\z`), `level` (`read`/`write`, CHECK). PK `(role_id, area)`, FK `(role_id, tenant_id)` → `fe_roles (id, tenant_id)` ON DELETE CASCADE. „Kein Zugriff“ = keine Zeile. | nein |
| 0031 | `create_fe_role_actions` | `role_id`, `tenant_id`, `action` (`ascii_bin`, CHECK Format `^[a-z_.]{1,40}\z`). PK `(role_id, action)`, FK wie oben. | nein |
| 0032 | `create_fe_role_locations` | Datenfilter Standorte: `role_id`, `tenant_id`, `location_id`. PK `(role_id, location_id)`, FK `(location_id, tenant_id)` → `fe_locations (id, tenant_id)` (nie gelöscht, also kein CASCADE nötig), FK auf `fe_roles` mit CASCADE. Ein Standort der Ebene 1 schließt seine Fächer ein. | nein |
| 0033 | `create_fe_role_device_types` | Datenfilter Ausrüstungstypen: `role_id`, `tenant_id`, `device_type_id`, analog. | nein |
| 0034 | `insert_fe_roles_defaults` | Nur `INSERT`: je bestehender Stadt die Rolle *Admin* (`system_key = admin`, ohne Zeilen in `fe_role_areas`/`_actions`: Admin hat immer alle Rechte, auch künftige) und *Benutzer* (`system_key = user`, Rechte = heutiges Verhalten, siehe Abschnitt 5). Neue Städte bekommen beide in `TenantRepository::create` (wie die Start-Gerätetypen, beide Stellen gemeinsam ändern). | nein (nur neue Zeilen) |
| 0035 | `add_fe_users_role_id` | `ALTER TABLE fe_users ADD COLUMN role_id … NULL` mit FK `(role_id, tenant_id)` → `fe_roles (id, tenant_id)` (eine ALTER-Anweisung). | nein |
| 0036 | `update_fe_users_role_id` | ⚠ **Ändert bestehende Daten**: `UPDATE fe_users` setzt `role_id` aller Konten einer Stadt auf die Rolle *Admin* (`role = 'admin'`) bzw. *Benutzer* (`role = 'user'`), auch `invited`, `unverified`, `pending` und `deleted`. Braucht Holgers Freigabe; vorher Sicherung durch das Deploy-Skript (offene Migrationen). | **ja** |
| 0037 | `add_fe_users_role_id_check` | CHECK `chk_fe_users_role_id`: `(tenant_id IS NULL) = (role_id IS NULL)` (Super-Admins ohne Rolle, jedes Stadt-Konto mit Rolle). Schlägt fehl, wenn 0036 nicht vollständig lief. | nein |
| 0038 | `add_fe_invitation_codes_role_id` | `role_id` NULL mit FK auf `fe_roles`; NULL = Standard-Rolle *Benutzer* (alte Codes bleiben so ohne UPDATE gültig). Dass *Admin* nicht wählbar ist, prüft der Code (ohne Trigger nicht als CHECK möglich, da es an `fe_roles.system_key` hängt). | nein |
| 0039 | `drop_fe_users_notify_device_log_check` | Alte CHECK `chk_fe_users_notify_device_log` (nur `admin`/`superadmin`) entfernen. | nein |
| 0040 | `add_fe_users_notify_device_log_check` | Neue CHECK: nur nicht `deleted`. Wer Log-Mails bekommt, bestimmt künftig die Aktion `mail.log` der Rolle (Code). | nein |

Umgesetzt in Schritt 1.1 (05.11.2026) mit zwei Ergänzungen: `fe_roles.version` (INT, ab 1) schon
jetzt für das gleichzeitige Bearbeiten in 1.5 (409 `version_conflict`), und CHECK
`chk_fe_roles_admin` (die Rolle *Admin* hat nie Datenfilter oder Einschränkungen). Offen für 1.5:
`bin/tenant.php purge-device-data` löscht Standorte; ein Datenfilter, der auf einen Standort zeigt,
verhindert das (Fremdschlüssel, die Transaktion wird zurückgenommen). Sobald Datenfilter gesetzt
werden können, entfernt der Befehl vorher die Zeilen in `fe_role_locations` der Stadt.

Schritt 1.2 (05.11.2026) in zwei Teilen: 1.2a ohne Migration (Schreibwege setzen `role_id`,
Probelauf `bin/role-assignment-check.php`), dann nach Freigabe 0036–0038. Probelauf auf dem Server
durch Holger am 05.11.2026, 06:39: 7 Konten bekämen eine Rolle (immendingen: admin active 1, user
deleted 1; test: admin active 3, user active 2), keines bliebe ohne Rolle, keine unpassende Rolle,
1 Super-Admin ohne Rolle; 0037 läuft durch. Freigabe der Migrationen 0036–0038 durch Holger am
05.11.2026.

Schritt 1.3a (05.11.2026): Für die Standard-Rollen muss jede Route wie bisher antworten (Vorgabe
Holger). Deshalb verlangen die Exporte (`devices/export`, `device-events/export`) vorerst nur, Geräte
zu sehen (`search` oder `devices` Ansehen, beim Verlauf auch `log`), und `csv.export` gibt es noch
nicht als Aktion. F2 („*Benutzer* ohne CSV-Export“) ändert das Verhalten für *Benutzer* (heute 200,
dann 403; die App bietet ihm den Export nirgends an); entschieden am 05.11.2026: kommt mit Schritt 1.7
(siehe F2 unter „Entscheidungen“).

Schritt 1.3b (05.11.2026): `auth/me` mit `permissions`, Sitzungsdauer aus den Rechten
(`SessionClass`), Log-Mails und ihre Einstellung über `mail.log`, Migrationen 0039/0040 (freigegeben
durch Holger am 05.11.2026). Vorgaben Holger, je per Test belegt: Sitzungsdauer der Standard-Rollen
unverändert (Admin 7 / 30 Tage, Benutzer 30 / 90 Tage); jede Zeile der alten CHECK erfüllt die neue
(`NotifyDeviceLogCheckMigrationTest`); Log-Mails an genau dieselben Konten wie vorher (Abgleich mit
der alten Abfrage in `UserRepositoryTest`). Zweiter Probelauf zu 0036 durch Holger vorher: 0 Konten
zuzuordnen, 7 mit Rolle, 0 ohne Rolle, Super-Admin ohne Rolle. Ausschalten der Option geht immer,
Einschalten nur mit `mail.log` (bzw. als Super-Admin). `SessionRepository::deleteExpired` rechnet die
Inaktivität weiter nach Kontoart (für eigene Rollen eine Obergrenze; kürzere Sitzungen löscht
`resume` beim Antreffen).

Schritt 1.5 (05.11.2026) in zwei Teilen: **1.5a** Backend (API unter `service`, Schutzregeln,
`purge-device-data`), **1.5b** Seiten des Super-Admins mit Live-Test. Vorgaben Holger: *Admin* nicht
änderbar und nicht löschbar, *Benutzer* änderbar und nicht löschbar; Löschen nur ohne Konten und
offene Einladungscodes (409 mit Anzahlen); Speichern mit `version` (409 `version_conflict`), leerer
Filter 400, F3 409, Standorte und Typen nur aus derselben Stadt 400; jede Änderung ändert
`permissions.version`, Sitzungen bleiben (F1). Festgelegt bei der Umsetzung: Gelöschte
(anonymisierte) Konten zählen nicht als Personen und bekommen beim Löschen der Rolle *Benutzer*;
nicht mehr nutzbare Codes verlieren ihre Rolle (= *Benutzer*). `purge-device-data` entfernt die
Standortfilter der Rollen; sie behalten `filter_locations = 1` und sehen nichts, bis neu ausgewählt
wird. Dazu aus 1.4: `Permissions::none()` sieht nichts, feste Liste der Stellen mit
`DeviceVisibility::all()` per Test. Abnahme 1.5 durch Holger am 05.11.2026 mit den Festlegungen:
gelöschte Konten bekommen *Benutzer*, nicht nutzbare Codes verlieren die Rolle; Kopie „<Name>
(Kopie)“, „(Kopie 2)“ …, höchstens 50 Zeichen (gekürzt wird der Name der Vorlage), bei *Admin* mit
den heutigen Rechten; Übernehmen nur aus einer anderen Stadt.

Schritt 1.6 (05.11.2026) in zwei Teilen: **1.6a** Backend, **1.6b** Seiten der Benutzerverwaltung
mit Live-Test. Vorgaben Holger: Schutzregeln F5 (nur *Admin* vergibt oder entzieht *Admin*, niemand
ändert die eigene Rolle, der letzte aktive Admin bleibt, wer eine Rolle zuweist, braucht alle ihre
Rechte), F1 (Sitzungen enden nur beim Wechsel zu oder von *Admin*), Mehrfachauswahl nur „Rolle
zuweisen …“ mit Ergebnis je Konto (F6), Freischalten mit Rollenwahl, Einladungscodes mit Rolle ohne
*Admin* (ohne Rolle = *Benutzer*). Festgelegt bei der Umsetzung: Wer die Rolle eines Kontos ändert,
es sperrt, löscht oder umbenennt, braucht auch alle Rechte der bisherigen Rolle (Verallgemeinerung
von „nur *Admin* entzieht *Admin*“); der Katalog für „Sieht: …“ kommt mit `GET roles` (kein
`permission-catalog` unter Städten, die Route bleibt dem Super-Admin). In 1.6b: Der Aushang
nennt die Rolle nicht (nur Liste und der neue Code; der Admin kann sie beim Freischalten ändern);
der Hinweis im Fenster sagt nicht „das Handy lädt seine Offline-Kopie neu“, das kommt erst mit 1.7.

Schritt 1.7 (05.11.2026) in drei Teilen (Freigabe Holger 05.11.2026): **1.7a** Backend (Aktion
`csv.export` für die Exporte, F2: *Benutzer* bekommt dort 403), **1.7b** App nach Rechten
(`permissions` aus `auth/me` auch in der gespeicherten Sitzung, Seiten mit Rechten statt Rollen,
ohne Recht still zur Startseite der Rolle, Menü, Handy-Leiste, Knöpfe, Dashboard nur mit erlaubten
Abfragen, `selectable` in der Standortwahl), **1.7c** Rechte ändern sich während der Sitzung
(`auth/me` neu nach 403 und beim Zurückkehren in die App, bei neuer `permissions.version` Menü,
Leiste, Stores und Offline-Kopie neu, Eingaben bleiben; Warteschlange 403 → `failed`) mit
`X-Client-Api` 2. Jeder Teil mit CI, Deploy und Live-Test; die Liste für *Benutzer* am iPhone
(Konto #7) nach 1.7c. Ohne gespeicherte Rechte (Sitzung aus einer App vor 1.7, Start offline)
gelten bis zur Antwort des Servers die Rechte aus der Kontoart: `admin` alle, `user` die der
Standard-Rolle *Benutzer*.

Umgesetzt in 1.7c (05.11.2026): `auth/me` neu beim Zurückkehren in die App (höchstens alle 30 s) und
nach 403 `forbidden` (höchstens alle 5 s); neue `permissions.version` lädt alle schon geladenen Daten
neu, löscht die Offline-Kopie (Format 2 mit `permissions`, Format 1 wird noch gelesen) und verlässt
eine nicht mehr erlaubte Seite still zur Startseite, außer mit ungespeicherten Eingaben (die bleiben,
ein Speichern lehnt der Server ab). Warteschlange 403 → `failed` mit „Dafür fehlen Ihnen inzwischen die
Rechte.“. Der Hinweis im Fenster „Bearbeiten“ nennt jetzt „Handys laden dabei ihre Offline-Kopie
neu.“. `X-Client-Api` 2, `ClientApi::MIN` 1.

Festgelegt bei der Umsetzung von 1.7b (05.11.2026): „Einstellungen“ öffnet jede Rolle mit Konsole
(Anzeigemodus, Passwort, angemeldete Geräte); Farbe und Logo nur mit `settings` Ändern (mit Ansehen
nur ein Hinweis), die Benachrichtigung „Log“ nur mit `mail.log`. Startseite mit Konsole: Dashboard,
sonst die erste erlaubte Seite des Menüs, sonst „Einstellungen“; ohne Konsole „Suchen“, sonst
„Prüffristen“, sonst „Profil“. Ohne Recht auf das Ziel sind Kacheln, Geräte und Namen keine Links.
Eine Rolle nur mit „Termine“ liest die Prüffristen vom Server (`inspection-due`), ihre Karten öffnen
kein Umbuchen. Die Vorschau „So sieht es die Rolle“ (1.5b) nennt „Einstellungen“ entsprechend bei
jeder Rolle mit Konsole.

**`X-Client-Api`, Variante B** (Rückfrage vom 05.11.2026, Entscheidung Holger): `X-Client-Api` 2,
`ClientApi::MIN` bleibt 1. Begründung: Die API bleibt kompatibel (nur Erweiterungen; der Export
mit 403 für *Benutzer* trifft eine alte App nicht, sie bietet ihn *Benutzer* nie an); inkompatibel
ist nur die Bedeutung von `role` für die Oberfläche (eine alte App zeigt bei eigenen Rollen falsche
Menüs und Knöpfe, der Server verhindert falsche Buchungen). Mit MIN = 2 scheiterte während des
Deploys jede Anfrage einer alten App mit 409 `client_outdated` (offene Eingaben gingen beim Update
verloren); mit Variante B arbeitet sie weiter, jede Antwort trägt `X-Api-Version: 2`, die App prüft
sofort und aktualisiert beim nächsten Seitenwechsel. Über MIN = 2 wird in Schritt 1.8 entschieden,
bevor Immendingen eigene Rollen bekommt.

Bewusst **nicht** in Phase 1:

- `fe_users.role` wird weder entfernt noch umbenannt (wäre ⚠ und ist als Kontoart nützlich).
- Spalte für den **Zugang über Claude** (siehe unten) kommt erst mit der MCP-Phase.
- Keine Spalte für die Sitzungsdauer: Sie folgt aus den Rechten (Abschnitt 5).

Gleichbleibende Regel, nur im Code gesichert (kein Trigger): `fe_users.role = 'admin'` genau dann,
wenn `role_id` die Rolle mit `system_key = 'admin'` ist. Alle Schreibwege (`approve`, `changeRole`,
`register`, `inviteAdmin`, `createTenantWithInvitedAdmin`, `acceptInvitation`) setzen beides in
derselben Transaktion; ein Integrationstest prüft die Regel nach jedem Weg, ein weiterer über alle
Zeilen der Testdatenbank.

### Platz für den „Zugang über Claude“ (nur beschreiben, nicht bauen)

- **Datenmodell** (Migration der MCP-Phase, reine Erweiterung): `fe_users.claude_access`
  (`ascii_bin`, `off`/`read`/`write`, Standard `off`, CHECK; `deleted` → `off` beim Anonymisieren).
  Je Konto, nicht je Rolle. Sitzungen bekommen eine Herkunft (`fe_sessions.channel`: `app` Standard,
  `claude`), damit Tokens des Konnektors getrennt sichtbar und beendbar sind.
- **Rechteprüfung**: `Permissions` (Abschnitt 2) wird pro Anfrage aus Rolle und Sitzung gebildet.
  Für eine Sitzung mit `channel = claude` gilt `effektiv = Rolle ∩ Claude-Stufe`: `off` → jede Route
  403 (die Sitzung entsteht gar nicht erst), `read` → alle Bereiche höchstens `read`, keine Aktionen,
  `write` → die Rechte der Rolle. Datenfilter und Einschränkungen der Rolle gelten immer. Die Prüfung
  bleibt in `Application` (dieselbe Stelle wie für die App), Handler sehen nur das Ergebnis.
- **Gültigkeitsdauer**: wie die Sitzungsdauer der Rolle (Abschnitt 5, Inaktivität und Höchstdauer);
  OAuth-Tokens laufen spätestens mit der Höchstdauer ab, Sperren, Rollenwechsel und Passwortänderung
  beenden sie wie jede Sitzung.
- **Setzen**: entschieden, siehe F12 unter „Entscheidungen“.

## 2. Rechte-Katalog

Fest im Code (`src/Permission/`: `Area`, `AreaLevel` `none`/`read`/`write`, `Action`, `Restriction`,
`Permissions`, `Requirement`, `DeviceVisibility`). „Ändern“ schließt „Ansehen“ ein. Jede Aktion nennt
den Bereich, den sie braucht; fehlt er, wirkt sie nicht (Mockup „Rolle bearbeiten“: bleibt angehakt,
grau). Neue Einträge kommen mit ihrer Phase; ein Recht ohne geprüfte Route gibt es nicht. Die
Datenbank prüft nur das Format, ein Test, dass jede gespeicherte Zeile im Katalog steht.

### Bereiche

| Schlüssel | Oberfläche (Mockup) | Stufen | Phase |
| --- | --- | --- | --- |
| `dashboard` | Dashboard | kein Zugriff, Ansehen | 1 |
| `search` | Suchen und Ausrüstung ansehen (Handy, Scan, Umbuchen-Ansicht, Details und Verlauf) | kein Zugriff, Ansehen | 1 |
| `devices` | Ausrüstungsliste (Konsole: Liste, Detailseite, Verlauf) | kein Zugriff, Ansehen, Ändern | 1 |
| `locations` | Standorte | kein Zugriff, Ansehen, Ändern | 1 |
| `device_types` | Ausrüstungstypen | kein Zugriff, Ansehen, Ändern | 1 |
| `due` | Termine | kein Zugriff, Ansehen, Ändern | 1 |
| `log` | Protokoll und Hinweise | kein Zugriff, Ansehen, Ändern | 1 |
| `users` | Benutzer (Konten, Rollen zuweisen, Einladungscodes, Aushang) | kein Zugriff, Ansehen, Ändern | 1 |
| `settings` | Einstellungen der Stadt (Farbe, Logo) | kein Zugriff, Ansehen, Ändern | 1 |
| `maintenance` | Wartung | kein Zugriff, Ansehen, Ändern | 2 (vorgesehen) |
| `lending` | Verleih und Rückgabe | kein Zugriff, Ansehen, Ändern | 4 (vorgesehen) |

### Aktionen

| Schlüssel | Oberfläche | braucht | Phase |
| --- | --- | --- | --- |
| `book.move` | Umbuchen | `search` Ansehen | 1 |
| `book.status` | Status setzen | `search` Ansehen | 1 |
| `device.create` | Ausrüstung anlegen | `devices` Ändern | 1 |
| `device.edit` | Stammdaten bearbeiten | `devices` Ändern | 1 |
| `device.retire` | Aussondern und wieder aufnehmen | `devices` Ändern | 1 |
| `device.code` | Code zuordnen | `devices` Ändern | 1 |
| `devices.bulk` | Sammelaktionen | `devices` Ändern | 1 |
| `csv.import` | CSV-Import | `devices` Ändern | 1 |
| `csv.export` | CSV-Export | `devices` Ansehen | 1 |
| `mail.log` | Log-Mails empfangen | – | 1 |
| `book.used` | Benutzt melden | `search` Ansehen | 2 (vorgesehen) |
| `book.swap` | Tauschen | `search` Ansehen | 5 (vorgesehen) |
| `book.below_min` | Mindestbestand ohne Ersatz unterschreiten | `search` Ansehen | 5 (vorgesehen) |
| `lend` | Verleihen | `lending` Ändern | 4 (vorgesehen) |
| `intake` | Rückgaben bearbeiten | `lending` Ändern | 4 (vorgesehen) |

### Einschränkungen und Datenfilter

- `restrict_single_use` („Bei Einmalgeräten nur ‚Benutzt melden‘“): Spalte schon in 0029, wirksam
  und in der Oberfläche erst in Phase 2 (vorher gibt es keine Einmalgeräte).
- Datenfilter „Sieht nur …“: Standorte (Ebene 1 schließt Fächer ein, einzelne Fächer wählbar) und
  Ausrüstungstypen, je „Alle“ oder „Nur ausgewählte“. Regeln wie im Konzept Abschnitt 1.

### Bestehende Route → benötigtes Recht

Jede Route aus `Routes::tenant()`. „oder“ = eines genügt. Feldregeln prüft ebenfalls `Application`
(siehe Abschnitt 3), nachdem der Body gelesen ist, vor dem Handler.

| Route | heute | künftig |
| --- | --- | --- |
| `GET health` | öffentlich | öffentlich |
| `GET tenant` | öffentlich | öffentlich |
| `GET tenant/logo/{size}`, `GET app-icon/{size}`, `GET manifest.webmanifest` | öffentlich | öffentlich |
| `POST tenant/appearance` | Admin | `settings` Ändern |
| `POST tenant/logo`, `DELETE tenant/logo` | Admin | `settings` Ändern |
| `POST auth/login`, `auth/logout`, `auth/register`, `auth/email/verify`, `auth/email/resend`, `auth/invitation/accept`, `auth/password-reset/request`, `auth/password-reset/confirm` | öffentlich | öffentlich |
| `GET auth/me`, `GET auth/sessions`, `DELETE auth/sessions/{id}`, `POST auth/sessions/logout-others`, `POST auth/password` | angemeldet | angemeldet (jede Rolle) |
| `GET`/`POST auth/notifications` | Admin | Aktion `mail.log` |
| `GET client-config` | angemeldet | angemeldet (jede Rolle) |
| `GET invitation-codes` | Admin | `users` Ansehen |
| `POST invitation-codes`, `POST invitation-codes/{id}/revoke` | Admin | `users` Ändern |
| `GET users`, `GET users/{id}` | Admin | `users` Ansehen |
| `POST users/{id}/approve`, `reject`, `lock`, `unlock`, `role`, `display-name`, `DELETE users/{id}` | Admin | `users` Ändern (dazu Schutzregeln, Abschnitt 5) |
| `GET device-types` | angemeldet | `device_types` Ansehen oder `search` Ansehen oder `devices` Ansehen (Typen als Filter und Ziel) |
| `POST device-types`, `…/{id}/rename`, `deactivate`, `activate` | Admin | `device_types` Ändern |
| `GET locations`, `GET locations/{id}` | angemeldet | `locations` Ansehen oder `search` Ansehen oder `devices` Ansehen |
| `POST locations`, `…/{id}/rename`, `lock`, `unlock` | Admin | `locations` Ändern |
| `GET devices`, `GET devices/{id}`, `GET devices/{id}/events` | angemeldet | `search` Ansehen oder `devices` Ansehen |
| `GET devices/export` | angemeldet | `csv.export` (braucht `devices` Ansehen) |
| `GET device-events/export` | angemeldet | `csv.export` und `log` Ansehen (Reiter „Verlauf exportieren“ im Protokoll) |
| `GET device-events` | Admin | `dashboard` Ansehen oder `log` Ansehen („Letzte Aktivitäten“) |
| `POST devices` | Admin | `device.create` |
| `GET devices/import/template`, `GET devices/import/values`, `POST devices/import/preview`, `POST devices/import` | Admin | `csv.import` |
| `POST devices/bulk/inspection-due` | Admin | `devices.bulk` und `due` Ändern |
| `POST devices/bulk/state` | Admin | `devices.bulk`; Feldregel je Eintrag: `location_id` → `book.move`, `status` → `book.status` |
| `POST devices/{id}/fields` | Admin | `device.edit` oder `device.code`; Feldregel: `scan_code` → `device.code`, jedes andere Stammdatenfeld → `device.edit` |
| `POST devices/{id}/inspection-due` | Admin | `due` Ändern |
| `POST devices/{id}/state` | angemeldet | `book.move` oder `book.status`; Feldregel: `location_id` → `book.move`, `status` → `book.status` |
| `POST devices/{id}/retire`, `reinstate` | Admin | `device.retire` (bei `reinstate` mit `identifier`/`device_type_id`/`scan_code` zusätzlich `device.edit` bzw. `device.code`) |
| `GET device-summary` | angemeldet | `dashboard` Ansehen oder `due` Ansehen; `notices {open}` nur mit `log` Ansehen (heute: nur Admins) |
| `GET inspection-due` | angemeldet | `due` Ansehen |
| `GET notices`, `GET notices/{id}` | Admin | `log` Ansehen |
| `POST notices/{id}/resolve`, `reopen` | Admin | `log` Ändern |

Neue Routen der Phase 1 (Abschnitt 5): unter `service` nur Super-Admin; unter einer Stadt `GET
roles` (`users` Ansehen), `POST users/bulk/role` (`users` Ändern).

## 3. Umstellung von `Access` auf Rechte

- **Reihenfolge in `Application::handle` bleibt**: Adresse → Route → `RequestGuard` → Version der
  App → Stadt → Sitzung → **Recht** → Handler. Neu ist nur, was im letzten Prüfschritt steht:
  statt `Access::allows(role)` prüft `Requirement::allows(Permissions)`; danach die Feldregeln der
  Route gegen den gelesenen Body (Feld vorhanden, auch `null` → Aktion nötig). Abgelehnt → 403
  `forbidden` wie heute; keine neuen Fehlercodes.
- `Permissions` entsteht nach `SessionManager::resume` einmal je Anfrage aus der Rolle des Kontos
  (ein JOIN bzw. zwei kleine Abfragen; *Admin* ohne Abfrage: alle Rechte, kein Filter) und kommt
  über `AuthSession` in den `RequestContext` (`$context->permissions()`, `$context->visibility()`).
- Router: `Router::forTenant()->add(method, pfad, Access|Requirement, handler, maxBodyBytes,
  fieldRules)`. Unter einer Stadt sind nur `Access::Public`, `Access::Authenticated` (für die Routen
  `auth/*` und `client-config`, Liste fest im Router) und `Requirement` erlaubt; `Access::Admin`
  entfällt (falsche Kombination → `LogicException` beim Anlegen wie heute). Unter `service` bleibt
  alles bei `Access::SuperAdmin`.
- Handler prüfen weiterhin keine Rechte. Einzige Stellen, die `Permissions` lesen: die Datenfilter
  (über `DeviceVisibility`) und die Empfänger der Mails.
- **Tests ersetzen**: `AdminRoutesAccessTest` und `TenantReadRoutesAccessTest` gehen in
  `TenantRoutePermissionsTest` auf:
  1. Vollständigkeit: Jede Route aus `Routes::tenant()` steht in einer Tabelle des Tests (= Tabelle
     oben) mit ihrem Erfordernis; die Tabelle ist gleich dem, was die Routen deklarieren. Eine neue
     Route ohne Eintrag lässt den Test scheitern.
  2. Je Route: eine Rolle mit genau den nötigen Rechten (in der Testdatenbank angelegt) kommt am
     Rechte-Check vorbei (Status ≠ 403/401); für jedes einzelne nötige Recht eine Rolle, der genau
     dieses fehlt → 403 `forbidden`; ohne Sitzung 401; Konto einer anderen Stadt 401. Bei Routen mit
     Feldregeln zusätzlich je Feld.
  3. *Admin* kommt überall durch, *Benutzer* genau dort, wo heute `user` durfte (Ausnahmen nur nach
     Entscheidung F2).
  `NotificationSettingsTest` prüft weiter beide Bereiche; `SuperAdminRoutesAccessTest` bleibt und
  bekommt die neuen Routen.

## 4. Datenfilter je Rolle

Umsetzung: `DeviceVisibility` (alle oder Listen von Standort- und Typ-IDs; Standorte der Ebene 1
beim Bilden der Sichtbarkeit einmal je Anfrage um ihre Fächer erweitert) liefert ein SQL-Stück mit
Parametern (`AND d.device_type_id IN (…) AND d.location_id IN (…)`) bzw. nichts. Jede Methode der
Repositorys, die Geräte, Ereignisse oder Hinweise liest, verlangt sie als Pflichtargument (ohne
Standardwert, `DeviceVisibility::all()` ausdrücklich, z. B. für Kommandozeile und Mails an
Super-Admins). Ein Test geht per Reflection alle öffentlichen lesenden Methoden dieser Repositorys
durch und verlangt den Parameter. Ein Gerät außerhalb des Filters gilt wie ein fremdes: 404.

| Abfrage | Stelle | Umsetzung | Test mit/ohne Filter |
| --- | --- | --- | --- |
| Geräteliste, Suche `q`, `scan_code` | `DeviceRepository::list` (`GET devices`) | Filter im WHERE, `total` danach | Liste, `total`, `q` und `scan_code` treffen nur Sichtbare |
| Einzelgerät, Verlauf eines Geräts | `find`, `DeviceEventRepository::listForDevice` | unsichtbar → 404; Standorte außerhalb im Verlauf als „anderer Standort“ (`id` und `path` `null`, `name` „anderer Standort“) | 404; maskierter Standort im Verlauf |
| Umbuchen, Status | `DeviceRepository::book`/`changeState` (`POST devices/{id}/state`, `bulk/state`) | Gerät muss sichtbar sein (404), Ziel-Standort im Filter (sonst 400 wie unbekannt) | Buchung auf Ziel außerhalb 400, unsichtbares Gerät 404 |
| Ändern (Stammdaten, Prüfdatum, Aussondern, Wiederaufnehmen) | `lockForChange` | unsichtbar 404; neuer Typ/Standort im Filter | je Route 404/400 |
| Anlegen | `create` (`POST devices`) | Typ und Standort im Filter, sonst 400 | 400 |
| Sammelaktionen | `DeviceBulkHandler` | je Eintrag wie Einzelroute (Fehler im Ergebnis) | Eintrag außerhalb → `error` |
| Zähler je Standort/Typ | `LocationRepository::tree` (`device_count`, `device_count_total`), `DeviceTypeRepository::list` (`device_count`) | nur sichtbare Geräte zählen; Liste der Standorte nur im Filter, übergeordneter Standort eines erlaubten Fachs erscheint ohne Gerätezahl als nicht wählbar | Zahlen und Baum |
| Typen und Standorte als Auswahl | `GET device-types`, `GET locations` | nur im Filter (bei „Alle“ alle) | Listen |
| Dashboard | `DeviceRepository::summary` (`GET device-summary`), `notices {open}` | Filter in der Gruppierung; offene Hinweise nur zu sichtbaren Geräten | Zahlen |
| Termine | `listInspections` (`GET inspection-due`) | Filter in allen Abschnitten | Abschnitte und `total` |
| Letzte Aktivitäten | `DeviceEventRepository::listForTenant` (`GET device-events`) | Ereignisse von Geräten, die JETZT sichtbar sind (Regel: Typ und aktueller Standort); Standorte maskiert wie oben | Liste, `total` |
| Verlauf exportieren | `countHistory`/`exportHistory` | wie Letzte Aktivitäten; Grenze `MAX_ROWS` zählt nur Sichtbare | CSV-Zeilen, 409-Grenze |
| CSV-Export Geräte | `DeviceRepository::export` | Filter wie Liste | CSV-Zeilen |
| CSV-Import | `DeviceImportPlanner`, Vorlage, „Gültige Werte“ | Entschieden (F3): nur für Rollen ohne Datenfilter; mit Datenfilter wirkt `csv.import` nicht (403), der Import sieht daher immer alle Geräte (`DeviceVisibility::all()`) | Rolle mit Filter und `csv.import` in der Datenbank: 403 |
| Hinweise | `DeviceNoticeRepository::list`/`find` (`GET notices`, `{id}`, `resolve`, `reopen`) | nur Hinweise zu sichtbaren Geräten; `open` gezählt ebenso | Liste, Zahl, 404 |
| Mails „Log“, Hinweis-Mails | `UserRepository::listDeviceLogRecipients`, `DeviceLogNotifier` | Empfänger brauchen `mail.log`; je Empfänger dessen Sichtbarkeit: Einzelmail nur, wenn das Gerät für ihn sichtbar ist (vorher oder nachher), Sammelmail (Import, Sammelaktion) nur mit seinen Zeilen, ohne sichtbare Zeilen keine Mail. Super-Admins: alle. | Empfänger mit Filter bekommt keine bzw. gekürzte Mail |
| Offline-Kopie | `GET devices`, `GET locations` (Snapshot) | folgt aus den gefilterten Antworten; Wechsel der Rechte → Kopie neu (Abschnitt 7) | Browser-Test: Kopie enthält nur Sichtbares |
| Vergebene Kennung / Scan-Code | `identifier_taken`, `scan_code_taken` | bleiben stadtweit (UNIQUE-Index); die Meldung nennt kein Gerät, verrät nur „vergeben“ | Meldung ohne Gerätedaten |
| Sperren eines Standorts | `LocationRepository::lock` (Anzahl in `details`) | zählt alle Geräte (Regel der Datenbank), nur mit `locations` Ändern erreichbar; Vorschlag F3: Verwaltung nur ohne Datenfilter | – |

Ein gemeinsamer Integrationstest (`DeviceVisibilityMatrixTest`) legt zwei Typen × drei Standorte (mit
Fach) an, je ein Gerät, und ruft jede Zeile der Tabelle einmal mit der Rolle *Admin* und mit drei
Rollen auf (Vorgabe Holger 05.11.2026): nur Standortfilter „HLF 20“, nur Typfilter
„Atemschutzmaske“, beide (verknüpft mit UND).

Ergänzungen und Festlegungen zu Schritt 1.4 (Holger, 05.11.2026; umgesetzt 05.11.2026):

- **Leerer Filter**: `filter_locations` bzw. `filter_device_types` an, aber keine Standorte bzw. Typen
  gewählt: Die Rolle sieht nichts (keine Geräte, Standorte bzw. Typen, Hinweise, Verlauf; Anlegen
  400). Per Test je Filterart und für beide. Der Editor (Schritt 1.5) lässt das gar nicht speichern:
  400 `invalid_request` mit Meldung (z. B. „Bitte mindestens einen Standort wählen oder ‚Alle
  Standorte‘.“).
- **Masken überall**: Ein Standort außerhalb des Filters erscheint als „anderer Standort“ (`id` und
  `path` `null`, `name` „anderer Standort“) im Verlauf (Gerät, Letzte Aktivitäten, Export als Text),
  in Hinweisen (`booking.location`, `other.location`) und in den Mails „Log“ und Hinweis-Mails.
  `details.device` bei `version_conflict` und `device` in den Ergebnissen der Sammelaktionen zeigen
  nie einen Standort außerhalb: Ist das Gerät inzwischen außerhalb, gilt es wie fremd (404 ohne
  `details` bzw. Eintrag `error` `not_found` mit `device` `null`), ein sichtbares Gerät steht immer
  an einem sichtbaren Standort. Per Test.
- **Wiederholung und Warteschlange**: Wird eine gespeicherte `client_ref` wiederholt, nachdem das
  Gerät für das Konto unsichtbar wurde, ist die Antwort 404 (auch offline und in der Sammelaktion).
  Die Warteschlange der App setzt die Buchung dann auf `failed` (andere 4xx), nicht `waiting`. Beides
  per Test (`DeviceVisibilityMatrixTest`, `bookingQueue.spec.ts`).
- **Umsetzung abweichend vom Text oben**: Die Repositorys (`DeviceRepository`,
  `DeviceEventRepository`, `DeviceNoticeRepository`, `LocationRepository`, `DeviceTypeRepository`)
  verlangen `DeviceVisibility` im Konstruktor (Pflicht, ohne Standardwert) statt in jeder Methode;
  jede Instanz nennt den Filter also ausdrücklich, der Reflection-Test prüft die Konstruktoren
  (`DeviceVisibilityTest`). Handler übergeben `RequestContext::visibility()`, Kommandozeile, Import
  und Mails `DeviceVisibility::all()` (Mails maskieren je Empfänger).
- **F3 auch zur Laufzeit**: `Permissions` gibt einer Rolle mit Datenfilter `users` nicht,
  `locations`/`device_types` höchstens Ansehen und `csv.import` nicht, auch wenn die Datenbank sie
  enthält (zusätzlich zur Prüfung beim Speichern in 1.5).
- **Standorte**: Ein Standort der Ebene 1, von dem nur Fächer sichtbar sind, kommt mit `selectable`
  false und `device_count` `null` (neues Feld `selectable`, sonst true); als Ziel einer Buchung 400.
  Ein neues Fach eines gewählten Standorts der Ebene 1 gehört sofort dazu (`permissions.version`
  ändert sich). Die App liest `selectable` ab Schritt 1.7; bis dahin zählt sie `null` als 0.
- **Mails**: „vorher“ ist der Stand beim Sperren des Geräts (`DeviceEventRecorder::before`). Eine
  Sammelaktion, von der ein Empfänger nur ein Gerät sieht, ergibt für ihn die Mail zum Gerät.

## 5. Standard-Rollen, Schutzregeln, Einladungscodes, Seiten

### Standard-Rollen

- *Admin* (`system_key = admin`, Chip „fest“): alle Rechte inklusive künftiger, kein Filter, nicht
  änderbar, nicht löschbar, nicht kopierbar als Admin (Kopie wird eine normale Rolle).
- *Benutzer* (`system_key = user`, Chip „Standard“): heutiges Verhalten von `user`: `search`
  Ansehen, `due` Ansehen, `book.move`, `book.status`. Heute darf `user` per API auch CSV
  exportieren, die App bietet es ihm aber nirgends an (F2). Änderbar und löschbar wie im Mockup
  (Löschen nur ohne Personen und ohne offene Einladungscodes).
- Jede neue Stadt bekommt beide (`TenantRepository::create`).

### Sitzungsdauer je Rolle

Folgt aus den Rechten, ohne Einstellung (kein Weg, sie zu verlängern):

- **kurz** (Inaktivität 7 Tage, Höchstdauer 30 Tage, wie heute `admin`): *Admin* und jede Rolle mit
  `users` oder `settings` mindestens Ansehen, einem Bereich auf „Ändern“ oder einer Aktion außer
  `book.*` und `mail.log`.
- **lang** (30 Tage / 90 Tage, wie heute `user`): alle anderen (nur ansehen und buchen).
- Super-Admin unverändert 1 Stunde / 12 Stunden.

| Rolle | Sitzungsdauer |
| --- | --- |
| Admin | kurz (7 / 30 Tage) |
| Benutzer | lang (30 / 90 Tage) |
| Gerätewart (Vorschlag Immendingen) | kurz (7 / 30 Tage) |
| Geräteträger (Vorschlag Immendingen) | lang (30 / 90 Tage) |
| Zugang über Claude | wie die Rolle des Kontos |

`SessionLifetime` rechnet künftig mit der Klasse statt mit `UserRole`; `expires_at` wird beim
Anmelden aus der Klasse gesetzt, `resume` prüft zusätzlich `created_at + Höchstdauer der aktuellen
Klasse` (wird eine Rolle strenger, gilt die kürzere Höchstdauer sofort).

### Schutzregeln (Konzept Abschnitt 1)

- *Admin* hat immer alle Rechte und ist nicht änderbar (Server lehnt Änderungen mit 409
  `rule_violation` ab).
- Jede Stadt behält mindestens einen aktiven, nicht gesperrten Admin (bestehende Regel über
  `fe_users.role`, unverändert in `UserRepository`).
- Niemand vergibt Rechte, die er nicht hat: Wer eine Rolle zuweist, braucht alle Rechte dieser
  Rolle, und ihr Datenfilter muss in seinem liegen. *Admin* zuweisen und Admins sperren, löschen oder
  umstellen dürfen nur Admins. Freischalten und Einladungscodes ebenso (Rolle ⊆ eigene Rechte).
- Die eigene Rolle ist nicht änderbar (wie heute: sich selbst herabstufen 409).
- Rollen ändern und anlegen nur der Super-Admin (E1); der Admin weist nur zu.

### Einladungscodes mit Rolle

- `POST invitation-codes {…, role_id}` (optional, Standard *Benutzer*); *Admin* → 400 („Die Rolle
  ‚Admin‘ gibt es für Codes nicht …“, Text aus dem Mockup). `GET invitation-codes` mit `role {id,
  name}`. Wird eine Rolle gelöscht, darf kein aktiver Code auf sie zeigen (409).
- Registrierung setzt `role_id` des Kontos auf die Rolle des Codes; Freischalten zeigt sie
  vorausgewählt („registriert am … mit dem Aushang ‚Geräteträger‘“), der Admin kann sie ändern.
  `approve {display_name, role_id}` (bisher `role`; `role` bleibt einen Deploy lang erlaubt).
- Aushang und Liste nennen die Rolle („Rolle nach Freischaltung“).

### Seiten des Super-Admins (Mockup `Main`, `Rolle-bearbeiten`)

- Stadtdetail mit Reitern „Übersicht“, „Admins und Benutzer“ (heutiger Inhalt), „Rollen“.
- Reiter „Rollen“: Tabelle Rolle (Name, Chip „fest“/„Standard“, Kurzbeschreibung), Sieht, Darf,
  Sieht nur …, Personen, Aktionen (Ansehen bei *Admin*; sonst Bearbeiten, Kopieren, Löschen … nur
  ohne Personen); „Neue Rolle“, „Rolle aus anderer Stadt übernehmen …“, Kasten „Regeln“.
- Seite „Rolle bearbeiten“ (`staedte/<id>/rollen/<rolle>`): Name, Kurzbeschreibung; „1 · Ansichten
  und Bereiche“ (je Bereich Knöpfe Kein Zugriff/Ansehen/Ändern mit `aria-pressed`); „2 · Aktionen“
  (Kästchen nach Gruppen, grau mit „braucht ‚…: …‘“); „3 · Einschränkungen“ (erst ab Phase 2
  sichtbar); „4 · Sieht nur …“ (Standorte und Ausrüstungstypen, je Alle/Nur ausgewählte; „Nur
  ausgewählte“ ohne Auswahl lässt sich nicht speichern, der Server antwortet 400 mit Meldung, siehe
  Abschnitt 4); rechts
  „So sieht es die Rolle“ (Menü am PC, Leiste am Handy, Darf, Sieht Ausrüstung) und „Die Änderung
  gilt sofort für N Personen …“; Speichern, Abbrechen, „Rolle löschen …“ (gesperrt mit Personen).
  Ungespeichert über `useUnsavedInput`.
- API unter `service` (alle `Access::SuperAdmin`, in `SuperAdminRoutesAccessTest`): `GET
  tenants/{id}/roles` (mit Personenzahl), `POST tenants/{id}/roles` (neu bzw. Kopie per
  `copy_of`), `GET tenants/{id}/roles/{role}`, `POST tenants/{id}/roles/{role}` (ganzer Stand mit
  `version` gegen gleichzeitiges Bearbeiten, 409 `version_conflict`), `DELETE
  tenants/{id}/roles/{role} {}`, `POST tenants/{id}/roles/import {source_role_id}` (aus anderer
  Stadt: Rechte übernommen, Datenfilter nicht, weil sie Standorte der anderen Stadt nennen; F10).
  Dazu Katalog für die Oberfläche: `GET permission-catalog` (Bereiche, Stufen, Aktionen mit
  „braucht“, deutsche Bezeichnungen aus dem Code).

### Zuweisung durch den Admin (Mockup `Benutzer`, `Benutzer-Rolle`)

- Reiter „Konten“: Suche, Rollenfilter als Knöpfe mit Anzahl („Alle (7)“, „Admin (2)“ …,
  `aria-pressed`), Spalte „Rolle“ (Chip), Kästchen je Konto (nicht am eigenen), Leiste „N Konten
  ausgewählt“ mit „Rolle zuweisen …“ und „Auswahl aufheben“; Hinweis unten „Welche Rollen es gibt …
  legt der Super-Admin fest“.
- „Bearbeiten“ (`FormDialog`): Anzeigename, Rolle als Radiogruppe mit Beschreibung und „Sieht: …“,
  Hinweis bei *Admin*, „Die neue Rolle gilt beim nächsten Aufruf der App …“; abseits Sperren …,
  Löschen ….
- API: `GET roles` (Rollen der Stadt für die Auswahl: `id`, `name`, `description`, `system_key`,
  Zusammenfassung „Sieht“), `POST users/{id}/role {role_id}` (bisher `role`; bleibt einen Deploy
  lang erlaubt und wird auf *Admin*/*Benutzer* abgebildet), `POST users/bulk/role {user_ids,
  role_id}` (höchstens 50, je Konto eigene Transaktion und Ergebnis wie die Sammelaktionen der
  Geräte). `UserJson` zusätzlich `role_id`, `role_name`; `role` bleibt die Kontoart.
- `GET auth/me` zusätzlich `permissions {areas: {…}, actions: […], restrictions: […], filtered:
  bool, version}`; die App baut Menü, Leiste und Knöpfe daraus statt aus `role`.

## 6. Rollen für Immendingen (nur Vorschlag)

Nichts davon wird angelegt, bevor Holger es freigibt; Agenten testen nur in `test`. Vorschlag nach
Konzept Abschnitt 1 und Mockup (Spalte „Phase 2+“: kommt mit der jeweiligen Phase dazu):

| Bereich / Aktion | Admin | Gerätewart | Geräteträger | Benutzer (Standard) |
| --- | --- | --- | --- | --- |
| Dashboard | Ansehen | Ansehen | – | – |
| Suchen und Ausrüstung ansehen | Ansehen | Ansehen | Ansehen | Ansehen |
| Ausrüstungsliste | Ändern | Ändern | – | – |
| Standorte, Ausrüstungstypen | Ändern | Ändern | – | – |
| Termine | Ändern | Ändern | – | Ansehen |
| Protokoll und Hinweise | Ändern | Ändern | – | – |
| Benutzer | Ändern | – | – | – |
| Einstellungen der Stadt | Ändern | – | – | – |
| Umbuchen, Status setzen | ja | ja | ja | ja |
| Anlegen, Stammdaten, Aussondern, Code | ja | ja | – | – |
| Sammelaktionen, CSV-Import, CSV-Export | ja | ja | – | – |
| Log-Mails empfangen | ja | ja | – | – |
| Wartung, Verleih, Benutzt melden, Tauschen (Phase 2+) | ja | ja | Benutzt melden, Tauschen | – |
| Bei Einmalgeräten nur „Benutzt melden“ (Phase 2) | – | – | ja | – |
| Sieht nur … | alles | alles | alles | alles |
| Sitzungsdauer | 7 / 30 Tage | 7 / 30 Tage | 30 / 90 Tage | 30 / 90 Tage |

Heutige Konten in Immendingen bekommen durch 0036 nur *Admin* bzw. *Benutzer* (wie bisher). Wer
Gerätewart oder Geräteträger wird, legt der Admin danach fest.

## 7. Offline-Kopie, Warteschlange, `X-Client-Api`

- **Offline-Kopie**: Die Antworten von `GET devices` und `GET locations` sind schon gefiltert, die
  Kopie also auch. `auth/me` liefert `permissions.version` (SHA-256 über Rolle, Rechte, Filter und
  `fe_roles.updated_at`). Die App speichert sie im Snapshot (Format 2) und in der gespeicherten
  Sitzung; ändert sie sich, verwirft sie die Kopie und lädt neu (Mockup: „Handys laden dabei ihre
  Offline-Kopie neu“). Offline gelten die Rechte der gespeicherten Sitzung.
- **Warteschlange**: Buchungen werden beim Senden mit den aktuellen Rechten geprüft. 403
  `forbidden` → Zustand `failed` mit „Dafür fehlen Ihnen inzwischen die Rechte.“ (wie andere 4xx),
  Ziel außerhalb des Filters → 400 → `failed`. Kein neues Format der Einträge nötig
  (`QUEUE_FORMAT` bleibt 1).
- **`X-Client-Api`**: Alle Änderungen sind Erweiterungen (neue Felder, neue Routen, `role` bleibt).
  Eine alte App (n = 1) richtet Menü und Knöpfe aber nach `role` aus: Ein Gerätewart (Kontoart
  `user`) sähe dort nur die Handy-Seiten, ein Geräteträger die Termine (dann 403). Falsch buchen kann
  sie nicht (der Server prüft), aber sie zeigt Falsches. Entschieden (Variante B, 05.11.2026):
  **n = 2 im Schritt 1.7c**, mit dem die App auf Rechte umgestellt ist; **`ClientApi::MIN` bleibt 1**
  (über 2 wird in Schritt 1.8 entschieden). Eine alte App sieht `X-Api-Version: 2`, prüft sofort und
  lädt die neue Version beim nächsten Seitenwechsel (Phase 0).

## 8. Oberflächentexte

**Neu in Phase 1** (Begriffe nach Konzept Abschnitt 9):

- Super-Admin: Reiter „Übersicht“, „Admins und Benutzer“, „Rollen“; Tabelle „Rolle, Sieht, Darf,
  Sieht nur …, Personen“; Chips „fest“, „Standard“; „Neue Rolle“, „Rolle aus anderer Stadt übernehmen
  …“, „Bearbeiten“, „Kopieren“, „Löschen …“, „Ansehen“; Kasten „Regeln“; Seite „Rolle bearbeiten“
  mit den Abschnitten 1–4, Stufen „Kein Zugriff“, „Ansehen“, „Ändern“, Aktionen und Gruppen aus dem
  Katalog, „braucht ‚…‘“, „Alle Standorte“/„Nur ausgewählte“, „Alle Ausrüstungstypen“, „So sieht es
  die Rolle“, „Menü am PC“, „Leiste am Handy“, „Keine Konsole – nur die Handy-Seiten.“, „Die Änderung
  gilt sofort für N Personen …“, „Löschen geht erst, wenn niemand die Rolle hat (N Personen).“
- Admin: Rollenfilter „Rolle:“, „N Konten ausgewählt“, „Rolle zuweisen …“, Spalte „Rolle“, Dialog
  „<Name> bearbeiten“ mit Rolle und „Sieht: …“, Hinweise zu *Admin* und „gilt beim nächsten Aufruf“;
  Einladungscodes „Rolle nach der Freischaltung“, Hinweis „Die Rolle ‚Admin‘ gibt es für Codes nicht
  …“, „So läuft es ab“; Freischalten „… mit dem Aushang ‚…‘; die Rolle ist vorausgewählt“.
- Fehlermeldungen (deutsch, 409/400): Admin-Rolle nicht änderbar, Rolle mit Personen nicht löschbar,
  Rolle mit eigenen Rechten nicht vergebbar, Admin nicht für Codes.

**Eigener späterer Schritt „Begriffe umstellen“** (mittel, nach Phase 1, unabhängig von Rechten):
bestehende Oberfläche auf die Tabelle in Abschnitt 9.

| Bisher → neu | Betroffene Seiten und Bausteine | Tests |
| --- | --- | --- |
| Geräte → Ausrüstung, Gerätetyp(en) → Ausrüstungstyp(en) | `ADMIN_MENU`, `AdminDashboardPage`, `DevicesPage`, `DeviceDetailPage`, `DeviceNewPage`, `DeviceImportPage`, `DeviceTypesPage`, `LocationsPage` (Gerätezahl), `DevicePage`, `DeviceDetails`, `DeviceBulk*Dialog`, `Device*Dialog`, `SearchPage`, `InspectionsPage`, `NoticesPage`/`NoticePage`, `SettingsPage`-Vorschau, `MailTemplates` (Log-, Hinweis-Mails) | fast alle e2e-Dateien (Texte in Locatoren), `router.spec.ts`, `adminMenu`, Mail-Tests in PHP |
| Prüffristen → Termine | `UserTabBar`, `ADMIN_MENU`, `InspectionsPage`, Dashboard-Kachel | `inspections.spec.ts`, `adminConsole.spec.ts`, `booking.spec.ts` |
| Kennung → Nummer (Suche, Scan-Felder, Handy) | `SearchPage`, `CodeScanner`, `DevicePage`, `DeviceCard` | `search`, `scanner.spec.ts`, `booking.spec.ts` |
| Wartung/Prüfung → Wartung, Neudatum → „in Betrieb seit“ | erst mit Phase 3 | – |

Offen dazu: F14 (Adressen wie `geraete`, `prueffristen` und Spalten der CSV).

## 9. Schritte, Reihenfolge, Risiken, offene Fragen

| Schritt | Inhalt | Größe | Live/CI vor dem nächsten |
| --- | --- | --- | --- |
| 1.1 | Migrationen 0029–0035 (nur Erweiterungen), `RoleRepository`, Standard-Rollen bei neuen Städten, Tests. Kein Verhalten ändert sich. | mittel | Deploy mit Migration, live prüfen |
| 1.2 ⚠ | Migration 0036 (Konten auf Rollen), 0037 (CHECK) und 0038 (Rolle der Einladungscodes; aus 1.1 hierher, weil Migrationen lückenlos nummeriert sein müssen), Regel Kontoart ↔ Rolle in `UserRepository`. **Freigabe nötig.** | klein | Deploy mit Sicherung, live prüfen |
| 1.3 | Katalog, `Permissions`, `Requirement`, Feldregeln, Prüfung in `Application`, `TenantRoutePermissionsTest` statt der zwei Access-Tests, `auth/me` mit `permissions`, Sitzungsdauer aus den Rechten, Migrationen 0039/0040. Für *Admin*/*Benutzer* gleiches Verhalten. Geteilt (05.11.2026): **1.3a** Rechte im Backend (Katalog bis `TenantRoutePermissionsTest`; die zwei Access-Tests bleiben zusätzlich), **1.3b** `auth/me` mit `permissions`, Sitzungsdauer aus den Rechten, Log-Mails und Einstellung über `mail.log`, Migrationen 0039/0040. Jeder Teil mit CI, Deploy und Live-Test. | groß | CI (alle Routen) |
| 1.4 | Datenfilter in allen Abfragen und Mails, `DeviceVisibilityMatrixTest`, Reflection-Test | groß | CI |
| 1.5 | Super-Admin: API und Seiten „Rollen“, „Rolle bearbeiten“, Kopieren, Übernehmen, Löschen | mittel | live in `test` |
| 1.6 | Admin: `GET roles`, Rolle zuweisen (einzeln, mehrere), Freischalten und Einladungscodes mit Rolle | mittel | live in `test` |
| 1.7 | App auf Rechte umstellen (Menü, Wächter, Handy-Leiste, Knöpfe, Offline-Kopie mit `permissions.version`), `X-Client-Api` 2 mit `MIN` 1 (Variante B); Aktion `csv.export` für die Exporte (F2, *Benutzer* dann 403). Geteilt in 1.7a/b/c (siehe oben) | mittel | je Teil live in `test`, Handy (Holger) nach 1.7c |
| 1.7d | „Schnellstart“: Start ohne Warten auf die Update-Prüfung, Angemeldete sofort mit Sitzung und Offline-Kopie, Zielseite parallel zu `tenant`/`auth/me`, Startbild, Messung vorher/nachher (siehe „Entscheidungen“). Erst nach 1.7c | mittel | live in `test` mit Messung, Handy (Holger) |
| 1.8 | Live-Test aller Rollen in `test` (LIVETEST-Datei), Entscheidung über `ClientApi::MIN` = 2, Rollen für Immendingen nur nach Freigabe durch Holger | klein | – |
| 1.9 | „Konto wechseln“ (Kontoliste auf dem Gerät, Menü und Profil, siehe „Entscheidungen“) | mittel | live in `test` (zweite Stadt nur lokal/im Test) |

Reihenfolge: 1.1 → 1.2 → 1.3 → 1.4 → 1.5 → 1.6 → 1.7 (a → b → c → d) → 1.8 → 1.9. 1.5/1.6 können neue Rollen erst
anlegen, wenn 1.3 und 1.4 live sind (sonst wirkte ein Filter nicht).

**Risiken**

- Eine vergessene Abfrage ohne Filter zeigt Daten, die eine Rolle nicht sehen soll (Pflichtargument,
  Reflection-Test, Matrix-Test).
- Rechteausweitung über „Benutzer: Ändern“ einer Nicht-Admin-Rolle (Regel „nur Rechte, die man hat“,
  Tests je Schutzregel).
- Feldregeln in `Application` sind neu; eine fehlende Regel erlaubt z. B. Code zuordnen mit nur
  `device.edit` (Tests je Feld).
- Migration 0036 auf Produktivdaten (Sicherung, Probelauf `--dry-run`, Zählung vorher/nachher).
- Laufzeit: zusätzliche Abfragen je Anfrage für die Rolle; IN-Listen bei großen Filtern (eine
  Feuerwehr hat wenige Standorte, unkritisch).
- Alte Apps zeigen bis zum Update Falsches (n = 2 in 1.7c löst die sofortige Prüfung aus; MIN = 2
  entscheidet 1.8).
- CI wird länger (Matrix-Test, Rechte-Test je Route).
- 1.7d ändert den Start der Update-Logik; sporadische Fehler dort nur unter Last suchen
  (`npm run test:load`), nie mit längeren Wartezeiten überdecken.

**Fragen** (alle entschieden am 05.11.2026, siehe „Entscheidungen“)

- F1: Beendet ein Rollenwechsel die Sitzungen? Heute ja (CLAUDE.md); das Mockup sagt „gilt beim
  nächsten Aufruf“. Vorschlag: Wechsel von oder zu *Admin* beendet sie (wie heute), andere nicht.
- F2: Standard-Rolle *Benutzer*: CSV-Export (heute per API erlaubt, in der App nie angeboten)
  behalten oder nicht? Vorschlag: nicht.
- F3: Dürfen Rollen mit Datenfilter verwalten (Standorte, Typen, CSV-Import, Benutzer)? Vorschlag:
  nein, Verwaltungsrechte nur ohne Filter (spart viele Sonderfälle, z. B. Sperren eines Standorts mit
  unsichtbaren Geräten).
- F4: Einladungscodes im Mockup: „Gültig bis“ als Datum statt „Gültig (Tage)“; Bezeichnung und
  „Registrierungen höchstens“ fehlen. Bleiben die heutigen Felder? Der Code erscheint verkürzt
  („7KQ4-…-M2PX“) mit „Aushang drucken“ und „Code kopieren“ bei bestehenden Codes; der Server kennt
  aber nur den Hash (heute bewusst kein Nachdruck). Teile des Codes speichern schwächt ihn
  (4 von 12 Zeichen unbekannt). Vorschlag: Bezeichnung statt Codeteilen, kein Nachdruck.
- F5: Darf eine Nicht-Admin-Rolle „Benutzer: Ändern“ bekommen (das Mockup erlaubt es)? Wenn ja, gelten
  die Schutzregeln aus Abschnitt 5; Vorschlag: ja, aber Admins bleiben für sie unantastbar.
- F6: Mehrfachauswahl der Konten: nur „Rolle zuweisen …“ (Mockup) oder auch Sperren?
- F7: Was zeigt der Reiter „Übersicht“ im Stadtdetail des Super-Admins (Mockup ohne Inhalt)?
  Vorschlag: der heutige Kopf (Name, Status, Zahlen, Umbenennen, Sperren).
- F8: Handy-Leiste: Das Mockup zeigt „Dashboard“, sobald die Rolle irgendeine Seite der Konsole hat.
  Bleibt „Dashboard“ der Eintrag, auch wenn die Rolle `dashboard` nicht sehen darf (dann erste
  erlaubte Seite)?
- F9: Log-Mails für Gerätewart: „Log-Mails empfangen“ ist eine Aktion der Rolle; die persönliche
  Einstellung (an/aus) bleibt zusätzlich (Phase 6 erweitert sie). Einverstanden?
- F10: „Rolle aus anderer Stadt übernehmen“: Rechte ja, Datenfilter nein (Standorte gehören der
  anderen Stadt). Einverstanden?
- F11: Sitzungsdauer aus den Rechten (kurz/lang wie oben) statt einer Einstellung je Rolle?
- F12: Zugang über Claude: Wer setzt ihn (Admin der Stadt im Dialog „Bearbeiten“, die Person selbst
  im Profil, beide)? Gilt er auch für Super-Admins?
- F13: Prüfdatum einzeln ändern (Gerätedetail) = „Termine: Ändern“ (Vorschlag) oder eine eigene
  Aktion?
- F14: Begriffe umstellen: Bleiben Adressen (`geraete`, `prueffristen`, Mail-Links) und die
  Spaltennamen der CSV (Format des Imports) unverändert? Vorschlag: Adressen bleiben, CSV nimmt alte
  und neue Überschriften an.
