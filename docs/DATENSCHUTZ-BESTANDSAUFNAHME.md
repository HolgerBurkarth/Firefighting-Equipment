# Datenschutz – Bestandsaufnahme

Stand: 13.11.2026 (Code auf `main`, Commit `822d280`, Migrationen bis `0065`).
Zweck: Grundlage für die Entscheidungen vor dem Start in Immendingen (CLAUDE.md, „Noch offen“).

Diese Datei hält nur fest, **was der Code tut**, und listet die **offenen Fragen**. Sie ist keine
Rechtsberatung. Was juristisch zu klären ist, steht als **Frage (juristisch)** da. Wo eine Angabe
nicht aus dem Code stammt (z. B. Vertrag mit STRATO), ist sie als **nicht geprüft** markiert.

Inhalt:

1. [Personenbezogene Daten je Speicherort](#1-personenbezogene-daten-je-speicherort)
2. [Speicherdauer, Löschen und Anonymisieren heute](#2-speicherdauer-löschen-und-anonymisieren-heute)
3. [Beteiligte Dienstleister](#3-beteiligte-dienstleister)
4. [Offene Entscheidungen für Holger](#4-offene-entscheidungen-für-holger)

Begriffe für die Rollen: *Admin* und *Benutzer* sind die Standard-Rollen jeder Stadt; eigene Rollen
(z. B. Gerätewart, Geräteträger) sehen das, was ihre Bereiche erlauben (`Area` in
`src/Permission/`). „Mit Bereich X“ heißt: eine Rolle, die den Bereich mindestens ansehen darf
(*Admin* hat alle). Der Super-Admin verwaltet die Städte unter `/firefighting/service/`.

---

## 1. Personenbezogene Daten je Speicherort

### 1.1 Datenbank (MariaDB bei STRATO)

Alle Tabellen tragen die Stadt (`tenant_id`), jede Abfrage filtert danach. Die Datenfilter der Rollen
(„Sieht nur …“) schränken zusätzlich ein, welche Ausrüstung (und damit welcher Verlauf) sichtbar ist.

#### Konten

| Tabelle / Spalte | Inhalt | Wer sieht es | Wofür |
|---|---|---|---|
| `fe_users.email` | E-Mail-Adresse (klein, `utf8mb4_bin`) | eigenes Konto; Bereich `users` (Benutzerverwaltung); Super-Admin (Konten je Stadt); Bereich `log` in Hinweisen (siehe unten) | Anmeldung, Mails (Einladung, Bestätigung, Passwort, Benachrichtigung „Log“) |
| `fe_users.name` | Name aus Registrierung bzw. Einladung | wie oben | Anzeige in der Benutzerverwaltung, Prüfung der Passwörter (kein Name im Passwort) |
| `fe_users.display_name` | Anzeigename (vom Admin bei der Freischaltung gesetzt) | alle mit Zugriff auf den Verlauf, die Hinweise, „Letzte Aktivitäten“; Benutzerverwaltung | „Wer“ im Verlauf, Begrüßung „Hallo …“ |
| `fe_users.password_hash` | Argon2id-Hash | niemand (nie in einer Antwort, `User` hat ihn nicht) | Anmeldung |
| `fe_users.status`, `locked_at`, `deleted_at`, `email_verified_at`, `created_at`, `updated_at` | Kontostatus und Zeiten | Bereich `users`, Super-Admin | Ablauf Registrierung → Freischaltung, Sperren |
| `fe_users.role`, `role_id` | Kontoart und Rolle | Bereich `users`, Super-Admin | Rechte |
| `fe_users.invitation_code_id` | mit welchem Einladungscode registriert | Bereich `users` | Freischaltung („Aushang …“) |
| `fe_users.notify_device_log`, `fe_user_mail_prefs` | Benachrichtigung „Log“ an/aus, gewählte Themen | eigenes Konto | Mailversand |
| `fe_users.claude_access` | Claude-Zugang Aus/Lesen/Ändern | Bereich `users` (nur *Admin* ändert), Super-Admin | Claude-Zugang (nur Test-Stadt) |
| `fe_users.protected` | geschütztes Super-Admin-Konto | Super-Admin | Schutz vor Aussperren |
| `fe_invitation_codes.created_by`, `label` | wer den Code angelegt hat, Bezeichnung (Freitext) | Bereich `users` | Verwaltung der Codes |

#### Anmeldung und Sicherheit

| Tabelle / Spalte | Inhalt | Wer sieht es | Wofür |
|---|---|---|---|
| `fe_sessions` | Konto, Hash des Tokens, `created_at`, `last_seen_at`, `expires_at`, **`device_label`** | nur das eigene Konto („Angemeldete Geräte“) | Anmeldung per Cookie |
| `fe_sessions.device_label` | grobe Bezeichnung aus festen Wörtern, z. B. „Chrome auf Android“, sonst „Unbekanntes Gerät“; nie der vollständige User-Agent (`src/Auth/DeviceLabel.php`) | eigenes Konto | eigene Sitzungen erkennen und beenden |
| `fe_user_tokens` | Konto, Zweck, Hash des Einmal-Links, Ablauf | niemand | Bestätigung, Passwort setzen/zurücksetzen |
| `fe_auth_attempts.email_hash` | SHA-256 der E-Mail (bei einigen Aktionen leer) | niemand (nur der Code) | Bremse gegen Durchprobieren |
| `fe_auth_attempts.ip_prefix` | **gekürzte IP-Adresse** (IPv4 /24, IPv6 /48, binär; `src/Auth/IpPrefix.php`), Aktion, Erfolg, Zeit | niemand (nur der Code) | Bremse (Anmeldung, Registrierung, Passwort, Einladung, Rückgabe ohne Anmeldung, Registrierung von Claude) |

Frage (juristisch): Sind gekürzter IP-Präfix und E-Mail-Hash noch personenbezogen? (Sie werden nach
24 Stunden gelöscht, siehe Abschnitt 2.)

#### Ausrüstung und Verlauf

| Tabelle / Spalte | Inhalt | Wer sieht es | Wofür |
|---|---|---|---|
| `fe_device_events.user_id` (+ Anzeigename über JOIN) | **„Wer“** jeder Änderung: Anlegen, Import, Umbuchen, Status, Benutzt melden, Tausch, Verleih, Wartungsdatum, Stammdaten | jede Rolle, die die Ausrüstung sehen darf (`GET devices/{id}/events`); „Letzte Aktivitäten“ mit Bereich `dashboard` oder `log`; CSV-Export der Historie mit `csv.export` + `log`; Empfänger der Mails „Log“; Claude-Werkzeuge (Test-Stadt) | Nachvollziehbarkeit: wer hat was wann gebucht |
| `fe_device_events.actor_name` | **Name, den eine Person bei der Rückgabe ohne Anmeldung eintippt** (bis 100 Zeichen, Migration `0058`; dann ist `user_id` leer, Anzeige „<Name> (ohne Anmeldung)“) | wie Verlauf (oben) | wer hat die Ausrüstung zurückgegeben |
| `fe_device_events.comment` | Freitext (bis 500 Zeichen) bei jeder Buchung | wie Verlauf | Begründung („Was ist defekt?“) – kann Namen oder Angaben über Personen enthalten |
| `fe_device_events.occurred_at`, `created_at`, `channel` | Aktionszeit auf dem Handy, Eingang, „über Claude“ | wie Verlauf | Offline-Buchungen, Kennzeichnung |
| `fe_devices.note` | Notiz zur Ausrüstung (bis 1000 Zeichen) | Detailseite (Bereich `devices`), Claude | Freitext; kann Personenbezug enthalten |
| `fe_device_bookings` | Quittung je Buchung mit `client_ref`: Konto, Gerät, Ergebnis, Eingang | niemand direkt (in der Historie als Buchungs-Kennung) | doppelte Buchungen verhindern |
| `fe_device_notices.user_id`, `other_user_id` | Konto der Offline-Buchung und Konto des anderen Stands | Bereich `log` (Protokoll), Mails „Hinweis“ | Konflikte nach Offline-Buchungen klären |
| Hinweise, Anzeige | **Anzeigename UND E-Mail** der beteiligten Konten (bewusste Ausnahme, CLAUDE.md „Hinweise (API)“; `NoticeJson`), Knopf „Rückfrage per E-Mail“; in der Mail „Wer: Name (E-Mail)“ (`MailTemplates`, Zeilen 524/536) | Bereich `log`; Empfänger der Mails „Log“ | Rückfrage an die Person, die offline gebucht hat |
| `fe_device_notice_actions.user_id`, `note` | wer einen Hinweis erledigt/wieder geöffnet hat, Notiz (Freitext bis 500) | Bereich `log` | Verlauf des Hinweises |
| `fe_device_loans.contact` | **Ansprechpartner beim Leihnehmer** (Freitext bis 100, z. B. Name, Telefon) | Bereich `loans` | Rückfragen beim Verleih |
| `fe_device_loans.lent_by`, `comment`, `lent_at`, `returned_at` | wer verliehen hat, Kommentar | Bereich `loans` | Verleih |
| `fe_return_points.created_by` | wer den Aushang erzeugt hat | Bereich `loans` | Verwaltung der Rückgabe |
| Standorte „Ausgeliehen an“ | Name des Leihnehmers ist ein Standort (z. B. „Hattingen“); kann eine Person sein, wenn die Stadt das so anlegt | wer Standorte sieht | Verleih |
| `fe_roles.updated_by`, `fe_tenant_logos.updated_by` | wer zuletzt geändert hat | Super-Admin bzw. intern | Nachvollziehbarkeit |

#### Claude-Zugang (nur Test-Stadt, `ClaudeAvailability`)

| Tabelle / Spalte | Inhalt | Wofür |
|---|---|---|
| `fe_oauth_clients` | Registrierung von Claude: `client_id`, Name (z. B. „Claude“), Zeiten; keine Person | OAuth |
| `fe_oauth_codes` | Konto, Hash des Codes, Rücksprung-Adresse, höchstens 5 Minuten gültig | OAuth |
| `fe_claude_connections` | Konto, Zugang, Hashes der Tokens, Zeiten (`created_at`, `last_seen_at`, `expires_at`) | Verbindung; eigenes Konto sieht sie unter „Angemeldete Geräte“, Bereich `users` sieht Anzahl und letzte Nutzung |

### 1.2 Mails

Versand über STRATO (`sendmail` oder SMTP `smtp.strato.de`, Absender `feuerwehr-app@prodad.de`).

| Mail | Empfänger | Personenbezogener Inhalt |
|---|---|---|
| Einladung Admin, Bestätigungslink, Passwort zurücksetzen, „Passwort geändert“, „Registrierung wartet auf Freischaltung“, „Konto freigeschaltet“, „Registrierung nicht freigegeben“, „Einladung angenommen“, „Neue Claude-Verbindung“ | das betroffene Konto bzw. Admins der Stadt bzw. Super-Admins | E-Mail-Adresse, Name, bei „wartet auf Freischaltung“ und „Einladung angenommen“ Name und E-Mail der neuen Person; Einmal-Links (Token hinter `#`) |
| Benachrichtigung „Log“ (`DeviceLogNotifier`, Zweck `device_log`) | Konten mit `mail.log` und eingeschalteter Option, Super-Admins mit Option | „Wer“ (Anzeigename, bei Rückgabe ohne Anmeldung der eingetippte Name), Zeit, Ausrüstung, Kommentar |
| Hinweis (Zweck `device_notice`) | wie „Log“ | Anzeigename **und E-Mail** der beteiligten Konten, Kommentar |

Was einmal beim Empfänger liegt, kann die App nicht mehr löschen (siehe Abschnitt 2).
Lokal (nur Entwicklung) schreibt `FileMailer` Mails als `.eml` nach `var/mail`; auf dem Server nicht
(Standard `sendmail`, kein `file`).

### 1.3 Protokoll des Servers (`error_log`)

- Geschrieben nur über `ErrorReporter` (`src/Http/ErrorReporter.php`): unerwartete Fehler
  maskiert, ohne Stack-Trace; Mailfehler als `mail_failed (<zweck>, Konto #ID)` – also **Konto-ID**,
  nie Empfänger, Text, Link oder Token. SMTP-Antworten werden bereinigt (E-Mail-Adressen →
  `<Adresse>`, `SmtpReply::sanitize`).
- Nicht im Code geregelt: das **Zugriffsprotokoll von Apache bei STRATO** (volle IP-Adresse, Adresse,
  Zeit, User-Agent). Inhalt, Kürzung und Aufbewahrung bestimmt STRATO – **nicht geprüft**.
  Adressen der Mail-Links tragen das Token hinter `#`, das kommt nicht beim Server an.
- Der Vermittler für Claude im `cgi-bin` (`scripts/cgi-bin/ff-mcp.pl`) schreibt kein Protokoll.

### 1.4 Sicherungen unter `~/htdocs/apps/_backup/`

| Datei | Inhalt | Aufbewahrung (Code) |
|---|---|---|
| `ff-db-*.sql.gz` (`bin/db-dump.php`, vor jeder Migration durch `scripts/deploy.sh`, vor `purge-device-data`) | **alle Tabellen `fe_*`**, also alle Daten aus 1.1, auch von Konten, die inzwischen anonymisiert sind | die 10 neuesten bleiben (`BackupRotation::KEEP`), ältere werden beim nächsten Dump gelöscht |
| `ff-deploy-*.tar.gz` (`scripts/deploy.sh`, Zeile 145) | Projektordner ohne `vendor/`, also Code und **`config/config.local.php` (Zugangsdaten)**, keine Datenbank | die 5 neuesten (`scripts/prune-deploy-backups.sh`) |

Der Ordner ist per `.htaccess` gesperrt. Ob STRATO zusätzlich eigene Sicherungen des Webspace und
der Datenbank anlegt und wie lange, ist **nicht geprüft**.

### 1.5 Auf dem Handy bzw. PC (Browser)

Alles liegt nur im Browser des Geräts; die App liest Daten anderer Städte nur für „Konto wechseln“.

| Speicher / Schlüssel | Inhalt mit Personenbezug | Wann gelöscht |
|---|---|---|
| Cookie `ff_session` (`HttpOnly`) | zufälliges Token | Abmelden, Ablauf der Sitzung |
| localStorage `ff-session:<kürzel>` | Konto-ID, **E-Mail**, Name, Anzeigename, Rolle, Rechte (`storedJson` in `frontend/src/auth/session.ts`) | Abmelden, abgelaufene/abgelehnte Sitzung (`resetSession`) |
| localStorage `ff-accounts` | je Stadt Konto-ID, Anzeigename, Rolle, Name der Stadt; ohne E-Mail und Tokens (`src/data/accounts.ts`) | Abmelden entfernt den Eintrag dieser Stadt |
| localStorage `ff-queue-owners:<kürzel>` | Konto-ID → Name der Besitzer wartender Buchungen | Abmelden entfernt den eigenen Eintrag |
| localStorage `ff-recent-devices:…`, `ff-recent-locations:…` | IDs zuletzt benutzter Ausrüstung bzw. Standorte je Konto | Abmelden |
| localStorage `ff-tenant:…`, `ff-used-target:…`, `ff-offline-limits:…`, `ff-theme` | Name/Farbe der Stadt, Sammelstelle, Grenzen, Anzeigemodus; kein Personenbezug | – |
| IndexedDB `ff-offline`, Tabelle `snapshots` | Kopie der sichtbaren Ausrüstung und Standorte (ohne Verlauf, ohne Personen) | Abmelden, anderes Konto, neue Rechte |
| IndexedDB `ff-offline`, Tabelle `queue` | wartende Offline-Buchungen mit **Kommentar** | Abmelden (nach Rückfrage), „Verwerfen“; nach dem Senden „Angekommene ausblenden“ |
| sessionStorage `ff-booking-notice`, `ff-login-notice`, `ff-update-trace`, `ff-update-reloaded-at` | Bestätigungstexte, Zeitleiste der Updates (ohne Adressen und persönliche Daten) | Schließen des Tabs |
| sessionStorage `ff-return-key` | Schlüssel der Rückgabestelle (kein Personenbezug); der eingetippte Name der Rückgabe wird nicht gespeichert | Schließen des Tabs |

Frage: Reicht es, dass Abmelden alles Persönliche löscht, oder braucht es einen Hinweis (z. B. bei
gemeinsam genutzten Handys der Wache), dass ohne Abmelden E-Mail und Name im Browser bleiben?

### 1.6 Claude-Zugang (MCP)

Heute nur in der Test-Stadt (`ClaudeAvailability::SLUG` = `test`, fest im Code). Bei Nutzung liest
Claude über die Werkzeuge (`src/Mcp/Tool/`) im Namen einer Person: Ausrüstung, Standorte, Termine,
Verlauf mit **Anzeigenamen** („Wer“, auch Namen der Rückgabe ohne Anmeldung), Kommentare, Notizen,
Hinweise (**ohne E-Mail**, `ToolFormat`), Verleihe mit Ansprechpartner. Keine Werkzeuge für Benutzer,
Einladungscodes, Rollen, Einstellungen (`ToolRoutesTest`). Diese Daten gehen an Anthropic (Abschnitt 3).

---

## 2. Speicherdauer, Löschen und Anonymisieren heute

Kein Cronjob auf STRATO: Aufgeräumt wird beim Aufruf bestimmter Routen.

### 2.1 Was heute gelöscht oder anonymisiert wird

| Daten | Regel | Fundstelle |
|---|---|---|
| `fe_auth_attempts` (IP-Präfix, E-Mail-Hash) | nach **24 Stunden**, beim Anmelden, Registrieren, Passwort zurücksetzen, Bestätigungsmail neu, „Claude verbinden“ | `LoginThrottle::RETENTION_HOURS`, `deleteOld()` (`src/Auth/LoginThrottle.php:83`); Aufrufe u. a. `LoginHandler.php:36`, `RegisterHandler.php:71` |
| `fe_sessions` | abgelaufene beim nächsten Login aller Konten bzw. beim Antreffen; Inaktivität/Höchstdauer: *Benutzer* 30/90 Tage, Admin-ähnliche Rollen 7/30 Tage, Super-Admin 1 h/12 h | `SessionRepository::deleteExpired()` (`src/Auth/SessionRepository.php:127`), `LoginHandler.php:37`, `SessionClass` |
| `fe_user_tokens` | abgelaufene beim Aufruf der Abläufe; eingelöste sofort; Gültigkeit 24 h bzw. 1 h | `UserTokenRepository::deleteExpired()` (`src/Auth/UserTokenRepository.php:53`) |
| Konten, die 7 Tage nach Registrierung noch unbestätigt sind | anonymisiert bei `register`, `email/verify`, `email/resend`, `GET users` | `UserRepository::UNVERIFIED_RETENTION_DAYS`, `anonymizeStaleUnverified` (`src/User/UserRepository.php:820`) |
| Konto „löschen“ bzw. Registrierung ablehnen (durch Admin) | **Anonymisieren**: E-Mail, Name, Anzeigename, Passwort-Hash, Bestätigung → leer, Sitzungen, Einmal-Links, Claude-Verbindungen gelöscht; der Datensatz bleibt als „Gelöschter Benutzer #ID“ | `anonymizeLocked` (`src/User/UserRepository.php`, ab Zeile 1052), `anonymize` (Zeile 534) |
| Claude: Codes und Verbindungen | abgelaufene bei jedem Token-Aufruf; Codes höchstens 5 Minuten, Verbindungen höchstens 90 Tage bzw. Sitzungsklasse; beim Sperren, Passwortwechsel, Anonymisieren, Zugang „Aus“ sofort | `ClaudeConnectionRepository::deleteExpired` (Zeile 157), `deleteForUser` (Zeile 170) |
| Claude: Registrierungen | nie genutzte nach 24 Stunden, genutzte ohne Verbindung nach 90 Tagen | `OAuthClientRepository::UNUSED_RETENTION_HOURS`, `USED_RETENTION_DAYS` (Zeile 16/18) |
| Sicherungen der Datenbank | 10 neueste | `BackupRotation::KEEP` |
| Sicherungen des Projektordners | 5 neueste | `scripts/prune-deploy-backups.sh` |
| Browser | beim Abmelden (siehe 1.5) | `src/auth/session.ts`, `installDataLayer` |

### 2.2 Wo es heute keine Löschung gibt

- **Verlauf** (`fe_device_events`): nur `INSERT`, bewusst unveränderlich. Nach dem Anonymisieren
  eines Kontos steht im Verlauf „Gelöschter Benutzer #ID“ – der Bezug über die ID bleibt in der
  Datenbank (und in Sicherungen mit dem alten Namen).
- **Namen der Rückgabe ohne Anmeldung** (`fe_device_events.actor_name`): hängen an keinem Konto,
  werden also auch beim Anonymisieren nie entfernt und bleiben unbegrenzt.
- **Kommentare und Notizen** (Freitext in Verlauf, Hinweisen, Verleih, Gerät): bleiben; beim
  Anonymisieren eines Kontos werden darin genannte Namen nicht entfernt.
- **Ansprechpartner beim Verleih** (`fe_device_loans.contact`): bleibt auch nach der Rückgabe.
- **Hinweise** (`fe_device_notices`, `fe_device_notice_actions`): bleiben, auch erledigte.
- **Quittungen** (`fe_device_bookings`): bleiben.
- **Abgelaufene, aufgebrauchte oder widerrufene Einladungscodes**, widerrufene **Rückgabe-Schlüssel**:
  bleiben (ohne Klartext, nur Hash).
- **Super-Admins**: werden nie gelöscht, nur gesperrt (CHECK `chk_fe_users_superadmin_not_deleted`).
- **Sicherungen**: die 10 neuesten Dumps enthalten auch Daten, die in der Datenbank schon
  anonymisiert sind; wie lange das dauert, hängt davon ab, wie oft migriert wird (ohne Migration
  entsteht kein neuer Dump, alte bleiben unbegrenzt).
- **Mails beim Empfänger** (Postfächer der Admins, Super-Admins, Personen): außerhalb der App.
- **CSV-Exporte**, die Nutzer herunterladen: außerhalb der App.
- **Zugriffsprotokoll und Sicherungen von STRATO**: außerhalb des Codes, **nicht geprüft**.
- **Daten bei Anthropic** (Claude-Zugang): außerhalb der App, abhängig vom Konto der Person bei
  Claude, **nicht geprüft**.
- **`purge-device-data`** (`bin/tenant.php`) löscht Ausrüstung, Verlauf, Hinweise und Standorte
  einer ganzen Stadt – nur für Städte ohne echte Daten, nicht als Löschfrist gedacht.

---

## 3. Beteiligte Dienstleister

| Dienstleister | Rolle | Welche Daten gehen dorthin | Stand |
|---|---|---|---|
| **STRATO** (Shared Hosting, MariaDB, Mail) | Webspace, Datenbank, Versand und Postfach `feuerwehr-app@prodad.de` | alle Daten aus 1.1 und 1.4 (Datenbank und Sicherungen liegen dort), Zugriffsprotokoll von Apache (volle IP-Adresse), alle Mails aus 1.2 | Vertrag zur Auftragsverarbeitung mit STRATO: **nicht geprüft**; Vertragspartner ist das Konto des Webspace (proDAD bzw. Holger?) – **Frage** |
| **Postfächer der Empfänger** (z. B. eigene Anbieter der Feuerwehrleute) | Empfang der Mails | Inhalte aus 1.2 | nicht von der App gesteuert |
| **GitHub** | Repository (öffentlich) und CI | **nur Code**; keine Zugangsdaten, keine echten Daten (CLAUDE.md: `config.local.php` nie committen). Die CI arbeitet mit Wegwerf-Datenbank und erfundenen Testdaten; die Anleitungen (PDF) zeigen erfundene Beispieldaten | kein Personenbezug außer Namen der Mitwirkenden im Git-Verlauf |
| **Anthropic** (Claude) | beim Claude-Zugang (nur Test-Stadt) | was die Werkzeuge liefern (1.6): Ausrüstung, Standorte, Anzeigenamen im Verlauf, Kommentare, Notizen, Ansprechpartner beim Verleih; dazu die Anfragen der Person an Claude. Über den Vermittler im `cgi-bin` von www.prodad.de (läuft bei STRATO) | Rechtsgrundlage und Auftragsverarbeitung offen (T17 in `docs/TODO-SPAETER.md`) |
| **Anthropic** (Entwicklung mit Claude Code) | Agent bei der Entwicklung | Code und Dokumentation; Live-Tests nur in der Test-Stadt mit Testgeräten M90–M99; Zugangsdaten öffnet der Agent nie | keine echten Daten aus Immendingen vorgesehen (Agenten prüfen dort nur lesend, CLAUDE.md) – **Frage**, ob auch lesende Prüfungen in Immendingen künftig entfallen sollen |

Keine weiteren Dienste: keine Schriften von Google (im Build), kein CDN (der Kamera-Scanner lädt sein
Lesemodul aus dem eigenen Build), keine Analyse- oder Werbedienste, kein Tracking.

---

## 4. Offene Entscheidungen für Holger

Je Punkt: Frage, Vorschlag, Begründung. Die Vorschläge sind keine rechtliche Bewertung.

### E1 – Wer ist Verantwortlicher?

- **Frage (juristisch):** Ist die Gemeinde Immendingen (Träger der Feuerwehr) Verantwortliche und
  Holger bzw. proDAD Auftragsverarbeiter? Oder ist Holger selbst Verantwortlicher? Wer unterschreibt
  einen Vertrag zur Auftragsverarbeitung, und ist STRATO dann Unterauftragsverarbeiter?
- **Vorschlag:** Gemeinde bzw. Feuerwehr Immendingen als Verantwortliche, Holger/proDAD als
  Betreiber und Auftragsverarbeiter, STRATO als Unterauftragsverarbeiter. Mit der Gemeinde und dem
  Datenschutzbeauftragten der Gemeinde klären.
- **Begründung:** Die Feuerwehr entscheidet, wer Konten bekommt, was gebucht wird und wer was sieht
  (Rollen); die App stellt nur das Werkzeug. Mehrere Städte nutzen denselben Server, die Daten sind
  je Stadt getrennt – das passt zum Bild „ein Betreiber, je Stadt ein Verantwortlicher“.

### E2 – Löschfristen je Datenart

| Datenart | heute | Vorschlag | Begründung / Frage |
|---|---|---|---|
| Fehlversuche (IP-Präfix, E-Mail-Hash) | 24 h | so lassen | kürzer bricht die Bremse |
| Sitzungen, Einmal-Links | abgelaufen = gelöscht | so lassen | – |
| Unbestätigte Registrierungen | 7 Tage | so lassen | – |
| Konto nach Austritt | anonymisieren von Hand durch den Admin | so lassen, aber **Ablauf festlegen**: wer meldet den Austritt, binnen welcher Frist wird anonymisiert | Frage: Soll die App gesperrte Konten nach N Monaten selbst anonymisieren oder daran erinnern? |
| Verlauf mit „Wer“ | unbegrenzt | **Frage an die Feuerwehr**: Wie lange muss nachvollziehbar sein, wer eine Ausrüstung (z. B. Atemschutz) zuletzt hatte? Vorschlag zur Diskussion: unbegrenzt für den Stand der Ausrüstung, Personenbezug (Konto-ID, Name der Rückgabe) nach z. B. 10 Jahren entfernen | Ausrüstung lebt lange; ob Prüf- und Nachweispflichten (Atemschutz) eine Frist vorgeben, ist **juristisch/fachlich zu klären**. Technisch bräuchte das eine Ausnahme von „Verlauf nur INSERT“ (Rückfrage vor jeder Umsetzung) |
| Namen der Rückgabe ohne Anmeldung | unbegrenzt | kürzer als der Verlauf, z. B. 1 Jahr, dann „(ohne Anmeldung)“ ohne Namen | Personen ohne Konto haben keine Freischaltung erlebt und kennen die App kaum |
| Ansprechpartner beim Verleih | unbegrenzt | nach der Rückgabe + z. B. 6 Monate leeren | wird nur für Rückfragen während des Verleihs gebraucht |
| Kommentare, Notizen | unbegrenzt | so lassen, aber in der Datenschutzerklärung und in der Anleitung bitten, keine Angaben über Personen hineinzuschreiben | Freitext lässt sich nicht automatisch prüfen |
| Erledigte Hinweise (mit E-Mail) | unbegrenzt | z. B. 1 Jahr nach Erledigen löschen oder E-Mail nur anzeigen, solange offen | E-Mail wird nur für die Rückfrage gebraucht |
| Quittungen `fe_device_bookings` | unbegrenzt | so lassen oder nach Zeitlimit der Warteschlange + Reserve löschen | technisch nur für Wiederholungen nötig – **Frage an mich (Agent)** vor einer Umsetzung: Folgen für Hinweise, die `booking_id` nutzen |
| Datenbanksicherungen | 10 neueste, ohne Zeitgrenze | **zusätzlich höchstens z. B. 90 Tage** | sonst können sehr alte Stände mit nicht mehr vorhandenen Personen liegen bleiben; Änderung an Sicherungen braucht laut CLAUDE.md Rückfrage |
| Zugriffsprotokoll STRATO | nicht bekannt | bei STRATO nachsehen und dokumentieren | – |

### E3 – Datenschutzerklärung: Inhalt und Ort

- **Frage:** Wer schreibt sie (Gemeinde bzw. ihr Datenschutzbeauftragter)? Eine je Stadt oder eine
  gemeinsame mit Platzhalter für den Verantwortlichen?
- **Vorschlag:** Je Stadt ein eigener Text (Verantwortlicher ist je Stadt verschieden, E1),
  gepflegt in den Einstellungen der Stadt bzw. durch den Super-Admin; zusätzlich ein gemeinsamer Teil
  zum Betrieb (STRATO, keine Tracker, Speicher im Browser). Link „Datenschutz“ auf der
  **Anmeldeseite**, der **Registrierung**, der **Rückgabeseite ohne Anmeldung**, „Claude verbinden“
  und im Profil/Einstellungen; der Link muss ohne Anmeldung und offline erreichbar sein (Vorab-Laden
  des Service Workers). Ein Impressum ist ebenfalls zu klären (**Frage, juristisch**).
- **Begründung:** Die Rückgabeseite wird von Personen ohne Konto genutzt, die ihren Namen eintippen –
  dort ist der Hinweis am nötigsten. Inhalt als Grundlage: Abschnitte 1–3 dieser Datei.

### E4 – Hinweis bei der Registrierung

- **Frage (juristisch):** Reicht ein Hinweis mit Link, oder braucht es eine Bestätigung (Kästchen)?
  Rechtsgrundlage bei Mitgliedern der Feuerwehr eher Dienst-/Mitgliedsverhältnis als Einwilligung?
- **Vorschlag:** Kurzer Text unter dem Formular „Mit der Registrierung speichern wir Ihre E-Mail und
  Ihren Namen; im Verlauf steht, wer welche Ausrüstung gebucht hat. Datenschutz: …“ mit Link, ohne
  Pflicht-Kästchen. Ebenso auf der Rückgabeseite beim Namensfeld („Ihr Name steht im Verlauf der
  Ausrüstung“) und bei „Einladung annehmen“.
- **Begründung:** Wer sich registriert, soll vorher wissen, dass sein Name an jeder Buchung steht.

### E5 – T17: Claude-Zugang in echten Städten

- **Frage (juristisch):** Ist die Übermittlung an Anthropic (USA) zulässig, mit welchem Vertrag
  (Auftragsverarbeitung, Standardvertragsklauseln), und über wessen Konto bei Claude (privates Konto
  der Person vs. Konto der Gemeinde)? Wer darf den Zugang je Stadt freigeben?
- **Vorschlag:** Für den Start in Immendingen **aus lassen** (wie heute, fest im Code nur `test`).
  Später freischalten nur je Stadt durch den Super-Admin nach schriftlicher Zustimmung der Stadt,
  mit Hinweis auf „Claude verbinden“ und in der Datenschutzerklärung, und nur mit Konten bei Claude,
  für die ein Vertrag besteht.
- **Begründung:** Mit dem Zugang verlassen Namen im Verlauf, Kommentare und Notizen den Server von
  STRATO; die Verantwortung dafür liegt bei der Stadt (E1), sie muss zustimmen.

### E6 – Auskunft und Löschung auf Anfrage

- **Heute:** Es gibt keinen eigenen Ablauf. Löschen = Anonymisieren durch den Admin
  (Benutzerverwaltung „… löschen (ausgetreten) …“). Eine Auskunft müsste von Hand zusammengestellt
  werden; das eigene Konto sieht in der App nur Name, E-Mail, Sitzungen und Claude-Verbindungen, nicht
  „alle meine Buchungen“.
- **Fragen:** An wen richtet sich eine Anfrage (Gemeinde, Feuerwehr, Holger)? In welcher Frist?
  Muss eine Auskunft den Verlauf mit allen Buchungen der Person enthalten? Dürfen bei einer Löschung
  Einträge im Verlauf bleiben (Nachweis über die Ausrüstung), nur ohne Namen?
- **Vorschlag:** Ansprechpartner ist die Stadt (E1), Holger hilft technisch. Kurzfristig ein
  Ablauf auf Papier (Admin anonymisiert das Konto; Super-Admin bzw. Holger erstellt die Auskunft aus
  der Datenbank). Später (in `docs/TODO-SPAETER.md` aufnehmen) ein Export „Meine Daten“ für das eigene
  Konto bzw. für den Admin: Kontodaten plus alle Einträge im Verlauf und in Hinweisen mit diesem Konto.
  Für Namen der Rückgabe ohne Anmeldung ist eine Auskunft nur über eine Suche im Namen möglich (keine
  Zuordnung zu einem Konto).
- **Begründung:** Bis zum Start reicht ein beschriebener Ablauf; die Werkzeuge kann man nachrüsten.

### E7 – Weitere kleinere Fragen

- **Gemeinsam genutzte Handys** (Wache, Fahrzeug): Bleiben Konten angemeldet, sind E-Mail und Name im
  Browser gespeichert (1.5), und andere sehen die Konten unter „Konto wechseln“ und auf der
  Anmeldeseite. Vorschlag: in der Anleitung festhalten, dass auf gemeinsamen Geräten abgemeldet wird;
  Frage, ob „Konto wechseln“ dort abschaltbar sein soll.
- **E-Mail in Hinweisen**: Bewusste Ausnahme für die Rückfrage. Frage, ob die Feuerwehr damit
  einverstanden ist, dass Rollen mit Bereich `log` und die Empfänger der Mails „Log“ die E-Mail-Adressen
  der Buchenden sehen.
- **Benachrichtigung „Log“ an Super-Admins**: Super-Admins (Holger) bekommen auf Wunsch Mails aus allen
  Städten mit Namen. Frage, ob das im Betrieb von Immendingen gewollt ist (Vorschlag: für echte Städte
  aus, nur auf Anfrage der Stadt).
- **Sicherungen mit Zugangsdaten** (`ff-deploy-*.tar.gz` enthält `config/config.local.php`): kein
  Datenschutz im engeren Sinn, aber festhalten, dass `_backup` gesperrt bleibt.
- **Datenschutzbeauftragter**: Frage, ob die Gemeinde einen hat und ob er diese Datei bekommen soll.
