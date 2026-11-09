# Plan Phase 2: Standort-Aufgaben, Wartung, Verleih, Sollbestand, Erinnerungen

Stand: 08.11.2026 (freigegeben von Holger am 08.11.2026 mit den Entscheidungen unten; Bau ab Runde 8). Grundlage:
[`AENDERUNGSKONZEPT-2026-11.md`](AENDERUNGSKONZEPT-2026-11.md) (Abschnitte 1–3, 5–7, 9–12,
Entscheidungen E1–E14), Plan Phase 1 ([`PLAN-PHASE-1-ROLLEN.md`](PLAN-PHASE-1-ROLLEN.md), umgesetzt),
Claude-Zugang ([`PLAN-MCP.md`](PLAN-MCP.md)), Mockups
[`aenderungskonzept/mockups/`](aenderungskonzept/mockups/LIESMICH.md), `CLAUDE.md`.

Umfang (Auftrag Holger, Runde 6): Standort-Aufgaben, mehrstufige Wartung (mit Status „benutzt“ und
Einmalgeräten), Verleih und Rückgabe, Sollbestand und Tausch, Erinnerungs-Mails, Einschränkungen der
Rollen, Geräteträger. Das entspricht den Phasen 2, 4, 5 und 6 des Konzepts plus den Erinnerungen
(E9). **Nicht** enthalten: Wartungsintervall je Typ mit mehreren Wartungen (Konzept Phase 3,
Abschnitt 4), Schlauchlager (Phase 8), „Wege der Ausrüstung“ als Grafik und steuernde Wege
(Abschnitt 10, „in Prüfung“), „Seiten anpassen“ (Abschnitt 9) – siehe „Offene Fragen“ G1, G2, G3.

Begriffe in der Oberfläche nach Konzept Abschnitt 9 (Ausrüstung, Termine, Wartung, Rückgabe,
„Ausgeliehen an“, „in Betrieb seit“, Sollbestand, Sammelstelle, Einmalgerät, außer Betrieb).
Im Plan stehen, wie im Konzept, teils die Arbeitsbegriffe (Gerät, Annahme, Leihnehmer).

⚠ = braucht vor dem Bau eine Entscheidung oder ändert eine Regel aus `CLAUDE.md`.
**[Daten]** = Migration ändert bestehende Daten (Rückfrage vor dem Bau, `CLAUDE.md` "Autonomer
Ablauf"). Alle anderen Migrationen sind reine Erweiterungen.

## Kurzfassung

- **Standort-Aufgaben** fest im Code (`LocationTask`: Lager, Sammelstelle, Werkstatt, Rückgabe,
  Ausgeliehen an), je Stadt vom Super-Admin freigeschaltet (Anzeigename änderbar), vom Admin an
  Standorte der Ebene 1 vergeben. Ohne Aufgaben bleibt alles wie heute (Konzept Abschnitt 9).
- **Status „benutzt“** (`used`) neu. Das ändert die Bedeutung der API: `X-Client-Api` 3, alte Apps
  zeigen ihn nicht → `ClientApi::MIN` 3 nach Rückfrage ⚠ (Schritt P2.1).
- **Wartung als Ablauf**: Benutzt melden → Sammelstelle → Werkstatt → Lager, Seite „Wartung“ mit
  Sammelaktionen je Stufe; **Einmalgeräte** am Typ; die Einschränkung „bei Einmalgeräten nur benutzt
  melden“ (`fe_roles.restrict_single_use`, schon seit Migration 0029 vorhanden) wird wirksam.
- **Verleih** als eigene Buchung auf „Ausgeliehen an“, **Rückgabe ohne Anmeldung** über einen
  geheimen Link je Rückgabe-Standort (Aushang), „Rückgaben bearbeiten“.
- **Sollbestand** je Standort (Zeilen mit einem oder mehreren gleichwertigen Typen), „außer
  Betrieb“ berechnet, **Tausch** als eine Anfrage (online und offline, „die Wirklichkeit gewinnt“).
- **Mail-Auswahl je Person** (Themen) nur für Mails, die eine Aktion auslöst. **Keine Erinnerungs-Mails
  und kein externer Zeitgeber** (Entscheidung Holger 08.11.2026, G12): „Termine fällig“ und „Rückgabe
  überfällig“ zeigt die App (Dashboard, Termine, Verleih). Erinnerungen stehen als Wunsch in
  `TODO-SPAETER.md` (T19). „Keine Cronjobs“ in `CLAUDE.md` bleibt unverändert.
- **Geräteträger**: keine Standard-Rolle, sondern eine Rolle, die der Super-Admin anlegt (E1, E2);
  neue Aktionen `device.used`, `device.swap`, Einschränkung „Einmalgeräte nur benutzt melden“.
- 13 Schritte (P2.1–P2.13), Vorschlag sechs Runden (Abschnitt 9). Keine Migration mit
  Datenänderung ist zwingend; zwei optionale sind markiert.

## 1. Rechte-Katalog: Erweiterungen

Neue Einträge nur mit ihrem Schritt und einer Route, die sie prüft (`CLAUDE.md`, „Rollen und
Rechte“). Bezeichnungen im Editor der Rollen nach Mockup `Rolle-bearbeiten`.

| Art | Wert | Bezeichnung | braucht | Schritt |
| --- | --- | --- | --- | --- |
| Bereich | `maintenance` | Wartung (Seite „Wartung“, Stufen) | – | P2.4 |
| Bereich | `loans` | Verleih und Rückgaben | – | P2.5 |
| Aktion | `device.used` | Benutzt melden | `search` Ansehen | P2.3 |
| Aktion | `device.swap` | Tauschen | `search` Ansehen | P2.8 |
| Aktion | `loan.lend` | Verleihen | `loans` Ändern | P2.5 |
| Aktion | `returns.process` | Rückgaben bearbeiten | `loans` Ändern | P2.6 |
| Aktion | `stock.override` | Sollbestand ohne Ersatz unterschreiten (E7) | `book.move` | P2.8 |
| Einschränkung | `single_use_used_only` | Bei Einmalgeräten nur „benutzt melden“ | – | P2.3 |

- Einschränkungen erscheinen in `permissions.restrictions` von `auth/me` (bisher leer) und im
  Editor als „3 · Einschränkungen“ (Mockup `Rolle-bearbeiten`, bisher ausgeblendet).
- Datenfilter (Konzept Abschnitt 1, Regel 3): Ziele mit fester Aufgabe, die der Ablauf vorgibt
  (Sammelstelle, Rückgabe, Standard-Lager), sind auch außerhalb des Filters wählbar – aber nur über
  die Abläufe (benutzt melden, Annahme), nicht über das freie Umbuchen. Sichtbar wird das Gerät dort
  nicht (es verlässt den Filter). ⚠ G4.
- F3 bleibt: mit Datenfilter keine Verwaltung von Standorten, Typen, Sollbestand.
- Claude-Zugang: Die neuen Bereiche und Aktionen gelten über `Permissions::forClaude` wie die
  anderen; Werkzeuge dafür kommen erst mit P2.12.

## 2. Datenmodell und Migrationen

Nummern ab 0049 (0047/0048 belegt der Vermerk „über Claude“ aus M6). Eine DDL-Anweisung je
Migration; Änderungen an CHECK-Bedingungen als DROP und ADD in EINER `ALTER TABLE` wie 0008–0010.

| Nr. | Schritt | Inhalt | Art |
| --- | --- | --- | --- |
| 0049 | P2.1 | `fe_devices`: CHECK `chk_fe_devices_status` um `used` erweitern | Erweiterung (Bedingung) |
| 0050 | P2.1 | `fe_device_events`: CHECK für `from_status`/`to_status` um `used` | Erweiterung (Bedingung) |
| 0051 | P2.1 | `fe_device_notices`: CHECK für `requested_status`/`other_status` um `used` | Erweiterung (Bedingung) |
| 0052 | P2.2 | `fe_tenant_location_tasks` (`tenant_id`, `task` ascii, `label` bis 50, `enabled`; Primärschlüssel `(tenant_id, task)`) | neue Tabelle |
| 0053 | P2.2 | `fe_locations.task` (ascii, NULL = ohne Aufgabe; CHECK Werte, nur Ebene 1), `fe_locations.is_default_store` (0/1), `collection_accepts` (`used`/`defective`/`both`, nur bei Sammelstelle) | Erweiterung (Spalten, Standard NULL/0) |
| 0054 | P2.3 | `fe_device_types.single_use` (0/1, Standard 0) | Erweiterung |
| 0055 | P2.3 | `fe_device_events`: CHECK `event_type` um `used_reported` | Erweiterung (Bedingung) |
| 0056 | P2.4 | `fe_devices.maintenance_stage_since` (DATETIME NULL; seit wann in der Stufe, aus dem letzten `state_changed` ableitbar – nur Zwischenspeicher für die Liste) | Erweiterung; ⚠ G5: statt Spalte aus dem Verlauf berechnen |
| 0057 | P2.5 | `fe_device_loans` (`tenant_id`, `device_id`, `borrower_location_id`, `contact` bis 100, `due_back_on` DATE NULL, `comment`, `lent_by`, `lent_at`, `returned_at` NULL, `return_event_id` NULL; höchstens ein offener Verleih je Gerät über generierte Spalte + UNIQUE) | neue Tabelle |
| 0058 | P2.5 | `fe_device_events`: CHECK `event_type` um `lent`, `returned`; Spalten `loan_id` NULL, `actor_name` NULL (Überbringer bei der Rückgabe ohne Anmeldung, bis 100), `user_id` NULL nur bei `returned` ohne Anmeldung | Erweiterung ⚠ G6 (`user_id` bisher immer gesetzt) |
| 0059 | P2.6 | `fe_return_points` (`tenant_id`, `location_id` (Rückgabe), `secret_hash` 32 Byte UNIQUE, `created_by`, `created_at`, `revoked_at`) | neue Tabelle |
| 0060 | P2.6 | `fe_auth_attempts`: CHECK `action` um `public_return` (Bremse) | Erweiterung (Bedingung) |
| 0061 | P2.7 | `fe_stock_rules` (`tenant_id`, `location_id`, `min_count` 1–99, `only_listed_types` 0/1, `position`) und 0062 `fe_stock_rule_types` (`rule_id`, `device_type_id`) | neue Tabellen |
| 0063 | P2.7 | `fe_location_outages` (`tenant_id`, `location_id`, `rule_id`, `started_at`, `ended_at` NULL, `started_event_id`, `notice_id`) – Beginn und Ende von „außer Betrieb“ für den Verlauf | neue Tabelle |
| 0064 | P2.8 | `fe_device_events.swap_id` (NULL; beide Buchungen eines Tauschs tragen dieselbe Kennung) und `fe_device_notices.kind` um `swap_replacement_moved`, `stock_below_minimum` | Erweiterung |
| 0065 | P2.10 | `fe_user_mail_prefs` (`user_id`, `tenant_id`, `topic` ascii; Zeile = angehakt) | neue Tabelle |
| 0066 | P2.10 | **[Daten, optional]** Konten mit `notify_device_log = 1` bekommen alle Themen (INSERT … SELECT). Vorschlag G7: nicht migrieren, `notify_device_log = 1` gilt als „alles angehakt“, bis die Person selbst wählt | optional |
| ~~0067~~ | entfällt | ~~`fe_reminder_log` (`tenant_id`, `kind` `due`/`loan_overdue`/`daily`, `subject_id`, `due_on`, `sent_at`; UNIQUE je Art, Gegenstand und Fälligkeit: keine doppelte Erinnerung)~~ | entfällt (G12) |
| 0068 | P2.13 | **[Daten, optional]** Startbelegung Immendingen (Aufgaben, Standard-Lager) per Migration. Vorschlag: nicht per Migration, sondern Holger richtet in der App ein (wie Rollen in 1.8b) | optional, Vorschlag: entfällt |

Keine Migration ändert vorhandene Zeilen zwingend. Neue Status-Werte und Ereignisarten erweitern nur
CHECK-Bedingungen.

## 3. Abläufe und Regeln

### 3.1 Standort-Aufgaben (P2.2; Mockup `Standorte-Aufgaben`)

- Katalog `LocationTask` (`store` Lager, `collection` Sammelstelle, `workshop` Werkstatt, `returns`
  Rückgabe, `borrower` Ausgeliehen an). Nur Ebene 1 hat eine Aufgabe; Fächer erben sie nicht ⚠ G8.
- Super-Admin: im Stadtdetail Reiter „Abläufe“ (neu): je Aufgabe ein/aus und Anzeigename.
- Admin: in „Standorte“ beim Bearbeiten „Aufgabe“ (nur freigeschaltete) und „Standard-Lager“ (genau
  einer, wenn es Lager gibt); bei der Sammelstelle „nimmt an: benutzte / defekte / beide“.
- Aufgaben ändern keine Buchung, solange kein Ablauf sie nutzt. Eine Aufgabe ist nur entfernbar,
  wenn der Ablauf sie nicht mehr braucht (z. B. offene Verleihe) – 409 mit Anzahl.

### 3.2 Status „benutzt“, Einmalgeräte, Benutzt melden (P2.1, P2.3; Mockup `Handy-Benutzt`)

- `DeviceStatus::Used` („benutzt“) in allen Listen, Filtern, Zählern, CSV (Spalte Status, Gültige
  Werte), Dashboard (zählt als nicht einsatzbereit), Offline-Kopie. Termine unverändert.
- „Benutzt melden“ (`POST devices/{id}/used {version, comment?, client_ref?, occurred_at?}`): Status
  `used`; hat die Stadt eine passende Sammelstelle, Ziel = Sammelstelle (E8), sonst bleibt der
  Standort. Ist der bisherige Standort mit Sollbestand belegt, verlangt die App den Tausch (P2.8).
- Einmalgerät = Typ mit `single_use`. Mit Einschränkung `single_use_used_only` lehnt der Server für
  solche Geräte `POST devices/{id}/state` und Sammelbuchungen ab (403 `forbidden` mit Meldung
  „Für Einmalgeräte nur „Benutzt melden““) ⚠ G9: eigener Code `restricted_action` statt 403?
- Offline: „Benutzt melden“ in die Warteschlange wie Umbuchen (gleiche Regeln, `client_ref`).

### 3.3 Seite „Wartung“ (P2.4; Mockup `Wartung`)

- Stufen nach Aufgabe des Standorts: In der Sammelstelle (benutzt/defekt), In der Werkstatt (in
  Wartung/defekt), je mit Anzahl und „seit“. Sammelaktionen: „Zur Werkstatt“ (Ziel Werkstatt, Status
  `maintenance`, Transportliste drucken/CSV), „Zurück aus der Werkstatt“ (Scannen hakt an, „Alle
  auswählen“; Ziel Standard-Lager, Status `ready`, ⚠ G1: „letzte Wartung = heute“ gibt es erst mit
  Konzept Phase 3; bis dahin optional „Wartungsdatum setzen“ wie die Sammelaktion).
- Server: neue Sammelroute nicht nötig; die App nutzt `devices/bulk/state` und
  `devices/bulk/inspection-due` mit dem Bereich `maintenance` als zusätzliches Erfordernis ⚠ G10
  (Alternative: eigene Routen `maintenance/…`, damit ein Gerätewart ohne `devices.bulk` die Stufen
  bedienen kann – Vorschlag: eigene Routen).

### 3.4 Verleih und Rückgabe (P2.5, P2.6; Mockups `Verleih`, `Handy-Rueckgabe`, `Annahme`)

- Verleihen (`POST loans {devices: [{id, version}], borrower_location_id, contact?, due_back_on?,
  comment?}`, `loan.lend`): Ort = „Ausgeliehen an“, Status bleibt; je Gerät ein Eintrag `lent`. Nur
  Geräte ohne offenen Verleih, nicht ausgesondert.
- Übersicht „Verliehen“ (`GET loans?status=open`): je Leihnehmer, „verliehen seit“, „Rückgabe bis“
  (überfällig rot), Ansprechpartner; Kachel im Dashboard „Verliehen: 7, davon 2 überfällig“, roter
  Zähler im Menü.
- Rückgabe ohne Anmeldung: öffentliche Seite `rueckgabe#schluessel=…` (Schlüssel im Fragment wie
  `#token`), API `POST api/v1/returns/check {key, code}` (nur Nummer und Ausrüstungstyp eines
  verliehenen Geräts) und `POST api/v1/returns {key, code, name, condition (ok|used|defective),
  comment?}`; Bremse wie die Anmeldung (`public_return`, je IP-Präfix); bucht in den
  Rückgabe-Standort, Verlauf „Rückgabe durch <Name>, ohne Anmeldung“. Andere Buchungen sind über
  diesen Zugang unmöglich (eigene Routen, `Access::Public`, keine Sitzung). Schlüssel erneuern =
  alter ungültig, neuer Aushang. CSP und Service Worker: Seite ohne Anmeldung, offline nicht
  verfügbar.
- Rückgaben bearbeiten (`returns.process`): Liste „In der Rückgabe“, Knöpfe nach Zustand
  („Defekte in die Kiste“ → Sammelstelle für defekte, „In Ordnung ins Lager“), Vorschlag je Gerät.
- „Seiten anpassen“ (Immendingen ohne „Rückgabe bis“): ⚠ G2, Vorschlag zunächst eine einzige
  Stadt-Einstellung „Rückgabefrist verwenden“.

### 3.5 Sollbestand und Tausch (P2.7–P2.9; Mockups `Mindestbestand`, `Handy-Tausch`)

- Regeln je Standort (Ebene 1 oder Fach): Zeilen mit Soll-Anzahl und einem oder mehreren
  gleichwertigen Typen („oder“, zählen zusammen), optional „nur diese Typen“. Gezählt werden
  einsatzbereite Geräte ⚠ G11 (auch „in Wartung“ am Fahrzeug?).
- „Außer Betrieb“ wird berechnet (nicht gesetzt): Zeile unter Soll. Beginn und Ende stehen in
  `fe_location_outages` (geschrieben in der Transaktion der Buchung, die den Zustand ändert),
  sichtbar am Fach, am Fahrzeug („1 Fach außer Betrieb“), im Dashboard und als Hinweis im Protokoll.
- Tausch (`POST devices/swap {out: {id, version}, in: {id, version}, out_target?, comment?,
  client_ref?, occurred_at?}`, `device.swap`): eine Transaktion, beide oder keine; der Ersatz muss zur
  selben Zeile passen; „Heraus“ standardmäßig in die Sammelstelle (Status `used`). Vorschläge für
  „Hinein“: einsatzbereite Geräte derselben Zeile aus dem Lager.
- Jede Buchung, die den Sollbestand unterschreiten würde (Einzel, Sammel, Verleih, Import), wird ohne
  `stock.override` mit 409 `rule_violation` (`details {location, rule, missing}`) abgelehnt; mit
  `stock.override` nach Rückfrage (`confirmed_below_minimum: true`) gebucht und als Hinweis
  protokolliert. Der Import warnt in der Vorschau.
- Offline (P2.9): Tausch als eine Einheit in der Warteschlange; kommt er an und ist der Ersatz
  inzwischen woanders, bucht der Server trotzdem und legt einen Hinweis an („Wirklichkeit gewinnt“,
  Konzept Abschnitt 6). Unterschreitung offline: gebucht, Hinweis, keine Ablehnung.

### 3.6 Mail-Auswahl (P2.10; Mockup `Benachrichtigungen`)

- Themen (`MailTopic`): defekt gemeldet, benutzt gemeldet, Rückgabe eingegangen, verliehen,
  Sollbestand unterschritten oder neuer Hinweis, sonstige Umbuchungen und Status, Stammdaten /
  Wartungsdatum / Import; Wählbar mit `mail.log`; Datenfilter gilt.
- Entfällt (G12, Holger 08.11.2026): Erinnerungen (Termin fällig, Rückgabe überfällig, tägliche
  Zusammenfassung), `scheduler/run`, `fe_reminder_log` (Migration 0067) und der externe Zeitgeber. Die
  Themen gelten nur für Mails, die eine Aktion auslöst; die Mockup-Zeilen für Erinnerungen werden nicht
  gebaut. `CLAUDE.md` „Keine Cronjobs“ bleibt.

### 3.7 Geräteträger und Einschränkungen (P2.3, P2.8, P2.13)

- Rolle „Geräteträger“ (E2): `search` Ansehen, `book.move`, `book.status`, `device.used`,
  `device.swap`, Einschränkung `single_use_used_only`; ohne Termine. Legt Holger in Immendingen an
  (wie 1.8b); die Agenten nur in `test`.
- Die Handy-Leiste zeigt für solche Rollen „Benutzt melden“ statt „Umbuchen“ bei Einmalgeräten
  (`PAGE_RIGHTS`, `DevicePage`).

## 4. API, App-Version, Offline, CSV, Claude

- `X-Client-Api` 3 mit P2.1 (neuer Status). `ClientApi::MIN` 3 nach Rückfrage ⚠ G13 (Vorschlag:
  direkt mit P2.1 nach einem Deploy, wie 1.8a).
- Warteschlange: neue Einträge „benutzt melden“ und „Tausch“ → `QUEUE_FORMAT` 2 mit Umwandlung von
  Format 1 (`readStoredQueue`).
- Offline-Kopie: Aufgaben der Standorte, `single_use` der Typen, Sollbestand (für die Prüfung vor dem
  Tausch); Format des Snapshots 3.
- CSV: Status „benutzt“ in Export, Import („benutzt“ erlaubt? ⚠ G14, Vorschlag: ja wie die anderen
  Status) und Gültige Werte; Standort-Aufgabe nur in Gültige Werte; Historie mit den neuen
  Ereignissen und Spalte „Überbringer“.
- Claude (P2.12): Werkzeuge `report_used`, `swap_equipment`, `lend_equipment`, `list_loans`,
  `list_maintenance` nach dem Muster von M5/M6 (Adapter auf die neuen Routen; `ToolRoutesTest`).
- Jede Änderung an der API nach `UEBERGABE-BACKEND.md`.

## 5. Tests (je Schritt)

- Backend: `TenantRoutePermissionsTest` (jede neue Route mit Erfordernis), Matrix je neuem Recht
  (Admin, Benutzer, Gerätewart, Geräteträger, Datenfilter), Datenfilter in jeder neuen Abfrage
  (`DeviceVisibilityMatrixTest` erweitern), Migrationstests (CHECK alt/neu), Sperren mit zweiter
  Verbindung beim Tausch und beim Sollbestand (wie `DeviceLocationLockingTest`), Offline-Regeln
  (`DeviceOfflineBookingTest` für benutzt melden und Tausch), Bremse und Schlüssel der Rückgabe,
  Zeitbudget und keine doppelten Erinnerungen (`FixedClock`), Mails je Thema (`FakeMailer`).
- Frontend: Unit je Logik (Stufen, Sollbestand, Tausch, Warteschlange Format 2), Browser-Tests je
  Seite (Chromium, WebKit; Handy-Seiten in allen fünf Profilen), Rückgabe ohne Anmeldung mit
  simulierter Kamera wie `scanner.spec.ts`.
- Live: Test-Stadt mit Aufgaben, Rückgabe-Aushang am Handy (Holger), Erinnerungs-Mails (Holger).

## 6. Schritte

| Schritt | Inhalt | Größe | Mockup | vor dem nächsten |
| --- | --- | --- | --- | --- |
| P2.1 | Status „benutzt“ (Migrationen 0049–0051, `DeviceStatus`, Zählung, CSV, App-Anzeige), `X-Client-Api` 3 | mittel | – | Deploy, dann Rückfrage MIN 3 |
| P2.2 | Standort-Aufgaben: Katalog, Freischalten (Super-Admin), Vergeben (Admin), Standard-Lager | mittel | `Standorte-Aufgaben` | live in `test` |
| P2.3 | Einmalgeräte, „Benutzt melden“ (online/offline), Einschränkung, Aktion `device.used`, Editor „Einschränkungen“ | mittel | `Handy-Benutzt`, `Rolle-bearbeiten` | live am Handy (Holger) |
| P2.4 | Seite „Wartung“ mit Stufen und Sammelaktionen, Transportliste | mittel | `Wartung` | live in `test` |
| P2.5 | Verleih (Backend, Seite „Verleih“, Dashboard-Kachel, Zähler) | mittel | `Verleih` | live |
| P2.6 | Rückgabe ohne Anmeldung, Aushang, Rückgaben bearbeiten | mittel | `Handy-Rueckgabe`, `Annahme` | live am Handy (Holger) |
| P2.7 | Sollbestand: Regeln, „außer Betrieb“, Anzeige, Hinweise | mittel | `Mindestbestand` | live |
| P2.8 | Tausch online, Prüfung in allen Buchungswegen, `stock.override` | groß | `Handy-Tausch` | live am Handy |
| P2.9 | Tausch und Unterschreitung offline | mittel | – | live offline (Holger) |
| P2.10 | Mail-Auswahl je Person | klein | `Benachrichtigungen` | live (Mails Holger) |
| P2.11 | entfällt (G12: keine Erinnerungs-Mails, kein Zeitgeber) | – | – | – |
| P2.12 | Claude-Werkzeuge für die neuen Abläufe | mittel | – | live mit Claude |
| P2.13 | Rolle Geräteträger in `test`, Live-Test-Liste, Doku, Startbelegung Immendingen durch Holger | klein | – | – |

## 7. Rundenvorschlag

| Runde | Schritte | Anhalten für |
| --- | --- | --- |
| 7 | (M6 Rest/M7 des Claude-Zugangs, falls offen), P2.1 | Rückfrage `ClientApi::MIN` 3 |
| 8 | P2.2, P2.3 | – |
| 9 | P2.4, P2.10 | – |
| 10 | P2.5, P2.6 | – |
| 11 | P2.7, P2.8 | – |
| 12 | P2.9, P2.12, P2.13 | – |

## 8. Risiken

- Status „benutzt“ ändert Zählungen und Filter überall (Dashboard, Termine, Export, Offline-Kopie):
  ein Test je Stelle, die Status aufzählt (Suche nach `DeviceStatus::cases` bzw. Listen der Werte).
- Sollbestand macht jede Buchung teurer (Zählung je Regel in der Transaktion): Index auf
  `(tenant_id, location_id, status)` prüfen, Messung mit 5000 Geräten.
- Öffentliche Rückgabe ist eine neue Angriffsfläche: Schlüssel 32 Byte, nur Hash, Bremse, keine
  Daten außer Nummer und Typ, Tests für fremde Städte und nicht verliehene Geräte.

## 9. Offene Fragen (mit Vorschlag)

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| G1 | Gehört das Wartungsintervall je Typ (Konzept Phase 3, mehrere Wartungen, „letzte Wartung = heute“ bei der Rückkehr) in Phase 2? | Nein, eigene Phase direkt danach; bis dahin setzt „Zurück aus der Werkstatt“ optional das Wartungsdatum wie heute. |
| G2 | „Seiten anpassen“ (Spalten/Felder je Stadt) jetzt? | Nein; nur die Stadt-Einstellung „Rückgabefrist verwenden“ für Immendingen. |
| G3 | „Wege der Ausrüstung“ (Grafik, steuernde Wege)? | Später; erst nach Live-Erfahrung mit den Abläufen. |
| G4 | Datenfilter und Ziele mit fester Aufgabe: Darf ein Geräteträger mit Filter „nur HLF 20“ in die Sammelstelle buchen? | Ja, aber nur über „Benutzt melden“ und Tausch (der Ablauf gibt das Ziel vor); freies Umbuchen bleibt im Filter. |
| G5 | „Seit wann in der Stufe“ als Spalte oder aus dem Verlauf? | Aus `state_changed_at` (gibt es schon): seit der letzten Umbuchung bzw. Statusänderung; keine Spalte 0056. |
| G6 | Rückgabe ohne Anmeldung hat kein Konto: `user_id` im Verlauf NULL erlauben? | Ja, nur bei `returned` mit `actor_name` (CHECK); Anzeige „Rückgabe durch <Name>, ohne Anmeldung“. |
| G7 | Bisherige „Log-Mails an“ in die Themen-Tabelle übertragen (Datenänderung)? | Nein: `notify_device_log = 1` ohne eigene Auswahl gilt als „alles angehakt“; keine Migration mit Daten. |
| G8 | Können Fächer eine eigene Aufgabe haben (z. B. Sammelkiste im Fahrzeug)? | Nein in Phase 2 (nur Ebene 1); später, falls gebraucht. |
| G9 | Abgelehnte Buchung wegen Einschränkung: 403 `forbidden` oder eigener Code? | Eigener Code `restricted_action` (403) mit Meldung; die App zeigt dann „Benutzt melden“. |
| G10 | Seite „Wartung“ über die Sammelrouten (braucht `devices.bulk`) oder eigene Routen? | Eigene Routen `maintenance/to-workshop`, `maintenance/back` mit Bereich `maintenance` Ändern. |
| G11 | Was zählt zum Sollbestand: nur „einsatzbereit“? | Ja; „in Wartung“, „benutzt“ und „defekt“ am Fahrzeug zählen nicht (das Fach ist dann außer Betrieb). |
| G12 | Welcher externe Zeitgeber? | **Entschieden (Holger 08.11.2026): keiner.** Keine Erinnerungs-Mails, keine tägliche Zusammenfassung; die App zeigt Fälliges und Überfälliges. Wunsch T19. |
| G13 | `ClientApi::MIN` 3 gleich nach P2.1? | Ja, nach einem Deploy mit `X-Client-Api` 3 (wie 1.8a). |
| G14 | Status „benutzt“ im CSV-Import erlauben? | Ja, wie die anderen Status (kein Ablauf wird ausgelöst; keine Sammelstelle). |
| G15 | Verleih mehrerer Geräte: ein Verleih je Gerät oder ein Vorgang mit Positionen? | Je Gerät eine Zeile in `fe_device_loans`, gemeinsamer Kommentar; die Übersicht gruppiert nach Leihnehmer. |
| G16 | Wer darf Rückgabe-Schlüssel erneuern und Aushänge drucken? | `returns.process` (Gerätewart, Admin). |
| G17 | Tausch über Claude (P2.12) erlauben? | Ja mit Zugang „Ändern“ und `device.swap`; Unterschreiten ohne Ersatz über Claude nie (`stock.override` fällt in `forClaude` weg). |

**Entscheidungen Holger 09.11.2026 (Runde 8b):** G4 entschieden wie vorgeschlagen: Rollen mit Datenfilter
erreichen die Sammelstelle nur über „Benutzt melden“ bzw. den Tausch, nicht als freies Ziel beim Umbuchen.
Migration 0055 (`used_reported`) entfällt: „Benutzt melden“ bleibt ein `state_changed` mit Status „benutzt“.

**Entscheidungen Holger 08.11.2026:** G1–G11 und G13–G17 wie vorgeschlagen (G1: Wartungsintervall je Typ als eigene Phase direkt nach Phase 2; G13: `ClientApi::MIN` 3 freigegeben, als eigener Schritt nach einem Deploy mit `X-Client-Api` 3, Deploy außerhalb eines Dienstabends); G12 siehe oben. Begriffe in der Oberfläche nach Konzept Abschnitt 9 (Rückgabe, „Ausgeliehen an“, „in Betrieb seit“ …), nicht die Arbeitsbegriffe des Plans. Die beiden optionalen Migrationen mit Datenänderung (0066, 0068) nur nach Rückfrage (Vorschlag: entfallen).
