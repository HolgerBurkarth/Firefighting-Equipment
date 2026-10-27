# Firefighting-Equipment

## REST-API

### Adressen

```
https://www.prodad.de/firefighting/<kürzel>/<pfad>    # Stadt
https://www.prodad.de/firefighting/service/<pfad>     # Super-Admin
```

| Methode | Pfad                                  | Zugriff    | Antwort                                        |
|---------|---------------------------------------|------------|------------------------------------------------|
| GET     | `api/v1/health`                       | öffentlich | `{"status":"ok","database":"ok"}`              |
| GET     | `api/v1/tenant`                       | öffentlich | `{"slug":…,"name":…,"accent_color":…,…}`       |
| POST    | `api/v1/tenant/appearance`            | Admin      | wie `api/v1/tenant`                            |
| POST    | `api/v1/tenant/logo`                  | Admin      | wie `api/v1/tenant`                            |
| DELETE  | `api/v1/tenant/logo`                  | Admin      | wie `api/v1/tenant`                            |
| GET     | `api/v1/tenant/logo/{größe}?v=…`      | öffentlich | PNG (512, 192, 180)                            |
| GET     | `api/v1/app-icon/{größe}?v=…`         | öffentlich | PNG (Standard-Symbol, auch unter `service`)    |
| GET     | `manifest.webmanifest`                | öffentlich | Web-App-Manifest                               |
| POST    | `api/v1/auth/login`                   | öffentlich | `{"user":…,"tenant":…,"session":…}` + Cookie   |
| POST    | `api/v1/auth/logout`                  | öffentlich | `{"logged_out":true}`, Cookie wird gelöscht    |
| GET     | `api/v1/auth/me`                      | angemeldet | wie Login                                      |
| GET     | `api/v1/auth/sessions`                | angemeldet | `{"sessions":[{"id":…,"current":true,…}]}`     |
| DELETE  | `api/v1/auth/sessions/{id}`           | angemeldet | `{"deleted":true,"current":false}`             |
| POST    | `api/v1/auth/sessions/logout-others`  | angemeldet | `{"ended_sessions":2}`                         |
| POST    | `api/v1/auth/password`                | angemeldet | wie Login, mit neuem Cookie                    |
| POST    | `api/v1/auth/password-reset/request`  | öffentlich | 202 `{"message":"Falls zu dieser Adresse …"}`  |
| POST    | `api/v1/auth/password-reset/confirm`  | öffentlich | `{"message":"Das Passwort wurde geändert. …"}` |
| POST    | `api/v1/auth/register`                | öffentlich | 202 `{"message":"Bitte prüfen Sie Ihr …"}`     |
| POST    | `api/v1/auth/email/verify`            | öffentlich | `{"message":"Ihre E-Mail-Adresse ist …"}`      |
| POST    | `api/v1/auth/email/resend`            | öffentlich | 202 `{"message":"Falls zu dieser Adresse …"}`  |
| GET     | `api/v1/invitation-codes`             | Admin      | `{"invitation_codes":[…]}` (ohne Klartext)     |
| POST    | `api/v1/invitation-codes`             | Admin      | 201 `{"invitation_code":…,"code":"ABCD-…"}`    |
| POST    | `api/v1/invitation-codes/{id}/revoke` | Admin      | `{"invitation_code":…}`                        |
| GET     | `api/v1/users[?status=…]`             | Admin      | `{"users":[…]}`                                |
| GET     | `api/v1/users/{id}`                   | Admin      | `{"user":…}`                                   |
| POST    | `api/v1/users/{id}/approve`           | Admin      | `{"user":…}`                                   |
| POST    | `api/v1/users/{id}/reject`            | Admin      | `{"user":…}` (anonymisiert)                    |
| POST    | `api/v1/users/{id}/lock`, `…/unlock`  | Admin      | `{"user":…}`                                   |
| POST    | `api/v1/users/{id}/role`              | Admin      | `{"user":…}`                                   |
| POST    | `api/v1/users/{id}/display-name`      | Admin      | `{"user":…}`                                   |
| DELETE  | `api/v1/users/{id}`                   | Admin      | `{"user":…}` (anonymisiert)                    |
| POST    | `api/v1/auth/invitation/accept`       | öffentlich | `{"message":"Ihr Konto ist eingerichtet. …"}`  |

Nur unter `service` (Super-Admin, siehe "Städte verwalten und Admins einladen"):

| Methode | Pfad                                                   | Antwort                                   |
|---------|--------------------------------------------------------|-------------------------------------------|
| GET     | `api/v1/tenants`                                       | `{"tenants":[…]}` mit Status und Zahlen   |
| GET     | `api/v1/tenants/slug-available?slug=…`                 | `{"slug":…,"valid":…,"available":…,…}`    |
| POST    | `api/v1/tenants`                                       | 201 `{"tenant":…,"user":…,"mail_sent":…}` |
| GET     | `api/v1/tenants/{id}`                                  | `{"tenant":…}`                            |
| POST    | `api/v1/tenants/{id}/rename`                           | `{"tenant":…}`                            |
| POST    | `api/v1/tenants/{id}/lock`, `…/unlock`                 | `{"tenant":…}`                            |
| POST    | `api/v1/tenants/{id}/admins`                           | 201 `{"user":…,"mail_sent":…}`            |
| POST    | `api/v1/tenants/{id}/users/{user}/resend-invitation`   | `{"user":…,"mail_sent":…}`                |
| GET     | `api/v1/tenants/{id}/users[?status=…]`                 | `{"users":[…]}` (nur lesend)              |

`api/v1/tenant`, das Erscheinungsbild (Logo, Manifest; das Standard-Symbol gibt es auch unter `service`), Registrierung, E-Mail-Bestätigung, Einladung annehmen, Einladungscodes und
Benutzerverwaltung gibt es nur unter einer Stadt (unter `service`: 404), die Städteverwaltung nur
unter `service`, alle anderen Routen unter `service` und unter jeder Stadt. Super-Admins melden sich nur unter `service` an, Konten einer Stadt
nur unter ihrer Stadt; ein Cookie gilt nur dort.

Unter einer Stadt antwortet die API nur, wenn die Stadt existiert und aktiv ist. Der Health-Check
prüft die Datenbankverbindung; bei einer Störung antwortet er mit 503 und
`{"status":"error","database":"error"}`. Das Deploy-Skript ruft ihn unter `service` auf.

### Stadt-Kürzel

Das Kürzel einer Stadt steht im Pfad (`/firefighting/<kürzel>/`). Frontend und Backend prüfen es
nach denselben Regeln (Backend: `TenantSlug`, zusätzlich CHECK in der Datenbank):

- Regulärer Ausdruck `^[a-z0-9]+(-[a-z0-9]+)*$`: nur Kleinbuchstaben `a-z`, Ziffern `0-9` und
  Bindestrich; der Bindestrich nicht am Anfang oder Ende und nicht doppelt. Keine Umlaute, kein
  `ß`, keine Leerzeichen, Punkte oder Unterstriche.
- Länge: mindestens 1, höchstens 50 Zeichen.
- Reserviert (nie für eine Stadt): `service` (Bereich des Super-Admins), auch in anderer
  Schreibweise. Als Teil eines Kürzels ist es erlaubt (`service-stadt`).
- Großbuchstaben werden abgelehnt, nicht still umgewandelt; ist die Form in Kleinbuchstaben gültig,
  wird sie vorgeschlagen („Immendingen“ → „immendingen“).
- Eindeutig über alle Städte, auch gesperrte. Nach dem Anlegen unveränderlich (Umbenennen ändert
  nur den Namen); Städte werden nie gelöscht, ein Kürzel wird also nie wieder frei.

Reihenfolge der Prüfungen (die erste verletzte Regel bestimmt die Meldung): leer → länger als
50 Zeichen → reserviert (in Kleinbuchstaben verglichen) → Großbuchstaben, deren Kleinschreibung
gültig wäre (mit Vorschlag) → Format.

| Eingabe          | Ergebnis                                                         |
|------------------|------------------------------------------------------------------|
| `bad-duerrheim`  | gültig                                                           |
| `stadt-2`, `123` | gültig                                                           |
| `Bad-Duerrheim`  | ungültig, Vorschlag `bad-duerrheim`                              |
| `Service`        | ungültig: reserviert                                             |
| `tübingen`       | ungültig: Format (Umlaut)                                        |
| `bad--duerrheim` | ungültig: Format (doppelter Bindestrich)                         |

Vor dem Anlegen kann das Frontend `GET api/v1/tenants/slug-available?slug=…` (nur Super-Admin)
fragen, siehe "Städte verwalten und Admins einladen".

### Fehler

Alle Fehler haben das Format `{"error":"code","message":"deutscher Text"}`. Manche Fehler enthalten
zusätzlich ein Objekt `details` mit maschinenlesbaren Angaben, z. B. beim Sperren eines Standorts, der
nicht leer ist: `{"error":"rule_violation","message":"…","details":{"devices":2,"sublocations":1}}`.

| HTTP | `error`                    | Bedeutung                                                  |
|------|----------------------------|------------------------------------------------------------|
| 400  | `invalid_request`          | Body kein JSON-Objekt, zu groß (> 16 KiB, CSV-Import 3 MiB), Feld fehlt, Import-Datei unlesbar |
| 400  | `invalid_token`            | Link (Zurücksetzen, Bestätigen, Einladung) ungültig, abgelaufen, benutzt |
| 400  | `invalid_invitation_code`  | Einladungscode ungültig, abgelaufen, aufgebraucht, widerrufen |
| 401  | `not_authenticated`        | keine oder abgelaufene Sitzung                             |
| 401  | `invalid_credentials`      | E-Mail oder Passwort falsch (bewusst nicht unterschieden)  |
| 403  | `forbidden`                | angemeldet, aber die Rolle reicht nicht                    |
| 403  | `origin_not_allowed`       | Header `Origin` nicht erlaubt (Schutz gegen CSRF)          |
| 403  | `account_locked`           | Passwort richtig, Konto gesperrt                           |
| 403  | `email_not_verified`       | Passwort richtig, E-Mail-Adresse noch nicht bestätigt      |
| 403  | `pending_approval`         | Passwort richtig, Freischaltung durch Admin steht aus      |
| 403  | `invalid_current_password` | Passwort ändern: aktuelles Passwort falsch                 |
| 403  | `tenant_inactive`          | Stadt gesperrt                                             |
| 404  | `not_found`                | Adresse oder Kürzel ungültig, Pfad unbekannt               |
| 404  | `tenant_not_found`         | Kürzel gültig, aber keine Stadt dazu                       |
| 405  | `method_not_allowed`       | Methode nicht erlaubt (erlaubte im Header `Allow`)         |
| 409  | `rule_violation`           | Regel verletzt (letzter Admin, sich selbst sperren, …)     |
| 409  | `slug_taken`               | Super-Admin: Kürzel einer neuen Stadt schon vergeben       |
| 409  | `email_taken`              | Super-Admin: Adresse hat in der Stadt schon ein Konto      |
| 409  | `name_taken`               | Name eines Gerätetyps bzw. Standorts schon vergeben        |
| 409  | `identifier_taken`         | Kennung eines Geräts schon vergeben (ohne Groß-/Kleinschreibung) |
| 409  | `scan_code_taken`          | Scan-Code eines Geräts schon vergeben (exakter Vergleich)  |
| 409  | `version_conflict`         | Gerät inzwischen geändert; aktueller Stand in `details.device` |
| 409  | `import_outdated`          | CSV-Import: seit der Vorschau geändert oder Datei fehlerhaft; Vorschau neu erstellen |
| 409  | `client_ref_conflict`      | Buchung: dieselbe `client_ref` schon für eine andere Buchung verwendet |
| 409  | `stale_action`             | Offline-Buchung zu alt; nach Rückfrage mit `confirmed_stale` senden |
| 415  | `unsupported_media_type`   | ändernde Anfrage ohne `Content-Type: application/json`     |
| 422  | `weak_password`            | neues Passwort verletzt die Passwortregeln                 |
| 422  | `clock_implausible`        | Offline-Buchung: Aktionszeit mehr als 5 Minuten in der Zukunft |
| 429  | `too_many_attempts`        | zu viele Fehlversuche (Wartezeit im Header `Retry-After`)  |
| 503  | `database_unavailable`     | Datenbank nicht erreichbar                                 |
| 500  | `internal_error`           | unerwarteter Fehler; Details nur im Server-Log             |

Alle Antworten tragen `Cache-Control: no-store` und `X-Content-Type-Options: nosniff`.

### Anmeldung und Sitzungen

- Nach dem Login steht die Sitzung im Cookie `ff_session` (`HttpOnly`, `Secure`,
  `SameSite=Strict`, Pfad `/firefighting/<kürzel>/` bzw. `/firefighting/service/`, `Max-Age` =
  Höchstdauer der Rolle). Die Datenbank kennt nur den SHA-256 des Tokens.
- Sitzungsdauer (Inaktivität / Höchstdauer): `user` 30 / 90 Tage, `admin` 7 / 30 Tage,
  `superadmin` 1 / 12 Stunden.
- Ändernde Anfragen (POST, DELETE) brauchen `Content-Type: application/json` und einen JSON-Body,
  auch wenn er leer ist (`{}`). Ein mitgesendeter `Origin` muss `https://www.prodad.de` sein; lokal
  lassen sich weitere über `http.allowed_origins` in `config/config.local.php` erlauben (siehe
  `config/config.example.php`).
- Bremse: 5 Fehlversuche je E-Mail und Stadt oder 30 je IP-Bereich (/24 bzw. /48) innerhalb von
  15 Minuten führen zu 429. Eine erfolgreiche Anmeldung setzt nur den Zähler der E-Mail zurück.
- Passwort ändern beendet alle anderen Sitzungen und ersetzt die aktuelle (neues Cookie).
- Nach jeder Passwortänderung (selbst geändert, zurückgesetzt oder per `bin/user.php`) bekommt das
  Konto die Mail "Ihr Passwort wurde geändert".
- `GET api/v1/auth/me` zeigt zusätzlich `protected` (geschütztes Konto, siehe "Super-Admins und
  Benutzer verwalten") und `notifications` (`{"device_log": true|false}`).

### Benachrichtigung "Log"

Admins (unter ihrer Stadt) und Super-Admins (unter `service`) können für ihr eigenes Konto eine Mail
bei jedem Vorgang der Geräte-Historie einschalten (anlegen, importieren, umbuchen/Status, aussondern,
wieder aufnehmen, Prüfdatum, Stammdaten). Standard: aus.

- `GET api/v1/auth/notifications` → `{"notifications":{"device_log":false}}`
- `POST api/v1/auth/notifications` mit `{"device_log":true}` bzw. `false` (nur echte JSON-Werte,
  sonst 400). Benutzer (Rolle `user`): 403.

Empfänger sind aktive, nicht gesperrte Konten mit eingeschalteter Option: Admins nur für Vorgänge
ihrer Stadt, Super-Admins für alle Städte. Wer den Vorgang auslöst, bekommt keine Mail. Je Vorgang
(Anfrage) gibt es eine Mail je Empfänger; ein CSV-Import ergibt eine Sammelmail mit Zusammenfassung
und höchstens 50 Zeilen Einzelheiten. Betreff z. B. "Log Feuerwehr Immendingen: M1 umgebucht, Status
defekt"; der Text nennt Wer, Wann (deutsche Zeit), Gerät(e), Von/Nach, Kommentar und den Link zur
Anwendung (Trennzeichen eines Scan-Codes als [GS], [RS], [EOT]). Gesendet wird erst nach dem Speichern; ein Versandfehler ändert am Vorgang nichts (nur
`mail_failed (device_log, Konto #ID)` im Server-Log). Begrenzung: höchstens 25 Empfänger je Vorgang
(zuerst die Admins der Stadt; Überschreitung als `device_log_recipients_capped` im Log) und 10 s
Zeitbudget je Anfrage für den Versand. Mehrere Mails gehen über eine SMTP-Verbindung (eine
Anmeldung; gemessen auf STRATO rund 0,22 s je Mail).

Legt eine Offline-Buchung einen Hinweis an (siehe "Hinweise"), geht statt der Mail zum Vorgang eine
Hinweis-Mail an dieselben Empfänger: Betreff „Log <Stadt>: Hinweis – <Kennung> <Grund>“ (z. B.
„Log Feuerwehr Immendingen: Hinweis – MA-01 Stand überschrieben“), Text mit Art, Gerät, der Buchung
(Wer mit E-Mail, Aktionszeit, Eingang, Wunsch, Kommentar) und dem anderen Stand. Versandfehler stehen
als `mail_failed (device_notice, Konto #ID)` im Log.

### Passwort vergessen

1. `POST api/v1/auth/password-reset/request` mit `{"email":"…"}` antwortet immer mit 202 und
   derselben Meldung, auch wenn es kein Konto zu der Adresse gibt, und frühestens nach 1,5 s.
   Nur aktive, nicht gesperrte Konten des Bereichs bekommen eine Mail mit dem Link
   `<base_url>/firefighting/<kürzel>/passwort-zuruecksetzen#token=…` (1 Stunde gültig, nur einmal
   nutzbar; ein neuer Link ersetzt den alten). Eine ungültige Schreibweise der Adresse gibt 400.
2. Die Seite hinter dem Link (Frontend, noch nicht gebaut) liest das Token hinter `#` und schickt
   `POST api/v1/auth/password-reset/confirm` mit `{"token":"…","new_password":"…"}`. Danach sind
   alle Sitzungen beendet; man meldet sich mit dem neuen Passwort an.

| Fall                                                        | Antwort                 |
|-------------------------------------------------------------|-------------------------|
| Link unbekannt, abgelaufen, benutzt, falscher Bereich       | 400 `invalid_token`     |
| Konto inzwischen gesperrt oder nicht mehr aktiv             | 400 `invalid_token`     |
| neues Passwort verletzt die Regeln (Link bleibt gültig)     | 422 `weak_password`     |
| > 3 Anforderungen je Adresse, > 20 je IP-Bereich pro Stunde | 429 `too_many_attempts` |
| > 10 ungültige Links je IP-Bereich in 15 Minuten            | 429 `too_many_attempts` |

Lokal ausprobieren (mit `mail.transport = file` und `app.base_url = http://localhost:8000`, siehe
"E-Mail-Versand"): `request.json` enthält `{"email":"admin@example.org"}`. Nach dem Anfordern das
Token aus der neuesten Datei in `var/mail/` kopieren und in `reset.json` schreiben
(`{"token":"…","new_password":"…"}`):

```
curl.exe -H "Content-Type: application/json" --data-binary "@request.json" http://localhost:8000/firefighting/immendingen/api/v1/auth/password-reset/request
curl.exe -H "Content-Type: application/json" --data-binary "@reset.json" http://localhost:8000/firefighting/immendingen/api/v1/auth/password-reset/confirm
```

`request.json` und `reset.json` danach löschen (nie committen).

### Einladung und Registrierung

Ablauf (nur unter einer Stadt):

1. Ein Admin legt einen Einladungscode an (`POST api/v1/invitation-codes` mit
   `{"label":"Dienstabend März","valid_days":14,"max_uses":25}`; `label` optional, `valid_days`
   1–90, `max_uses` 1–500). Den Code (`XXXX-XXXX-XXXX`) gibt es nur in dieser Antwort; gespeichert
   wird nur sein Hash. Die Liste zeigt Status (`active`, `expired`, `exhausted`, `revoked`),
   Nutzungen und Ersteller; `…/revoke` widerruft einen Code.
2. Die neue Person registriert sich: `POST api/v1/auth/register` mit
   `{"code":"…","email":"…","password":"…","name":"…"}`. Beim Code zählen Groß-/Kleinschreibung,
   Leerzeichen und Bindestriche nicht. Das Konto ist danach `unverified`, die Person bekommt einen
   Bestätigungslink `<base_url>/firefighting/<kürzel>/email-bestaetigen#token=…` (24 Stunden
   gültig). Gibt es die Adresse in der Stadt schon, entsteht kein Konto und der Code wird nicht
   verbraucht; die Antwort ist dieselbe (202, frühestens nach 1,5 s), der Besitzer der Adresse
   bekommt einen Hinweis.
3. Die Seite hinter dem Link (Frontend, noch nicht gebaut) schickt `POST api/v1/auth/email/verify`
   mit `{"token":"…"}`. Das Konto wartet dann auf Freischaltung (`pending`); alle aktiven, nicht
   gesperrten Admins der Stadt bekommen eine Mail. Einen neuen Link gibt es mit
   `POST api/v1/auth/email/resend` und `{"email":"…"}` (immer 202, gleiche Meldung).
4. Ein Admin schaltet frei (`POST api/v1/users/{id}/approve` mit
   `{"display_name":"…","role":"user"}`, Rolle `user` oder `admin`; Mail "Ihr Konto wurde
   freigeschaltet") oder lehnt ab (`…/reject`; das Konto wird anonymisiert, die Person bekommt
   "Ihre Registrierung wurde nicht freigegeben").

Nie bestätigte Registrierungen werden 7 Tage nach der Registrierung anonymisiert (beim nächsten
Aufruf von Registrierung, Bestätigung, Neu-Anfordern oder Benutzerliste der Stadt; kein Cronjob).
Die Nutzung des Einladungscodes wird dabei nicht zurückgegeben.

| Fall                                                                       | Antwort                       |
|----------------------------------------------------------------------------|-------------------------------|
| Code falsch, aus einer anderen Stadt, abgelaufen, aufgebraucht, widerrufen | 400 `invalid_invitation_code` |
| Passwort verletzt die Regeln (Code wird nicht verbraucht)                  | 422 `weak_password`           |
| > 10 ungültige Codes je IP-Bereich in 15 Minuten                           | 429 `too_many_attempts`       |
| > 3 Registrierungen je Adresse und Stadt, > 30 je IP-Bereich pro Stunde    | 429 `too_many_attempts`       |
| Bestätigungslink ungültig, abgelaufen, benutzt, Konto gesperrt             | 400 `invalid_token`           |
| > 10 ungültige Bestätigungslinks je IP-Bereich in 15 Minuten               | 429 `too_many_attempts`       |
| > 3 neue Links je Adresse und Stadt, > 20 je IP-Bereich pro Stunde         | 429 `too_many_attempts`       |

Benutzerverwaltung (nur Admins, nur Konten der eigenen Stadt; fremde IDs: 404):

- `GET api/v1/users` listet alle Konten außer gelöschten, `?status=pending` (bzw. `invited`,
  `unverified`, `active`, `deleted`) filtert.
- Sperren, Rollenwechsel und Löschen (= Anonymisieren) beenden alle Sitzungen des Kontos. Der letzte
  aktive Admin bleibt geschützt; ein Admin kann sich nicht selbst sperren, herabstufen oder löschen
  (409 `rule_violation`).

Lokal ausprobieren (Admin angemeldet wie unter "Lokal starten", Mails in `var/mail/`). In PowerShell
JSON mit Inhalt aus einer Datei senden (`--data-binary "@datei.json"`), weil PowerShell 5.1
Anführungszeichen nicht unverändert an `curl.exe` weitergibt:

```
# code.json:     {"label":"Dienstabend","valid_days":14,"max_uses":25}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@code.json" http://localhost:8000/firefighting/immendingen/api/v1/invitation-codes
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/invitation-codes
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/invitation-codes/3/revoke

# register.json: {"code":"ABCD-EFGH-JKMN","email":"max@example.org","password":"…","name":"Max Mustermann"}
curl.exe -H "Content-Type: application/json" --data-binary "@register.json" http://localhost:8000/firefighting/immendingen/api/v1/auth/register
# verify.json:   {"token":"<Token aus der Mail in var/mail/>"}
curl.exe -H "Content-Type: application/json" --data-binary "@verify.json" http://localhost:8000/firefighting/immendingen/api/v1/auth/email/verify
# resend.json:   {"email":"max@example.org"}
curl.exe -H "Content-Type: application/json" --data-binary "@resend.json" http://localhost:8000/firefighting/immendingen/api/v1/auth/email/resend

curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/users?status=pending"
# approve.json:  {"display_name":"Max","role":"user"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@approve.json" http://localhost:8000/firefighting/immendingen/api/v1/users/7/approve
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/users/7/reject
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/users/7/lock
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/users/7/unlock
# role.json:     {"role":"admin"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@role.json" http://localhost:8000/firefighting/immendingen/api/v1/users/7/role
# name.json:     {"display_name":"Max M."}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@name.json" http://localhost:8000/firefighting/immendingen/api/v1/users/7/display-name
curl.exe -b cookies.txt -X DELETE -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/users/7
```

Die JSON-Dateien danach löschen (`register.json` enthält ein Passwort, `verify.json` ein Token; nie
committen).

### Städte verwalten und Admins einladen

Nur Super-Admins, nur unter `/firefighting/service/` (Admins und Benutzer einer Stadt: 401). Städte
werden über ihre ID angesprochen (siehe `GET api/v1/tenants`).

1. Neue Stadt: `POST api/v1/tenants` mit `{"slug":"geisingen","name":"Feuerwehr Geisingen",
   "admin_email":"kommandant@example.org"}`. In einer Transaktion entstehen die Stadt (aktiv), das
   Konto des ersten Admins (Status `invited`, noch ohne Name und Passwort) und ein Einmal-Link. Der
   Admin bekommt eine Mail mit `<base_url>/firefighting/<kürzel>/einladung-annehmen#token=…`
   (24 Stunden gültig, nur einmal nutzbar). `mail_sent: false` heißt: Versand gescheitert, Einladung
   erneut senden.
2. Weitere Admins: `POST api/v1/tenants/{id}/admins` mit `{"email":"…"}` (gleicher Ablauf).
3. Einladung erneut senden (nur Status `invited`): `POST api/v1/tenants/{id}/users/{user}/resend-invitation`
   mit `{}`. Der neue Link ersetzt den alten.
4. Die Seite hinter dem Link (Frontend, noch nicht gebaut) schickt
   `POST /firefighting/<kürzel>/api/v1/auth/invitation/accept` mit
   `{"token":"…","name":"Erika Musterfrau","password":"…"}`. Danach ist das Konto aktiv (Name =
   Anzeigename, E-Mail gilt als bestätigt), eine Sitzung entsteht nicht: Anmeldung mit dem neuen
   Passwort. Alle aktiven Super-Admins bekommen die Mail "Einladung angenommen".

Kürzel vorab prüfen (für das Formular „Neue Stadt“): `GET api/v1/tenants/slug-available?slug=…`
antwortet immer mit 200 und `{"slug":"Geisingen","valid":false,"available":false,"message":"Das
Kürzel darf keine Großbuchstaben enthalten. Gemeint ist vermutlich „geisingen“.","suggestion":"geisingen"}`.
`slug` ist die Eingabe unverändert; ungültig → `valid: false` mit der Meldung der verletzten Regel
(siehe "Stadt-Kürzel") und ggf. `suggestion`; gültig → `available` sagt, ob es frei ist (auch eine
gesperrte Stadt belegt ihr Kürzel), `message` „Das Kürzel ist verfügbar.“ bzw. „Das Kürzel ist
bereits vergeben.“, `suggestion` `null`. Der Vorschlag selbst wird nicht auf Verfügbarkeit geprüft.
Fehlt `slug`: 400. Verbindlich bleibt `POST api/v1/tenants` (409 `slug_taken`), da jemand anderes
das Kürzel inzwischen vergeben haben kann.

Außerdem: `…/rename` mit `{"name":"…"}` ändert nur den Namen (das Kürzel ist unveränderlich);
`…/lock` sperrt die Stadt und beendet sofort alle Sitzungen ihrer Konten (nach dem Entsperren gelten
keine alten Sitzungen); `…/unlock` entsperrt. `GET api/v1/tenants/{id}/users` zeigt die Konten einer
Stadt nur lesend (Filter `?status=` wie bei `GET users`).

| Fall                                                                 | Antwort                   |
|----------------------------------------------------------------------|---------------------------|
| Kürzel/Name/E-Mail ungültig (z. B. Großbuchstaben, `service`)         | 400 `invalid_request`     |
| Kürzel schon vergeben                                                | 409 `slug_taken`          |
| Adresse hat in der Stadt schon ein Konto (auch offene Einladung)     | 409 `email_taken`         |
| Einladen oder erneut senden bei gesperrter Stadt                     | 409 `rule_violation`      |
| Erneut senden für ein Konto, das nicht (mehr) `invited` ist oder gesperrt | 409 `rule_violation` |
| Stadt oder Konto (der Stadt) unbekannt                               | 404 `not_found`           |
| Link ungültig, abgelaufen, benutzt, andere Stadt, Konto gesperrt     | 400 `invalid_token`       |
| Einladung annehmen bei gesperrter Stadt                              | 403 `tenant_inactive`     |
| Passwort verletzt die Regeln (Link bleibt gültig)                    | 422 `weak_password`       |
| > 10 ungültige Einladungslinks je IP-Bereich in 15 Minuten           | 429 `too_many_attempts`   |

Lokal ausprobieren (Super-Admin per `bin/user.php superadmin:create` anlegen, Mails in `var/mail/`):

```
# login-service.json: {"email":"chef@example.org","password":"…"}
curl.exe -c service-cookies.txt -H "Content-Type: application/json" --data-binary "@login-service.json" http://localhost:8000/firefighting/service/api/v1/auth/login
curl.exe -b service-cookies.txt http://localhost:8000/firefighting/service/api/v1/tenants
# tenant.json: {"slug":"geisingen","name":"Feuerwehr Geisingen","admin_email":"kommandant@example.org"}
curl.exe -b service-cookies.txt -H "Content-Type: application/json" --data-binary "@tenant.json" http://localhost:8000/firefighting/service/api/v1/tenants
curl.exe -b service-cookies.txt http://localhost:8000/firefighting/service/api/v1/tenants/3
# rename.json: {"name":"Freiwillige Feuerwehr Geisingen"}
curl.exe -b service-cookies.txt -H "Content-Type: application/json" --data-binary "@rename.json" http://localhost:8000/firefighting/service/api/v1/tenants/3/rename
curl.exe -b service-cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/service/api/v1/tenants/3/lock
curl.exe -b service-cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/service/api/v1/tenants/3/unlock
# admin.json: {"email":"vertretung@example.org"}
curl.exe -b service-cookies.txt -H "Content-Type: application/json" --data-binary "@admin.json" http://localhost:8000/firefighting/service/api/v1/tenants/3/admins
curl.exe -b service-cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/service/api/v1/tenants/3/users/12/resend-invitation
curl.exe -b service-cookies.txt "http://localhost:8000/firefighting/service/api/v1/tenants/3/users?status=invited"

# accept.json: {"token":"<Token aus der Mail in var/mail/>","name":"Erika Musterfrau","password":"…"}
curl.exe -H "Content-Type: application/json" --data-binary "@accept.json" http://localhost:8000/firefighting/geisingen/api/v1/auth/invitation/accept
```

Die JSON-Dateien und `service-cookies.txt` danach löschen (sie enthalten Passwörter, Tokens bzw.
die Sitzung; nie committen). Der Super-Admin bleibt höchstens 1 Stunde ohne Aktivität angemeldet.

### Super-Admins sperren und entsperren

Unter `/firefighting/service/` (nur Super-Admins): `GET api/v1/superadmins` (alle Super-Admins mit
`locked` und `protected`), `POST api/v1/superadmins/{id}/lock` bzw. `…/unlock` mit `{}`
(wiederholbar; Sperren beendet die Sitzungen des Kontos). Super-Admins werden nie gelöscht, nur
gesperrt. Abgelehnt mit 409 `rule_violation`: sich selbst sperren, ein geschütztes Konto sperren,
den letzten aktiven Super-Admin sperren. Unbekannte ID oder Konto einer Stadt: 404. Anlegen, Passwort
setzen und Schutz bleiben Sache der Kommandozeile (`bin/user.php`).

```
curl.exe -b service-cookies.txt http://localhost:8000/firefighting/service/api/v1/superadmins
curl.exe -b service-cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/service/api/v1/superadmins/2/lock
```

### Gerätetypen und Standorte

Nur unter einer Stadt. Ansehen dürfen alle angemeldeten Konten der Stadt (`user` und `admin`),
ändern nur Admins (`user`: 403). Fremde oder unbekannte IDs im Pfad: 404. Gezählt werden immer nur
nicht ausgesonderte Geräte.

- Gerätetypen: `GET api/v1/device-types` listet die aktiven Typen nach Name (mit `device_count`),
  `?include_inactive=1` auch deaktivierte. `POST api/v1/device-types` mit `{"name":"…"}` legt an
  (höchstens 50 Zeichen), `…/{id}/rename` benennt um, `…/{id}/deactivate` und `…/{id}/activate`
  (Body `{}`) schalten ab bzw. wieder an. Ein deaktivierter Typ ist kein Ziel für neue Geräte;
  bestehende Geräte behalten ihn.
- Standorte: `GET api/v1/locations` liefert einen Baum: Standorte der obersten Ebene mit ihren
  Unterstandorten in `children`, jeweils nach Name. `device_count` zählt die Geräte direkt am
  Standort, `device_count_total` (nur oberste Ebene) auch die der Unterstandorte. Gesperrte
  Standorte nur mit `?include_inactive=1`. `GET api/v1/locations/{id}` zeigt einen Standort (oberste
  Ebene mit allen Unterstandorten, auch gesperrten).
- Art (`kind`, Bezeichnung in `kind_label`): `vehicle` (Fahrzeug), `room` (Raum/Lager), `external`
  (Extern). Höchstens zwei Ebenen; ein Unterstandort hat immer die Art seines übergeordneten
  Standorts.
- Anlegen: `POST api/v1/locations` mit `{"name":"HLF 20","kind":"vehicle"}` (oberste Ebene) bzw.
  `{"name":"Fach 1","parent_id":5}` (Unterstandort; `kind` weglassen oder gleich). Umbenennen:
  `…/{id}/rename`. Verschieben und Art ändern gibt es in Version 1 nicht.
- Sperren (`…/{id}/lock`, Body `{}`) nur, wenn der Standort leer ist: keine Geräte, keine aktiven
  Unterstandorte. Entsperren (`…/{id}/unlock`) eines Unterstandorts nur, wenn der übergeordnete
  Standort aktiv ist. Beides ist wiederholbar.

| Fall                                                                 | Antwort                   |
|----------------------------------------------------------------------|---------------------------|
| Name leer, zu lang (Typ 50, Standort 100 Zeichen), mit Zeilenumbruch | 400 `invalid_request`     |
| Oberste Ebene ohne `kind`, unbekannte Art                            | 400 `invalid_request`     |
| `parent_id` keine Zahl, unbekannt oder aus einer anderen Stadt       | 400 `invalid_request`     |
| `?include_inactive=` mit anderem Wert als `0`/`1`                    | 400 `invalid_request`     |
| Name schon vergeben (Typ je Stadt, Standort je Ebene bzw. übergeordnetem Standort; ohne Groß-/Kleinschreibung, „Straße“ = „Strasse“, auch gesperrte) | 409 `name_taken` |
| Unterstandort unter einem Unterstandort                              | 409 `rule_violation`      |
| Unterstandort unter gesperrtem Standort oder mit anderer Art         | 409 `rule_violation`      |
| Sperren, obwohl Geräte oder aktive Unterstandorte da sind            | 409 `rule_violation` mit `details` |
| Unterstandort entsperren, übergeordneter Standort gesperrt           | 409 `rule_violation`      |

Lokal ausprobieren (Admin angemeldet wie unter "Lokal starten"):

```
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/device-types
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/device-types?include_inactive=1"
# type.json:     {"name":"Hitzeschutzhaube"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@type.json" http://localhost:8000/firefighting/immendingen/api/v1/device-types
# type-name.json: {"name":"Flammschutzhaube"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@type-name.json" http://localhost:8000/firefighting/immendingen/api/v1/device-types/5/rename
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/device-types/5/deactivate
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/device-types/5/activate

curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/locations
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/locations?include_inactive=1"
# vehicle.json:  {"name":"HLF 20","kind":"vehicle"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@vehicle.json" http://localhost:8000/firefighting/immendingen/api/v1/locations
# fach.json:     {"name":"Fach 1","parent_id":1}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@fach.json" http://localhost:8000/firefighting/immendingen/api/v1/locations
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/locations/1
# location-name.json: {"name":"HLF 20/16"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@location-name.json" http://localhost:8000/firefighting/immendingen/api/v1/locations/1/rename
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/locations/2/lock
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/locations/2/unlock
```

### Geräte

Nur unter einer Stadt. Ansehen, umbuchen und den Status setzen dürfen alle angemeldeten Konten der
Stadt; anlegen, Stammdaten und Prüfdatum ändern, aussondern und wieder aufnehmen nur Admins
(`user`: 403). Fremde oder unbekannte IDs im Pfad: 404.

| Methode | Pfad                                  | Zugriff    | Antwort                                     |
|---------|---------------------------------------|------------|---------------------------------------------|
| GET     | `api/v1/devices`                      | angemeldet | `{"devices":[…],"total":…,"limit":…,"offset":…}` |
| GET     | `api/v1/devices/{id}`                 | angemeldet | `{"device":…}` mit allen Feldern            |
| POST    | `api/v1/devices`                      | Admin      | 201 `{"device":…}`                          |
| POST    | `api/v1/devices/{id}/fields`          | Admin      | `{"device":…}`                              |
| POST    | `api/v1/devices/{id}/inspection-due`  | Admin      | `{"device":…}`                              |
| POST    | `api/v1/devices/{id}/state`           | angemeldet | `{"device":…,"result":…}` (siehe "Offline-Buchungen") |
| POST    | `api/v1/devices/{id}/retire`          | Admin      | `{"device":…}`                              |
| POST    | `api/v1/devices/{id}/reinstate`       | Admin      | `{"device":…}`                              |
| GET     | `api/v1/devices/{id}/events`          | angemeldet | `{"events":[…],"total":…,"limit":…,"offset":…}` |
| GET     | `api/v1/devices/export`               | angemeldet | CSV-Download (siehe "CSV-Export")           |
| GET     | `api/v1/device-events/export`         | angemeldet | CSV-Download (siehe "CSV-Export")           |

- Liste: je Gerät `id`, `identifier`, `scan_code`, `device_type` (`id`, `name`), `location` (`id`, `name`,
  `path` wie „HLF 20 / G1“), `status` mit `status_label` (einsatzbereit, in Wartung, defekt,
  ausgesondert), `next_inspection_due`, `version`. Die Einzelansicht zeigt zusätzlich
  `serial_number`, `manufacturer`, `model`, `procured_on`, `note`, `created_at`, `updated_at`.
- Parameter der Liste (alle optional):
  - `device_type_id`, `location_id`; `include_sublocations=1` (nur mit `location_id`) schließt die
    Geräte der Unterstandorte ein. Unbekannte oder fremde IDs ergeben eine leere Liste.
  - `status=ready,defective` (kommagetrennt). Ausgesonderte Geräte (`retired`) erscheinen nur, wenn
    `retired` angegeben ist oder (ohne `status`) mit `include_retired=1`.
  - `q`: Teil von Kennung, Scan-Code, Seriennummer, Hersteller oder Modell, ohne
    Groß-/Kleinschreibung (höchstens 100 Zeichen; `%` und `_` sind keine Platzhalter).
  - `scan_code`: genau dieser Scan-Code (exakt, mit Groß-/Kleinschreibung; Leerraum am Rand wird wie
    beim Speichern entfernt, ein mitgescanntes CR/LF stört also nicht). Mit allen anderen Parametern
    kombinierbar; leer oder ungültig: 400. Im URL kodiert, z. B. GS als `%1D`, `+` als `%2B`.
  - `sort`: `identifier` (Standard, Kennung natürlich: „M2“ vor „M10“), `device_type` (Name des
    Gerätetyps), `location` (angezeigter Pfad „HLF 20 / G1“), `status` (fachlich: einsatzbereit, in
    Wartung, defekt, ausgesondert) oder `next_inspection_due` (Geräte ohne Datum immer am Ende,
    in beiden Richtungen). Texte natürlich und ohne Groß-/Kleinschreibung („LF 8“ vor „LF 10“).
  - `dir=asc` (Standard) oder `dir=desc`. Bei Gleichstand immer natürlich nach Kennung, dann nach
    ID (beides aufsteigend), damit Seiten stabil sind. Ungültige Werte für `sort` oder `dir`: 400.
  - `limit` 1–200 (Standard 50), `offset` 0–1000000 (Standard 0); `total` zählt alle Treffer.
- Kennung: höchstens 30 Zeichen aus Buchstaben, Ziffern, Leerzeichen, `-`, `/`, `.`; Leerraum am
  Rand wird entfernt, mehrere Leerzeichen werden zu einem. Eindeutig je Stadt unter nicht
  ausgesonderten Geräten, ohne Groß-/Kleinschreibung („MA-01“ = „ma-01“), aber „ß“ ≠ „ss“ und
  „é“ ≠ „e“. Nach dem Aussondern ist die Kennung wieder frei.
- Scan-Code (`scan_code`, optional): Inhalt eines Barcodes, QR-Codes oder einer GS1-DataMatrix,
  höchstens 200 Zeichen, gespeichert wie gescannt und exakt verglichen („abc“ ≠ „ABC“, „ß“ ≠ „ss“).
  - Leerzeichen, Tab, CR und LF am Rand werden entfernt (Handscanner hängen oft CR/LF oder Tab an);
    danach leer bzw. `null` = kein Scan-Code.
  - Erlaubt sind die Trennzeichen GS (0x1D, GS1), RS (0x1E) und EOT (0x04) aus ISO/IEC 15434, in
    JSON als `\u001d` usw. Andere Steuerzeichen (auch Tab oder Zeilenumbruch mitten im Code, meist
    zwei zusammengefügte Scans) und unsichtbare Formatzeichen (z. B. Zero-Width-Space) werden
    abgelehnt. In Meldungen und Mails erscheinen die Trennzeichen als `[GS]`, `[RS]`, `[EOT]`.
  - Eindeutig je Stadt unter nicht ausgesonderten Geräten; nach dem Aussondern frei (bleibt aber am
    ausgesonderten Gerät gespeichert).- Datumsfelder (`procured_on`, `next_inspection_due`) im Format `JJJJ-MM-TT` zwischen 1950-01-01
  und 2099-12-31. Leere Texte werden zu `null`. Nur die Notiz darf Zeilenumbrüche enthalten.
- Anlegen: Pflicht `identifier`, `device_type_id` (aktiver Typ), `location_id` (aktiver Standort);
  optional `scan_code`, `status` (`ready` Standard, `maintenance`, `defective`), `serial_number`, `manufacturer`,
  `model` (je 100 Zeichen), `procured_on`, `next_inspection_due`, `note` (1000 Zeichen) und
  `comment` (500 Zeichen, einzeilig) für die Historie.
- Ändern mit erwarteter `version` (aus der letzten Antwort):
  - `…/fields`: nur die mitgesendeten Stammdaten (`identifier`, `scan_code`, `device_type_id`,
    `serial_number`, `manufacturer`, `model`, `procured_on`, `note`; `null` leert ein optionales Feld) und optional
    `comment`. Je geändertem Feld ein Eintrag in der Historie mit demselben Kommentar.
  - `…/inspection-due`: `next_inspection_due` (Datum oder `null` zum Entfernen), optional `comment`.
  - `…/state` (auch `user`): `location_id` (neuer Standort) und/oder `status` (`ready`,
    `maintenance`, `defective`), optional `comment`. Umbuchung und Statusänderung ergeben EINEN
    Eintrag in der Historie. Der neue Standort muss aktiv sein. Optional `client_ref`,
    `occurred_at`, `confirmed_stale` (siehe "Offline-Buchungen"); die Antwort enthält zusätzlich
    `result` mit dem Ergebnis für den Kameraden.
  - `…/retire` (Admin): nur `version` und optional `comment`. Das Gerät ist danach ausgesondert,
    erscheint nicht mehr in normalen Listen, und seine Kennung ist frei.
  - `…/reinstate` (Admin): `status` (Pflicht, nicht `retired`), optional `location_id` (sonst der
    bisherige Standort), `identifier` (neue Kennung, falls die alte inzwischen vergeben ist),
    `scan_code` (neuer Scan-Code oder `null` zum Entfernen, falls der alte inzwischen vergeben ist),
    `device_type_id` (falls der bisherige Typ deaktiviert ist) und `comment`. Standort und Typ
    müssen aktiv sein.
  - Jede Änderung erhöht `version` um 1. Ändert sich nichts (gleiche Werte), bleibt alles wie es
    ist, auch die Version. Bei `…/state` und `…/reinstate` werden gleiche Teile (z. B. der aktuelle
    Standort) einfach übergangen.
  - Ist die Version veraltet: 409 `version_conflict`, `details.device` enthält den aktuellen Stand.
  - Ausgesonderte Geräte lassen sich nur wieder aufnehmen (alles andere 409 `rule_violation`).
- Historie (`…/events`, auch für ausgesonderte Geräte): neueste zuerst, `limit`/`offset` wie bei der
  Liste. Je Eintrag `type` mit `type_label` (z. B. „Umgebucht“, „Status geändert“, „Ausgesondert“,
  „Wieder aufgenommen“, „Prüfdatum geändert“, „Stammdaten geändert“, „Umbuchung nicht übernommen“),
  `created_at` = `received_at` (Eingang beim Server, UTC), `occurred_at` (Aktionszeit: bei
  Offline-Buchungen die Zeit auf dem Handy, sonst wie `received_at`), `applied` (`false` = nicht
  übernommen, nur Historie; dann nur „nach“ gefüllt) mit `not_applied_reason`/`not_applied_label`,
  `client_ref` (falls die Buchung eine hatte), `user`
  (`id`, `name` = Anzeigename bzw. „Gelöschter Benutzer #ID“), `comment` sowie je nach Art
  `location` (`from`/`to` mit `id`, `name`, `path`), `status` (`from`/`to` mit deutscher
  Bezeichnung), `inspection_due` (`from`/`to`) und `field` (`name`, `label`, `old`, `new`); was die
  Art nicht kennt, ist `null`. Standorte erscheinen mit ihrem heutigen Namen (nach dem Umbenennen
  also mit dem neuen). Reihenfolge nach Eingang beim Server (so, wie der Server die Buchungen
  verarbeitet hat); die Aktionszeit steht daneben.

| Fall                                                                    | Antwort                |
|-------------------------------------------------------------------------|------------------------|
| Feld fehlt, falscher Typ, unbekanntes Feld, Wert verletzt die Regeln    | 400 `invalid_request`  |
| `device_type_id`/`location_id` im Body unbekannt oder aus anderer Stadt | 400 `invalid_request`  |
| `…/fields` ohne Stammdaten, `status` oder `location_id` mitgesendet     | 400 `invalid_request`  |
| `…/state` ohne `location_id` und `status`; `status` = `retired`         | 400 `invalid_request`  |
| Parameter der Liste ungültig (z. B. `limit=500`, `status=kaputt`)       | 400 `invalid_request`  |
| Kennung schon vergeben (auch beim Wiederaufnehmen)                      | 409 `identifier_taken` |
| Scan-Code schon vergeben (auch beim Wiederaufnehmen)                    | 409 `scan_code_taken`  |
| Version veraltet                                                        | 409 `version_conflict` |
| Standort gesperrt, Gerätetyp deaktiviert, Gerät ausgesondert            | 409 `rule_violation`   |
| Aussondern eines ausgesonderten, Wiederaufnahme eines nicht ausgesonderten Geräts | 409 `rule_violation` |

Lokal ausprobieren (Admin angemeldet wie unter "Lokal starten"):

```
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/devices
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/devices?location_id=1&include_sublocations=1&status=ready,maintenance&q=ma&sort=next_inspection_due&dir=desc&limit=20&offset=0"
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/devices?include_retired=1"
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/devices?scan_code=0104006381333931%1D10AB12"
# device.json:   {"identifier":"MA-01","device_type_id":1,"location_id":2,"manufacturer":"Dräger","model":"FPS 7000","next_inspection_due":"2027-03-01","comment":"Neu beschafft"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@device.json" http://localhost:8000/firefighting/immendingen/api/v1/devices
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/devices/1
# fields.json:   {"version":1,"serial_number":"ARFK-1234","note":null,"comment":"Seriennummer nachgetragen"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@fields.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/fields
# due.json:      {"version":2,"next_inspection_due":"2028-03-01","comment":"Prüfung bestanden"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@due.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/inspection-due
# Dieselbe Datei noch einmal: Version 2 ist jetzt veraltet → 409 version_conflict mit details.device
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@due.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/inspection-due
# move.json:     {"version":3,"location_id":1,"status":"maintenance","comment":"Nach Einsatz zur Wartung"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@move.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/state
# status.json:   {"version":4,"status":"defective"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@status.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/state
# retire.json:   {"version":5,"comment":"Nicht mehr reparierbar"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@retire.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/retire
# reinstate.json: {"version":6,"status":"ready","location_id":2,"comment":"Doch repariert"}
#   Kennung inzwischen vergeben → 409 identifier_taken; dann mit neuer Kennung:
#   {"version":6,"status":"ready","location_id":2,"identifier":"MA-01a"}
#   Scan-Code inzwischen vergeben → 409 scan_code_taken; dann neuer Scan-Code oder ohne:
#   {"version":6,"status":"ready","location_id":2,"scan_code":null}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@reinstate.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/reinstate
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/devices/1/events?limit=20&offset=0"
```

Hinweis zum letzten Aufruf: Beim zweiten Senden ist das Datum unverändert, die Version wird aber
trotzdem zuerst geprüft, daher der Konflikt.

### Offline-Buchungen

Umbuchen und Status setzen (`POST api/v1/devices/{id}/state`) geht auch ohne Netz: Das Handy merkt
sich die Buchung und sendet sie später. Andere Aktionen (Admin) gibt es offline nicht. Zusätzliche
Felder (alle optional):

| Feld              | Format                                         | Bedeutung |
|-------------------|------------------------------------------------|-----------|
| `client_ref`      | UUID Version 4 (z. B. `crypto.randomUUID()`)    | Kennung der Buchung; dieselbe Buchung erneut gesendet wirkt nicht noch einmal |
| `occurred_at`     | `JJJJ-MM-TTTHH:MM:SSZ` (UTC, Bruchteile erlaubt, werden abgeschnitten) | Aktionszeit auf dem Handy; nur mit `client_ref` |
| `confirmed_stale` | `true`/`false`                                 | alte Buchung nach Rückfrage trotzdem senden; nur mit `occurred_at` |

`client_ref` lohnt sich auch online: Geht die Antwort verloren, darf das Handy dieselbe Anfrage
gefahrlos wiederholen.

**Ablauf beim Server:**

1. Felder prüfen (400).
2. Gibt es die `client_ref` in der Stadt schon und ist der Inhalt gleich (Konto, Gerät, Version,
   Standort, Status, Kommentar, Aktionszeit; `confirmed_stale` zählt nicht), kommt das gespeicherte
   Ergebnis mit `"replayed": true` und dem aktuellen Gerätestand zurück; nichts wird geschrieben.
   Mit anderem Inhalt: 409 `client_ref_conflict`.
3. Aktionszeit prüfen: mehr als 5 Minuten (300 s) nach der Serverzeit → 422 `clock_implausible`
   (`details`: `server_time`, `occurred_at`, `max_skew_seconds`); älter als
   `offline.queue_max_age_days` ohne `confirmed_stale` → 409 `stale_action` (`details`:
   `occurred_at`, `queue_max_age_days`, `server_time`). Genau auf der Grenze ist erlaubt.
4. Buchen. Standort und Status haben am Gerät EINEN gemeinsamen Zeitstempel (Aktionszeit der
   Änderung, die den aktuellen Stand bestimmt). Mit `occurred_at` gilt „der Letzte gewinnt“:

| Lage                                                                  | Ergebnis (`outcome` / `reason`)   |
|-----------------------------------------------------------------------|-----------------------------------|
| Version aktuell, oder seitdem nur Stammdaten/Prüfdatum geändert       | `applied` (übernommen)            |
| Version veraltet, Stand inzwischen geändert, Aktionszeit jünger       | `applied` / `overwritten` + Hinweis |
| Version veraltet, Stand inzwischen geändert, Aktionszeit älter oder gleich | `history_only` / `older` + Hinweis |
| Gerät inzwischen ausgesondert                                         | `not_applied` / `retired` + Hinweis |
| neuer Standort inzwischen gesperrt                                    | `not_applied` / `location_locked` + Hinweis |
| Gerät hat schon diesen Standort und Status                            | `unchanged`                       |
| alte Buchung nach Rückfrage (sonst unauffällig)                       | wie oben, `stale_confirmed: true` + Hinweis |

Ohne `occurred_at` bleibt alles wie bisher (veraltete Version → 409 `version_conflict`, gesperrter
Standort oder ausgesondertes Gerät → 409 `rule_violation`). Ein unbekannter oder fremder Standort
ist immer 400. „Nur Historie“ und „nicht übernommen“ stehen in der Historie mit `applied: false` und
Grund; der aktuelle Stand bleibt.

Antwort (immer 200):

```json
{"device":{…aktueller Stand…},
 "result":{"outcome":"applied","outcome_label":"übernommen","reason":"overwritten",
           "reason_label":"Anderen Stand überschrieben",
           "message":"Übernommen. Ein zwischenzeitlich gebuchter, älterer Stand wurde überschrieben; …",
           "stale_confirmed":false,"client_ref":"3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f",
           "replayed":false,"notice_id":12}}
```

So behandelt das Handy die Antworten: 2xx → Buchung erledigt (aus der Warteschlange nehmen,
`message` anzeigen). 400, 404, `client_ref_conflict` → dauerhaft fehlerhaft, entfernen und Meldung
zeigen. `stale_action` → nachfragen und ggf. mit `"confirmed_stale": true` erneut senden.
`clock_implausible` → Uhr prüfen lassen; ggf. ohne `occurred_at` senden (dann gilt die Serverzeit
und die Versionsprüfung). 401 → neu anmelden, Buchung behalten. 5xx oder kein Netz → später erneut.

**Zeitlimits** (`GET api/v1/client-config`, angemeldet):

```json
{"server_time":"2026-10-27T13:05:09Z","offline":{"max_age_days":7,"queue_max_age_days":7},
 "max_clock_skew_seconds":300}
```

- `max_age_days`: So lange darf das Handy nach der letzten Synchronisierung offline buchen (prüft
  nur das Handy; der Server kennt die Synchronisierung nicht).
- `queue_max_age_days`: Ab diesem Alter fragt das Handy vor dem Senden nach.
- Einstellbar in der Konfigurationsdatei, Gruppe `offline` (je 1–365 Tage, Standard 7; siehe
  `config/config.example.php`). `server_time` hilft dem Handy, eine falsche Uhr zu erkennen.

Lokal ausprobieren (Benutzer oder Admin angemeldet wie unter "Lokal starten"):

```
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/client-config
# offline.json:  {"version":3,"location_id":1,"status":"defective","comment":"Nach Einsatz","client_ref":"3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f","occurred_at":"2026-10-27T08:15:00Z"}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@offline.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/state
# Dieselbe Datei noch einmal: "replayed": true, keine zweite Buchung
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@offline.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/state
# Ältere Buchung (z. B. 10 Tage) → 409 stale_action; nach Rückfrage:
# stale.json:    {"version":3,"status":"maintenance","client_ref":"9a8b7c6d-5e4f-4a3b-9c2d-1e0f9a8b7c6d","occurred_at":"2026-10-17T08:15:00Z","confirmed_stale":true}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@stale.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/state
```

### Hinweise (nur Admins)

Offline-Buchungen mit besonderem Ergebnis legen einen Hinweis für die Admins der Stadt an: anderer
Stand überschrieben, nur Historie, nicht übernommen (ausgesondert), nicht übernommen (Standort
gesperrt), alte Buchung nach Rückfrage. Hinweise werden nie automatisch erledigt und nie gelöscht.

| Methode | Pfad                                | Body       | Antwort |
|---------|-------------------------------------|------------|---------|
| GET     | `api/v1/notices[?status=open\|resolved\|all]` | – | `{"notices":[…],"total":…,"open":…,"limit":…,"offset":…}` |
| GET     | `api/v1/notices/{id}`               | –          | `{"notice":…}` |
| POST    | `api/v1/notices/{id}/resolve`       | `{note?}`  | `{"notice":…}` (erledigt) |
| POST    | `api/v1/notices/{id}/reopen`        | `{}`       | `{"notice":…}` (wieder offen) |

- Liste: Standard `status=open`, neueste zuerst, `limit` 1–200 (Standard 50), `offset`; `open` zählt
  alle offenen Hinweise der Stadt.
- Ein Hinweis enthält `kind` mit `kind_label`, `status` (`open`/`resolved`, „offen“/„erledigt“),
  `stale_confirmed`, `device` (`id`, `identifier`, `device_type`), `booking` (Konto mit Anzeigename
  und E-Mail, `occurred_at` = Aktionszeit, `received_at` = Eingang, gewünschter `location`/`status`,
  `client_ref`, `event_id`) und `other` (der andere Stand: Konto, Aktionszeit, Standort, Status; bei
  „überschrieben“ der überschriebene, sonst der Stand beim Eingang) sowie `history` (Verlauf:
  `action` `resolve`/`reopen`, Konto, Zeitpunkt, Notiz). Gelöschte Konten erscheinen als
  „Gelöschter Benutzer #ID“ ohne E-Mail.
- Erledigen mit optionaler Notiz (höchstens 500 Zeichen, Zeilenumbrüche erlaubt); Wiederöffnen ohne
  Notiz. Beides ist wiederholbar: Hat der Hinweis den Status schon, ändert sich nichts. Notizen
  bleiben im Verlauf erhalten, auch nach dem Wiederöffnen.
- `GET api/v1/device-summary` zeigt Admins zusätzlich `"notices":{"open":…}`; für Benutzer fehlt
  das Feld (Vorschlag fürs Frontend: bei `user` keinen Hinweis-Zähler anzeigen).
- Mail an alle Empfänger der Benachrichtigung „Log“ (siehe dort), nie an den Auslöser.

Lokal ausprobieren (Admin angemeldet):

```
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/notices
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/notices?status=all&limit=20"
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/notices/12
# resolve.json:  {"note":"Mit dem Kameraden geklärt, Maske liegt im HLF."}
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@resolve.json" http://localhost:8000/firefighting/immendingen/api/v1/notices/12/resolve
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/notices/12/reopen
```

### Prüffristen und Zusammenfassung

Nur unter einer Stadt, für alle angemeldeten Konten der Stadt (`user` und `admin`). Ausgesonderte
Geräte sind nie enthalten. „Heute“ ist der Kalendertag in deutscher Zeit (Europe/Berlin): Um
00:30 Uhr am 16. Juni ist ein Gerät mit Prüfdatum 15. Juni überfällig, obwohl in UTC noch der
15. ist.

| Methode | Pfad                     | Zugriff    | Antwort                                                     |
|---------|--------------------------|------------|-------------------------------------------------------------|
| GET     | `api/v1/inspection-due`  | angemeldet | `{"today":…,"days":…,"until":…,"overdue":{…},"due_soon":{…}}` |
| GET     | `api/v1/device-summary`  | angemeldet | `{"today":…,"devices":{…},"inspection":{…}}`                |

- `inspection-due`: Abschnitte `overdue` (Prüfdatum vor heute, am stärksten überfällig zuerst) und
  `due_soon` (heute bis einschließlich `until` = heute + `days`, nächste Fälligkeit zuerst), bei
  gleichem Datum natürlich nach Kennung. Jeder Abschnitt ist `{"devices":[…],"total":…}`; die Geräte
  wie in der Geräteliste, zusätzlich `days_overdue` (ab 1) bzw. `days_until_due` (0 = heute fällig).
  Parameter (alle optional):
  - `days` 1–365 (Standard 30).
  - `include_without_date=1`: zusätzlich Abschnitt `without_date` (Geräte ohne Prüfdatum, nach
    Kennung); ohne den Parameter fehlt das Feld.
  - `device_type_id`, `location_id`, `include_sublocations=1` wie in der Geräteliste.
  - `limit` 1–500 je Abschnitt (Standard 200), kein `offset`; `total` zählt alle Treffer.
  - Andere Parameter (z. B. `status`, `include_retired`) werden ignoriert. Ungültige Werte: 400.
- `device-summary` (Startseite, eine Abfrage, keine Parameter): `devices.total` und
  `devices.by_status` (immer `ready`, `maintenance`, `defective` mit `status_label` und `count`, auch
  0), `inspection` mit `overdue`, `due_soon` (heute bis heute + 30 Tage, `due_soon_days`) und
  `without_date`. Nur für Admins zusätzlich `notices` mit `open` (offene Hinweise, siehe "Hinweise").

Lokal ausprobieren (angemeldet wie unter "Lokal starten"):

```
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/inspection-due
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/inspection-due?days=60&include_without_date=1"
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/inspection-due?location_id=1&include_sublocations=1&device_type_id=1&limit=50"
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/device-summary
```

Beispielantwort von `device-summary`:

```json
{"today":"2026-10-26",
 "devices":{"total":42,"by_status":[{"status":"ready","status_label":"einsatzbereit","count":38},
                                     {"status":"maintenance","status_label":"in Wartung","count":3},
                                     {"status":"defective","status_label":"defekt","count":1}]},
 "inspection":{"overdue":3,"due_soon":7,"due_soon_days":30,"without_date":12}}
```

### CSV-Export

Nur unter einer Stadt, für alle angemeldeten Konten der Stadt (`user` und `admin`). Die Antwort ist
ein Download (`Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment`,
`Cache-Control: no-store`); Fehler (z. B. ungültige Parameter, 400) kommen wie immer als JSON.

| Methode | Pfad                          | Zugriff    | Datei                                      |
|---------|-------------------------------|------------|--------------------------------------------|
| GET     | `api/v1/devices/export`       | angemeldet | `geraete-<kürzel>-<JJJJ-MM-TT>.csv`        |
| GET     | `api/v1/device-events/export` | angemeldet | `historie-<kürzel>-<von>-bis-<bis>.csv`    |

Format (für Excel unter Windows gedacht, Doppelklick öffnet die Datei mit korrekten Umlauten):

- UTF-8 mit BOM, Semikolon als Trennzeichen, Zeilenende CRLF.
- Felder mit Semikolon, Anführungszeichen oder Zeilenumbruch stehen in Anführungszeichen
  (RFC 4180, `"` wird zu `""`); Zeilenumbrüche in Notizen bleiben innerhalb des Felds erhalten.
- Deutsche Spaltenüberschriften und Bezeichnungen (z. B. „in Wartung“), Datum `TT.MM.JJJJ`,
  Zeitpunkte in deutscher Zeit als `TT.MM.JJJJ HH:MM`.
- Schutz gegen Formeln: Werte, die mit `=`, `+`, `-`, `@`, Tab, CR oder `'` beginnen, erhalten ein
  vorangestelltes `'` (z. B. `'-12`). Der Import entfernt genau ein führendes `'` wieder.
- Bricht der Export mittendrin ab (z. B. Datenbankfehler), endet die Datei mit der Zeile
  `Export abgebrochen – Datei unvollständig`; der Fehler steht im Server-Log.

**Geräteliste** (`devices/export`): Spalten Kennung, Scan-Code, Gerätetyp, Standort (Pfad wie
„HLF 20 / G1“), Status, Nächste Prüfung, Seriennummer, Hersteller, Modell, Beschaffungsdatum, Notiz.
Der Scan-Code steht unverändert in der Datei (Trennzeichen wie GS als Steuerzeichen). Filter wie in
der Geräteliste (`device_type_id`, `location_id`, `include_sublocations=1`, `status`, `q`,
`scan_code`, `include_retired=1`), Sortierung ebenso (`sort`, `dir`; Standard natürlich nach Kennung), ohne
Seitenbegrenzung (`limit` und `offset` werden ignoriert). So kann das Frontend die Parameter der
angezeigten Liste unverändert weitergeben. Die Datei kann wieder importiert werden (siehe "CSV-Import").

**Historie** (`device-events/export`): alle Ereignisse der Stadt, auch ausgesonderter Geräte.
Spalten Aktionszeit (bei Offline-Buchungen die Zeit auf dem Handy), Eingang beim Server, Geräte-Nr.
(ID; unterscheidet Geräte, deren Kennung nach dem Aussondern neu vergeben wurde), Kennung und
Gerätetyp (aktueller Stand), Ereignis, Wer, Standort von/nach, Status von/nach, Prüfdatum alt/neu,
Feld, Alter Wert, Neuer Wert, Kommentar, Übernommen (Ja/Nein), Grund (nicht übernommen),
Buchungs-Kennung (`client_ref`). Zeitraum und Reihenfolge richten sich nach dem Eingang beim Server.
Parameter (alle optional):

- `from`, `to` (`JJJJ-MM-TT`, Kalendertage in deutscher Zeit, beide einschließlich). Standard: die
  letzten 90 Tage bis heute; nur `to` → 90 Tage bis dahin, nur `from` → bis heute. Höchstens 366
  Tage, sonst 400.
- `device_id`; `device_type_id` (aktueller Gerätetyp des Geräts); `location_id`: Ereignisse, deren
  Von- **oder** Nach-Standort dieser Standort ist, mit `include_sublocations=1` auch seine
  Unterstandorte (Prüfdatum- und Stammdatenänderungen haben keinen Standort und fallen dann heraus).
- `order=newest` (Standard) oder `oldest`.
- Höchstens 100.000 Einträge; mehr ergibt 409 `rule_violation` mit
  `details: {"events": …, "max": 100000}`, dann Zeitraum verkleinern oder filtern.

Lokal ausprobieren (angemeldet wie unter "Lokal starten"; `-o` speichert die Datei):

```
curl.exe -b cookies.txt -o geraete.csv http://localhost:8000/firefighting/immendingen/api/v1/devices/export
curl.exe -b cookies.txt -o geraete-hlf.csv "http://localhost:8000/firefighting/immendingen/api/v1/devices/export?location_id=1&include_sublocations=1&status=ready,defective"
curl.exe -b cookies.txt -o alle-geraete.csv "http://localhost:8000/firefighting/immendingen/api/v1/devices/export?include_retired=1"
curl.exe -b cookies.txt -o historie.csv http://localhost:8000/firefighting/immendingen/api/v1/device-events/export
curl.exe -b cookies.txt -o historie-2026.csv "http://localhost:8000/firefighting/immendingen/api/v1/device-events/export?from=2026-01-01&to=2026-12-31&order=oldest"
curl.exe -b cookies.txt -o historie-geraet.csv "http://localhost:8000/firefighting/immendingen/api/v1/device-events/export?device_id=7&from=2025-11-01"
```

Mit `-o` landet auch eine Fehlerantwort (JSON) in der Datei; `-f` (`--fail`) bricht stattdessen mit
Fehlermeldung ab.

### CSV-Import

Nur unter einer Stadt, nur für Admins. Der Import liest die Geräteliste im Format des Exports, legt
neue Geräte an und ändert bestehende. Er legt nie Gerätetypen oder Standorte an und sondert weder
aus noch nimmt er wieder auf. Ablauf in zwei Schritten:

| Methode | Pfad                            | Body                         | Wirkung                                  |
|---------|---------------------------------|------------------------------|------------------------------------------|
| POST    | `api/v1/devices/import/preview` | `{file}`                     | prüfen und Vorschau zeigen, nichts speichern |
| POST    | `api/v1/devices/import`         | `{file, token, comment?}`    | dieselbe Datei erneut prüfen und übernehmen |

`file` ist die Datei als Base64. Die Import-Routen nehmen Bodys bis 3 MiB an, alle anderen weiterhin
höchstens 16 KiB. Der Schutz gegen CSRF bleibt wie überall: nur `Content-Type: application/json`
und erlaubter `Origin`.

**Datei**

- Spalten wie im Export (Kennung, Scan-Code, Gerätetyp, Standort, Status, Nächste Prüfung,
  Seriennummer, Hersteller, Modell, Beschaffungsdatum, Notiz). Zugeordnet wird über die Überschrift, Reihenfolge
  und Groß-/Kleinschreibung sind egal. Unbekannte Spalten (z. B. Tippfehler „Seriennumer“) sind ein
  Fehler. Nur die Spalte Kennung ist Pflicht.
- Die Kennung ordnet die Zeile einem nicht ausgesonderten Gerät zu (ohne Groß-/Kleinschreibung,
  „ß“ ≠ „ss“). Ohne passendes Gerät entsteht ein neues; dafür sind Gerätetyp und Standort Pflicht, der
  Status ist ohne Angabe „einsatzbereit“. Dieselbe Kennung zweimal in der Datei ist ein Fehler.
- Fehlt eine Spalte, bleibt das Feld unverändert. Ist die Spalte da und die Zelle leer, wird ein
  optionales Feld geleert. Leere Zellen bei Gerätetyp, Standort und Status ändern ein bestehendes
  Gerät nicht.
- Gerätetyp über den Namen, Standort über den Pfad wie im Export („HLF 20 / G1“), jeweils wie in
  der Datenbank verglichen (ohne Groß-/Kleinschreibung, „Straße“ = „Strasse“). Unbekannte Namen und
  Pfade sind Fehler; die Vorschau listet sie unter `unknown` auf, damit sie zuerst angelegt werden
  können. Gesperrte Standorte und deaktivierte Gerätetypen sind kein neues Ziel.
- Status: einsatzbereit, in Wartung, defekt. Zeilen mit „ausgesondert“ werden übersprungen
  (Aussondern und Wiederaufnehmen nur einzeln). Ein Export mit ausgesonderten Geräten lässt sich
  daher ohne Fehler einlesen.
- Scan-Code: Regeln wie bei der Bearbeitung (siehe "Geräte"), exakt verglichen. Derselbe Scan-Code
  zweimal in der Datei ist ein Fehler, ebenso ein Scan-Code, der einem anderen nicht ausgesonderten
  Gerät gehört, auch wenn jenes ihn in derselben Datei abgibt: Scan-Codes lassen sich per Import
  nicht tauschen oder übertragen (dafür zuerst beim alten Gerät entfernen, dann neu importieren).
- Datum als `TT.MM.JJJJ`, `T.M.JJJJ` oder `JJJJ-MM-TT`. Ein führendes `'` (Formelschutz des Exports)
  wird immer genau einmal entfernt.
- Kodierung UTF-8 (mit oder ohne BOM) oder Windows-1252 (Excel „CSV (Trennzeichen-getrennt)“).
  UTF-16 („Unicode-Text“) wird abgelehnt. Trennzeichen Semikolon, Komma oder Tab, erkannt an der
  Kopfzeile.
- Grenzen: höchstens 2 MiB und 5000 Zeilen, sonst bitte aufteilen. Eine Datei aus einem
  abgebrochenen Export (letzte Zeile „Export abgebrochen – Datei unvollständig“) wird abgelehnt.
- Fehler der ganzen Datei (z. B. unbekannte Spalte, nicht geschlossenes Anführungszeichen) kommen als
  400 `invalid_request`, wenn möglich mit `details`, z. B. `{"line": 1, "columns": ["Seriennumer"]}`.

**Vorschau** (Auszug):

```json
{
  "token": "3f9a…(64 Zeichen, nur ohne Fehler, sonst null)",
  "encoding": "utf-8", "delimiter": ";", "delimiter_label": "Semikolon",
  "columns": ["Kennung", "Standort", "Status"],
  "summary": {"rows": 3, "new": 1, "changed": 1, "unchanged": 0, "skipped": 0, "errors": 1, "warnings": 1},
  "unknown": {"device_types": [], "locations": ["LF 8"]},
  "rows": [
    {"line": 2, "identifier": "MA-01", "result": "changed", "result_label": "Änderungen",
     "device": {"id": 7, "version": 3},
     "changes": [{"field": "location", "label": "Standort", "old": "HLF 20 / G1", "new": "Lager"}],
     "messages": [], "warnings": []},
    {"line": 3, "identifier": "7", "result": "new", "…": "…",
     "warnings": ["Die neue Kennung „7“ entspricht ohne führende Nullen dem vorhandenen Gerät „007“. …"]},
    {"line": 4, "identifier": "MA-09", "result": "error", "…": "…",
     "messages": ["Der Standort „LF 8“ ist nicht bekannt."]}
  ]
}
```

`result` ist `new`, `changed`, `unchanged`, `skipped` oder `error`; `line` ist die Zeilennummer wie in
Excel (Kopfzeile = 1). Warnungen blockieren nicht. Sie erscheinen, wenn Excel eine Kennung oder einen
Scan-Code vermutlich verändert hat: eine neue Kennung aus Ziffern, die ohne führende Nullen einem
vorhandenen Gerät entspricht; eine Kennung, die wie ein Datum aussieht; ein neuer oder geänderter
Scan-Code in wissenschaftlicher Schreibweise („4,00638E+12“) oder aus Ziffern, dem gegenüber einem
vorhandenen Scan-Code (desselben oder eines anderen Geräts) führende Nullen fehlen
(„4006381333931“ statt „04006381333931“).

**Übernehmen**: Nur mit dem `token` der Vorschau und nur, wenn die erneute Prüfung fehlerfrei ist und
seit der Vorschau kein betroffenes Gerät (Version), keine Zuordnung und nicht die Datei geändert
wurde. Sonst 409 `import_outdated` (in `details.summary` die Zahlen der neuen Prüfung); dann die
Vorschau neu erstellen. Alles oder nichts in einer Transaktion. Antwort `{"summary": …}`. Die
Historie zeigt neue Geräte als „Importiert“, Änderungen wie bei der Bearbeitung von Hand (Stammdaten
je Feld, Prüfdatum, Umbuchung und Status in einem Eintrag); der Kommentar steht an allen Einträgen.

**CSV in Excel sicher öffnen und speichern**

Ein Doppelklick auf die CSV-Datei öffnet sie zwar, Excel wandelt dabei aber Werte um: Aus „007“ wird
„7“, aus „1-3“ ein Datum, aus dem Scan-Code „04006381333931“ die Zahl „4,00638E+12“. Mehr als 15
Ziffern rundet Excel sogar auf Nullen („123456789012345678“ → „123456789012345000“); das kann der
Import nicht erkennen. Zum Bearbeiten daher so öffnen:

1. In einer leeren Arbeitsmappe **Daten → Aus Text/CSV** wählen und die Datei auswählen.
2. Dateiursprung „65001: Unicode (UTF-8)“ und Trennzeichen „Semikolon“ prüfen, dann **Daten
   transformieren** wählen.
3. Im Power-Query-Editor die Spalten **Kennung** und **Scan-Code** markieren, **Datentyp → Text**
   wählen und „Aktuelle ersetzen“ bestätigen. Am besten ebenso für Seriennummer und Modell. Dann
   **Schließen & laden**.
4. Nach dem Bearbeiten **Datei → Speichern unter → „CSV UTF-8 (durch Trennzeichen getrennt)“**
   wählen. Auch „CSV (Trennzeichen-getrennt)“ (Windows-1252) wird erkannt; Zeichen wie „ẞ“ gehen dort
   aber verloren.

In der Vorschau auf Warnungen achten: Sie zeigen, wenn Excel eine Kennung oder einen Scan-Code
trotzdem umgewandelt hat.

**Laufzeit und Server-Einstellungen**

Lokal gemessen (5000 Zeilen, 0,5 MiB): Vorschau 0,3–0,4 s; Übernehmen 0,8 s bei 5000 neuen Geräten,
2 s bei 5000 geänderten mit je 5 Ereignissen; Spitzenspeicher rund 45 MiB je Anfrage. Neue Geräte
und Ereignisse werden in Blöcken zu 500 Zeilen geschrieben, geänderte Geräte mit je einem `UPDATE`.
Schätzung für STRATO (Datenbank auf einem anderen Rechner, etwa 0,5–1 ms je Abfrage, langsamere
CPU): Vorschau 1–2 s, Übernehmen bis etwa 10 s bei 5000 geänderten Geräten. Das liegt deutlich unter
`max_execution_time` von 30 s. Nach dem Deployment mit einer Teststadt nachmessen, z. B. mit
`-w "%{time_total}"` beim curl-Aufruf, und die Werte hier eintragen. Voraussetzungen auf dem Server:
`post_max_size` mindestens 4M (der Body ist bis 3 MiB groß; darüber verwirft PHP ihn und die API
meldet 400) und `memory_limit` mindestens 128M.

Lokal ausprobieren in PowerShell, angemeldet als Admin wie unter "Lokal starten". Die JSON-Dateien
werden ohne BOM geschrieben, sonst lehnt die API sie ab:

```
curl.exe -b cookies.txt -o geraete.csv http://localhost:8000/firefighting/immendingen/api/v1/devices/export
# geraete.csv bearbeiten, dann:
$b64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes("$PWD\geraete.csv"))
[IO.File]::WriteAllText("$PWD\preview.json", (@{ file = $b64 } | ConvertTo-Json -Compress))
curl.exe -b cookies.txt -o vorschau.json -H "Content-Type: application/json" --data-binary "@preview.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/import/preview
$token = (Get-Content -Raw -Encoding UTF8 vorschau.json | ConvertFrom-Json).token
[IO.File]::WriteAllText("$PWD\apply.json", (@{ file = $b64; token = $token; comment = "Inventur 2026" } | ConvertTo-Json -Compress))
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@apply.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/import
```

Dasselbe in Bash (Linux, macOS, Git Bash; `jq` für die Antwort):

```
printf '{"file":"%s"}' "$(base64 -w0 geraete.csv)" > preview.json
token=$(curl -s -b cookies.txt -H "Content-Type: application/json" --data-binary @preview.json \
  http://localhost:8000/firefighting/immendingen/api/v1/devices/import/preview | jq -r .token)
printf '{"file":"%s","token":"%s","comment":"Inventur 2026"}' "$(base64 -w0 geraete.csv)" "$token" > apply.json
curl -b cookies.txt -H "Content-Type: application/json" --data-binary @apply.json \
  http://localhost:8000/firefighting/immendingen/api/v1/devices/import
```

Ist `token` `null`, enthält die Datei Fehler (siehe `rows[].messages` in der Vorschau); Übernehmen
ergibt dann 409 `import_outdated`.

### Erscheinungsbild (Akzentfarbe, Logo, Web-App-Manifest)

Jede Stadt kann eine Akzentfarbe und ein Logo festlegen (nur Admins der Stadt). Beides erscheint in
`GET api/v1/tenant` (öffentlich, für die Anmeldeseite) und im Web-App-Manifest
`/firefighting/<kürzel>/manifest.webmanifest` (App auf dem Startbildschirm).

```
{"slug":"immendingen","name":"Feuerwehr Immendingen","accent_color":"#b71c1c",
 "accent_color_is_default":true,"logo_url":null,"logo_urls":null}
```

**Akzentfarbe** (`POST api/v1/tenant/appearance {"accent_color": "#1A237E"}`, `null` = Standard
`#b71c1c`): `#` und 6 oder 3 Hex-Ziffern, Groß-/Kleinschreibung egal, gespeichert als `#rrggbb`.
Weiße Schrift auf der Farbe muss einen Kontrast von mindestens 4,5:1 haben (WCAG 2.x), sonst 400
mit dem erreichten Wert (abgerundet), z. B. `"details":{"contrast":4.47,"min_contrast":4.5}`.

**Logo** (`POST api/v1/tenant/logo {"file": "<Base64>"}`): nur PNG, genau 512 × 512 px, höchstens
500 KB, keine animierte PNG und keine Daten nach dem Dateiende. Der Server erzeugt das Bild mit GD
neu (Metadaten und versteckte Inhalte fallen weg, Transparenz bleibt) und speichert es in drei
Größen in der Datenbank: 512 und 192 px (Manifest) sowie 180 px auf weißem Hintergrund
(`apple-touch-icon`). `DELETE api/v1/tenant/logo {}` kehrt zum Standard-Symbol zurück.

**Auslieferung und Zwischenspeichern**: Bilder und Manifest sind die einzigen Antworten ohne
`Cache-Control: no-store`. Die Adressen in `logo_url`, `logo_urls` und im Manifest enthalten die
Versionskennung (`?v=…`); dann darf der Browser die Datei dauerhaft behalten (`public,
max-age=31536000, immutable`). Ein neues Logo hat eine neue Kennung und damit eine neue Adresse.
Ohne oder mit veralteter Kennung und beim Manifest gilt `no-cache` (vor jeder Verwendung per `ETag`
prüfen, unverändert → 304 ohne Inhalt).

Das Standard-Symbol (Platzhalter in `resources/app-icon/`, neu erzeugen mit
`php scripts/generate-default-app-icon.php`) liefert das Backend. Im Manifest sind `start_url` und
`scope` die Startseite der Stadt (`/firefighting/<kürzel>/`), `background_color` ist der helle
Hintergrund der App (`#f3f4f6`). Das Frontend verweist in `index.html` auf das Standard-Symbol
(`api/v1/app-icon/192` und `/180` als `apple-touch-icon`), setzt den Link auf das Manifest nur
unter Städten und ersetzt nach `GET api/v1/tenant` Farbe (`theme-color`), Titel und – wenn es ein
Logo gibt – die Symbole durch die Logo-Adressen.

Lokal ausprobieren in PowerShell, angemeldet als Admin wie unter "Lokal starten" (JSON ohne BOM):

```
[IO.File]::WriteAllText("$PWD\color.json", '{"accent_color":"#1A237E"}')
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@color.json" http://localhost:8000/firefighting/immendingen/api/v1/tenant/appearance
$b64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes("$PWD\logo.png"))
[IO.File]::WriteAllText("$PWD\logo.json", (@{ file = $b64 } | ConvertTo-Json -Compress))
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@logo.json" http://localhost:8000/firefighting/immendingen/api/v1/tenant/logo
curl.exe http://localhost:8000/firefighting/immendingen/api/v1/tenant
curl.exe -i http://localhost:8000/firefighting/immendingen/manifest.webmanifest
curl.exe -b cookies.txt -X DELETE -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/tenant/logo
```

Dasselbe in Bash; auf dem Server nur in der Test-Stadt (`/firefighting/test/`):

```
base=https://www.prodad.de/firefighting/test
curl -b cookies.txt -H "Content-Type: application/json" --data-binary '{"accent_color":"#1A237E"}' "$base/api/v1/tenant/appearance"
printf '{"file":"%s"}' "$(base64 -w0 logo.png)" > logo.json
curl -b cookies.txt -H "Content-Type: application/json" --data-binary @logo.json "$base/api/v1/tenant/logo"
url=$(curl -s "$base/api/v1/tenant" | jq -r .logo_url)
curl -s -o logo-512.png -D - "https://www.prodad.de$url"            # 200, immutable, ETag
etag=$(curl -s -o /dev/null -D - "$base/manifest.webmanifest" | tr -d '\r' | sed -n 's/^[Ee][Tt]ag: //p')
curl -s -o /dev/null -w "%{http_code}\n" -H "If-None-Match: $etag" "$base/manifest.webmanifest"   # 304
curl -b cookies.txt -X DELETE -H "Content-Type: application/json" --data-binary '{}' "$base/api/v1/tenant/logo"
```

### Lokal starten

```
php -S localhost:8000 public/index.php
```

Dann z. B. `http://localhost:8000/firefighting/service/api/v1/health`. Die Anwendung verwendet
`config/config.local.php`; fehlt sie, antwortet der Health-Check mit 503.

Zum Anmelden `localhost` verwenden, nicht `127.0.0.1`: Browser und curl nehmen das
`Secure`-Cookie über `http://` nur für `localhost` an.

Beispiel mit curl und Cookie-Datei (in PowerShell `curl.exe` schreiben, nicht `curl`). Die
Zugangsdaten stehen in einer Datei, damit das Passwort nicht im Befehlsverlauf landet:

```
# login.json enthält: {"email":"admin@example.org","password":"…"}
curl.exe -c cookies.txt -H "Content-Type: application/json" --data-binary "@login.json" http://localhost:8000/firefighting/immendingen/api/v1/auth/login
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/auth/me
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/auth/sessions
curl.exe -b cookies.txt -X DELETE -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/auth/sessions/12
curl.exe -b cookies.txt -c cookies.txt -H "Content-Type: application/json" --data-binary "{}" http://localhost:8000/firefighting/immendingen/api/v1/auth/logout
```

`login.json` und `cookies.txt` (enthält das Sitzungs-Token im Klartext) danach löschen und nie
committen.

### Lokale Entwicklungsdatenbank mit Test-Stadt

Zum Ansehen der App braucht die Anwendung lokal eine eigene Datenbank. Nie dafür
`firefighting_test` verwenden: Die Tests leeren deren Tabellen bei jedem Lauf.

1. Datenbank und Benutzer anlegen (als root in der MariaDB-Konsole, Passwort frei wählen):

   ```sql
   CREATE DATABASE firefighting_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'ff_dev'@'localhost' IDENTIFIED BY 'EIN-PASSWORT';
   GRANT ALL PRIVILEGES ON firefighting_dev.* TO 'ff_dev'@'localhost';
   ```

2. `config/config.example.php` nach `config/config.local.php` kopieren (wird nie committet) und
   anpassen:

   ```php
   return [
       'db' => [
           'host' => '127.0.0.1',
           'name' => 'firefighting_dev',
           'user' => 'ff_dev',
           'pass' => 'EIN-PASSWORT',
       ],
       // Links in Mails zeigen auf den lokalen Server.
       'app' => ['base_url' => 'http://localhost:8000'],
       // Mails als .eml-Dateien in var/mail statt Versand.
       'mail' => ['transport' => 'file'],
       // Nur für den Vite-Entwicklungsserver (npm run dev) nötig.
       'http' => ['allowed_origins' => ['http://localhost:5173']],
   ];
   ```

3. Tabellen anlegen, Test-Stadt mit den Start-Gerätetypen anlegen und ihren ersten Admin einladen:

   ```
   php bin/migrate.php run
   php bin/tenant.php create --slug=test --name="Feuerwehr Test"
   php bin/tenant.php invite-admin --slug=test --email=admin@example.org --print-link
   ```

   `--print-link` zeigt den Einladungslink (`…/einladung-annehmen#token=…`); dieselbe Mail liegt
   auch in `var/mail/`.

4. Frontend bauen und den Server starten:

   ```
   cd frontend
   npm ci
   npm run build
   cd ..
   php -S localhost:8000 public/index.php
   ```

5. Einladung annehmen. Solange das Frontend die Seite `einladung-annehmen` noch nicht hat, per
   curl (Token = Teil hinter `#token=` im Link; Datei danach löschen):

   ```
   # accept.json: {"token":"<Token>","name":"Erika Musterfrau","password":"<mindestens 10 Zeichen>"}
   curl.exe -H "Content-Type: application/json" --data-binary "@accept.json" http://localhost:8000/firefighting/test/api/v1/auth/invitation/accept
   ```

Danach `http://localhost:8000/firefighting/test/` öffnen. Mit dem Admin-Konto lassen sich
Standorte und Geräte über die API anlegen (Beispiele oben unter "Gerätetypen und Standorte" und
"Geräte", Adresse `http://localhost:8000/firefighting/test/…`) oder per CSV-Import. Ganz von vorn:
`DROP DATABASE firefighting_dev;` und ab Schritt 1 wiederholen.

### Neuen Endpunkt anlegen

1. Handler in `src/Api/V1/` anlegen, der `FirefightingEquipment\Http\RequestHandler` implementiert.
   Er liefert eine `JsonResponse` (Downloads: `CsvResponse`, siehe "CSV-Export") und bekommt einen
   `RequestContext`:
   - `$context->tenant`: die aktive Stadt (unter `service`: `null`); jede Abfrage nach deren ID
     filtern.
   - `$context->db->get()`: die PDO-Verbindung; sie wird erst hier aufgebaut.
   - `$context->auth()`: angemeldetes Konto und Sitzung (nur bei Routen, die nicht öffentlich sind).
   - `$context->param('id')`: Wert eines Platzhalters; `$context->stringField('name')`: Textfeld aus
     dem JSON-Body (fehlt es, folgt 400); `optionalStringField('label')` (fehlt oder `null` →
     `null`); `intField('max_uses', 1, 500)` (nur echte JSON-Ganzzahlen im Bereich, sonst 400);
     `$context->request->query()`: Parameter des Query-Strings (nur einfache Werte).
   - `$context->notifier()->send('zweck', $userId, fn () => MailTemplates::…)`: Mail senden
     (Vorlage in `src/Mail/MailTemplates.php`); ein Versandfehler wird nur protokolliert.
   - Bekannte Fehler als `HttpException` werfen (Fabriken in `src/Http/HttpException.php`, neue
     Codes dort ergänzen); alles andere wird automatisch zu 500.
2. Route in `src/Http/Routes.php` mit Zugriffsstufe eintragen, z. B.
   `->add('GET', 'api/v1/devices/{id}', Access::Authenticated, new DeviceHandler())` in `tenant()`
   (Städte) oder `service()` (Super-Admin). Stufen: `Public`, `Authenticated`, `Admin` (nur Städte),
   `SuperAdmin` (nur `service`). Pfad ohne Slash am Anfang und Ende; ein Platzhalter `{name}` passt
   nur auf positive Ganzzahlen.
3. Tests: Unit-Test für die Logik, Integrationstest in `tests/Integration/Http/` (erbt von
   `DomainTestCase`) bzw. `tests/Integration/Auth/` (erbt von `AuthTestCase`, mit Anmeldung) für die
   Antworten mit echter Datenbank.
4. Endpunkt in der Tabelle oben eintragen.

## Frontend

Vue 3 mit Vite, TypeScript, Reka UI und Workbox (PWA) im Ordner `frontend/`; Konzept und Gestaltung
in `docs/frontend/`. Node.js 24 LTS wird nur lokal und in GitHub Actions gebraucht, auf dem Server
liegt nur der fertige Build (`frontend/dist/`, im Branch `deploy`). Ausgeliefert wird er von PHP:
Alle GET-Adressen unter `/firefighting/<bereich>/` außerhalb von `api/` bekommen die App-Seite
oder eine Datei des Builds (Regeln in `CLAUDE.md`, "Frontend ausliefern").

```
cd frontend
npm ci                                            # Abhängigkeiten
npx playwright install chromium firefox webkit    # einmalig: Browser für die Tests
npm test                                          # Lint, Vitest, Typprüfung + Build, Playwright
npm run build                                     # nur Build nach frontend/dist
npm run dev                                       # Entwicklung: http://localhost:5173/firefighting/test/
npm run format                                    # Prettier und ESLint --fix
```

Fürs Ansehen mit echter API: `npm run build`, dann im Hauptordner
`php -S localhost:8000 public/index.php` und `http://localhost:8000/firefighting/test/` öffnen
(Daten siehe "Lokale Entwicklungsdatenbank mit Test-Stadt"). `npm run dev` leitet API-Anfragen an
`localhost:8000` weiter; für ändernde Anfragen dort `http://localhost:5173` in
`http.allowed_origins` eintragen.

Die Browser-Tests (Chromium, Firefox, WebKit, iPhone 13, Pixel 7) laufen gegen den Build, den
Playwright selbst mit `php -S localhost:4173` ausliefern lässt; die API wird simuliert, eine
Datenbank ist nicht nötig. Jeder Konsolenfehler und jede Verletzung der Content-Security-Policy
lässt einen Test scheitern.

## GitHub Actions und Branch deploy

Bei jedem Push auf `main` und bei Pull Requests testet der Workflow "CI" PHP (mit MariaDB 10.11)
und Frontend. Nur nach einem grünen Lauf auf `main` legt der Job `deploy` Code plus Build im Branch
`deploy` ab; auf den Server kommt er von Hand ("Deployment"). Die Versionen der Actions hält
Dependabot aktuell (wöchentlicher Pull Request "CI: …", nach grünem Lauf mergen).

### Branch deploy schützen

Ruleset auf GitHub, damit `deploy` weder gelöscht noch per Force-Push überschrieben werden kann. Den
normalen Push des Workflows erlaubt es weiter:

1. Auf GitHub das Repository öffnen → **Settings**.
2. Links unter "Code, planning, and automation": **Rules** → **Rulesets**.
3. **New ruleset** → **New branch ruleset**.
4. **Ruleset name**: `deploy schützen`.
5. **Enforcement status**: **Active**.
6. **Bypass list**: leer lassen (dann gelten die Regeln auch für Admins).
7. **Target branches** → **Add target** → **Include by pattern** → `deploy` → **Add Inclusion
   pattern**.
8. Unter **Branch rules** nur diese beiden anhaken:
   - **Restrict deletions**
   - **Block force pushes**

   Alles andere aus lassen, insbesondere **Restrict updates** (dann dürfte nur noch die Bypass-Liste
   pushen; den `GITHUB_TOKEN` des Workflows kann man dort nicht eintragen, der Job `deploy` würde
   scheitern) und **Require linear history** (die deploy-Commits haben zwei Eltern).
9. **Create**.

Prüfen: Beim nächsten Push auf `main` muss der Job `deploy` weiter grün werden. Ein
`git push --force origin <x>:deploy` oder `git push origin --delete deploy` wird abgelehnt
(`GH013: Repository rule violations found`).

## Datenbank-Migrationen

```
php bin/migrate.php status                 # ausgeführte und offene Migrationen
php bin/migrate.php run --dry-run          # zeigt nur, was passieren würde
php bin/migrate.php run                    # führt offene Migrationen aus
php bin/migrate.php check                  # nur prüfen: Exit-Code 0 = nichts offen, 3 = offen
php bin/migrate.php status --config=PFAD   # andere Konfiguration (Standard: config/config.local.php)
```

Auf dem Server immer `php85` statt `php` verwenden. Regeln für Migrationen stehen in
`CLAUDE.md`.

## Deployment

Ablauf: `main` → GitHub Actions (alle Tests) → Branch `deploy` → Deploy von Hand.

1. Lokal alle Tests grün (`composer test`, in `frontend/` `npm test`), committen, nach `main`
   pushen.
2. GitHub Actions (Workflow "CI") testet PHP und Frontend. Nur wenn alles grün ist, legt der Job
   `deploy` Code plus Frontend-Build (`frontend/dist`) als neuen Commit im Branch `deploy` ab
   ("Deploy <commit>: <Betreff>"). Prüfen unter "Actions" auf GitHub.
3. Auf dem Server per SSH:

```
cd ~/htdocs/apps/firefighting-equipment && bash scripts/deploy.sh
```

Der Server steht auf dem Branch `deploy`, nie auf `main` (dort fehlt der Frontend-Build, und auf
STRATO gibt es kein Node.js). Der Umstieg ist erledigt (27.10.2026, erster Stand `0912203`); der
lokale Branch `main` bleibt dort vorerst für einen Rücksprung. Die Anleitung unten gilt nur noch
für einen neu eingerichteten Server.

Ablauf von `scripts/deploy.sh`:

1. Abbruch, wenn versionierte Dateien auf dem Server geändert sind oder der Server nicht auf dem
   Branch `deploy` steht.
2. Sicherung des Projektordners (ohne `vendor/`) nach
   `~/htdocs/apps/_backup/ff-deploy-JJJJMMTT-HHMMSSZ-<commit>.tar.gz`; die 5 neuesten (nach
   Änderungsdatum der Datei) bleiben, die gerade erzeugte wird nie gelöscht
   (`scripts/prune-deploy-backups.sh`).
3. `git fetch` und `git merge --ff-only origin/deploy`. Hat der Merge `scripts/deploy.sh` geändert,
   startet sich das Skript sofort mit der neuen Fassung neu und setzt dort fort, ohne Sicherung
   und Merge zu wiederholen. Fehlt danach `frontend/dist/index.html`, bricht es ab. Sonst
   `composer install`.
4. `php85 bin/migrate.php check`. Sind Migrationen offen:
   1. Datenbanksicherung mit `php85 bin/db-dump.php --suffix=vor-migration-<alter Commit>`.
      Schlägt sie fehl, bricht das Skript ab, **ohne** zu migrieren. Gibt es noch keine Tabelle
      `fe_*` (erste Migration), gibt es nichts zu sichern: Das Skript zeigt einen Hinweis und
      migriert ohne Sicherung.
   2. `php85 bin/migrate.php run`. Schlägt die Migration fehl, zeigt das Skript den Pfad der
      Sicherung und die Befehle zum Zurückspielen an. Es spielt **nichts** automatisch zurück.
5. Health-Check `https://www.prodad.de/firefighting/service/api/v1/health` (erwartet HTTP 200;
   prüft auch die Datenbankverbindung, scheitert also z. B. bei fehlender
   `config/config.local.php`).
6. App-Seite `https://www.prodad.de/firefighting/service/` (erwartet HTTP 200,
   `text/html; charset=utf-8` und `<base href="/firefighting/service/" />`; prüft, dass PHP den
   Frontend-Build ausliefert).

Bei einem Fehler zeigt das Skript außerdem die Befehle für den Rücksprung auf den vorherigen
Commit. Wichtig: Schlagen Sicherung oder Migration fehl, ist der neue Code bereits eingespielt,
die Datenbank aber noch nicht (vollständig) migriert.

Alle Zeitstempel in Dateinamen sind in UTC (Kennzeichen `Z`).

### Umstieg des Servers auf den Branch deploy

Erledigt am 27.10.2026. Nur noch nötig, wenn ein Server noch auf `main` steht. Voraussetzung: Der letzte Lauf "CI" auf GitHub
ist grün, und `origin/deploy` enthält das neue `scripts/deploy.sh` (mit `BRANCH="deploy"`).
Die Schritte nacheinander ausführen und die Ausgabe jeweils vergleichen; passt etwas nicht,
abbrechen und den Rücksprung unten verwenden.

1. Ausgangslage prüfen:

   ```
   cd ~/htdocs/apps/firefighting-equipment
   git rev-parse --abbrev-ref HEAD
   git status --short --untracked-files=no
   git log --oneline -1
   ```

   Erwartet: `main`, danach keine Ausgabe von `git status` (nichts lokal geändert), dann der
   bisherige Stand, z. B. `4f47ad2 Offline-Buchungen, …`. Diesen Commit notieren (ALT).

2. Sicherung des Projektordners (wie `deploy.sh`, Zusatz `umstieg`):

   ```
   (umask 077 && tar -czf ../_backup/ff-deploy-$(date -u +%Y%m%d-%H%M%SZ)-$(git rev-parse --short HEAD)-umstieg.tar.gz --exclude=./vendor .)
   ls -l ../_backup/ | grep umstieg
   ```

   Erwartet: keine Ausgabe von `tar`, danach eine Zeile `-rw------- … ff-deploy-…Z-<ALT>-umstieg.tar.gz`.

3. Branch `deploy` holen und prüfen:

   ```
   git fetch origin
   git merge-base --is-ancestor HEAD origin/deploy && echo "Fast-Forward möglich"
   git log --oneline -1 origin/deploy
   git show origin/deploy:scripts/deploy.sh | grep '^BRANCH='
   git ls-tree --name-only origin/deploy frontend/dist/
   ```

   Erwartet: bei `git fetch` u. a. die Zeile `* [new branch]      deploy     -> origin/deploy`;
   dann `Fast-Forward möglich`; `Deploy <commit>: <Betreff>` (der neueste Stand von `main`);
   `BRANCH="deploy"`; `frontend/dist/assets`, `frontend/dist/index.html`, `frontend/dist/sw.js`
   und `frontend/dist/workbox-….js`. Fehlt `Fast-Forward möglich` oder eine dieser Zeilen: hier
   aufhören (es wurde noch nichts geändert).

4. Auf `deploy` umstellen:

   ```
   git checkout -b deploy --track origin/deploy
   git rev-parse --abbrev-ref HEAD
   ls frontend/dist/index.html
   ```

   Erwartet: `Switched to a new branch 'deploy'` und `branch 'deploy' set up to track
   'origin/deploy'.` (Wortlaut je nach Git-Version), dann `deploy` und `frontend/dist/index.html`.
   Ab hier ist der neue Code eingespielt, aber Composer und Migrationen laufen erst im nächsten
   Schritt.

5. Deployment wie künftig immer:

   ```
   bash scripts/deploy.sh
   ```

   Erwartet (gekürzt): `Aktueller Stand: <deploy-Commit>`, `Sicherung: …`, `Already up to date.`,
   die Ausgabe von Composer, `Health-Check OK (HTTP 200).`, `App-Seite OK (HTTP 200, Basispfad
   gesetzt).`, `Deployment erfolgreich.` und `(keine neuen Commits)`. Sind Migrationen offen,
   erscheinen zusätzlich `Datenbanksicherung vor der Migration ...` und die Ausgabe der Migration.

6. Im Browser prüfen: `https://www.prodad.de/firefighting/test/` zeigt die App (Kopfleiste mit dem
   Namen der Test-Stadt), `https://www.prodad.de/firefighting/service/` die Kopfleiste
   „Super-Admin“, `https://www.prodad.de/firefighting/service/api/v1/health` weiterhin
   `{"status":"ok","database":"ok"}`.

7. Aufräumen (optional, empfohlen): den lokalen Branch `main` auf dem Server löschen, damit nicht
   versehentlich darauf gewechselt wird:

   ```
   git branch -d main
   ```

   Erwartet: `Deleted branch main (was <ALT>).` (`-d` löscht nur, weil `main` in `deploy`
   enthalten ist.)

**Rücksprung** (nur nötig, wenn ab Schritt 4 etwas nicht passt):

```
cd ~/htdocs/apps/firefighting-equipment
git checkout main
git branch -D deploy
php85 /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-cache
curl -s https://www.prodad.de/firefighting/service/api/v1/health
```

Erwartet: `Switched to branch 'main'`, `Deleted branch deploy (was …).`, die Ausgabe von
Composer und `{"status":"ok","database":"ok"}`. `git checkout main` entfernt dabei auch
`frontend/dist` (dort nicht versioniert); `config/config.local.php` und andere nicht versionierte
Dateien bleiben unberührt. Hat Schritt 5 Migrationen ausgeführt, passt die Datenbank nicht mehr
zum alten Code: dann die Datenbanksicherung aus der Ausgabe von Schritt 5 zurückspielen
("Sicherung zurückspielen"). War Schritt 7 schon erledigt, statt `git checkout main`:
`git checkout -b main <ALT>`.

## Datenbanksicherung

```
php bin/db-dump.php                                   # Sicherung nach ~/htdocs/apps/_backup/
php bin/db-dump.php --suffix=vor-update               # mit Zusatz im Dateinamen
php bin/db-dump.php --target-dir=PFAD                 # anderer Zielordner (muss .htaccess enthalten)
php bin/db-dump.php --mysqldump=/usr/bin/mysqldump    # anderes mysqldump-Programm
php bin/db-dump.php --config=PFAD                     # andere Konfiguration
```

- Gesichert werden alle Tabellen `fe_*` der Datenbank aus der Konfiguration.
- Speicherort: `~/htdocs/apps/_backup/ff-db-JJJJMMTT-HHMMSSZ[-zusatz].sql.gz` (UTC, gzip,
  Rechte 600). Der Ordner muss existieren und eine `.htaccess` enthalten.
- Es bleiben die 10 neuesten `ff-db-*.sql.gz` (nach Änderungsdatum der Datei, nicht nach Namen);
  ältere werden nach jeder erfolgreichen Sicherung gelöscht, die gerade erzeugte nie. Andere
  Dateien im Ordner werden nicht angefasst.
- Gibt es keine Tabelle `fe_*` (z. B. vor der ersten Migration), wird nichts gesichert, keine
  Datei angelegt und mysqldump nicht aufgerufen (Exit-Code 4).
- Die Zugangsdaten stehen nie in der Befehlszeile, sondern nur kurz in einer Datei mit Rechten
  600, die danach gelöscht wird.
- Ausgabe bei Erfolg: nur der Pfad der Sicherung. Exit-Codes: 0 OK, 1 Fehler, 2 falscher Aufruf,
  4 nichts zu sichern (keine Tabellen `fe_*`).

Auf dem Server immer `php85` statt `php` verwenden.

### Sicherung zurückspielen

Nur von Hand, per SSH. Das Passwort nie in die Befehlszeile schreiben: Es wäre in der
Prozessliste und in der Shell-Historie sichtbar.

1. Code auf den Stand vor dem Deployment zurücksetzen (die Befehle zeigt `deploy.sh` an):
   ```
   cd ~/htdocs/apps/firefighting-equipment
   git reset --hard <alter Commit>
   php85 /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-cache
   ```
2. Zugangsdatei nur für den eigenen Benutzer anlegen:
   ```
   (umask 077 && nano ~/htdocs/apps/_backup/.restore.cnf)
   ```
   Inhalt (Werte aus `config/config.local.php`; in den Werten `\` als `\\` und `"` als `\"`
   schreiben):
   ```
   [client]
   user="BENUTZER"
   password="PASSWORT"
   host="HOST"
   ```
3. Sicherung einspielen (`--defaults-extra-file` muss die erste Option sein):
   ```
   gunzip -c ~/htdocs/apps/_backup/ff-db-JJJJMMTT-HHMMSSZ-vor-migration-COMMIT.sql.gz \
     | mysql --defaults-extra-file=$HOME/htdocs/apps/_backup/.restore.cnf DATENBANKNAME
   ```
4. Zugangsdatei sofort löschen:
   ```
   rm -f ~/htdocs/apps/_backup/.restore.cnf
   ```
5. Prüfen: `php85 bin/migrate.php status`.

Hinweise:

- Die Sicherung enthält nur Tabellen `fe_*`. Beim Einspielen werden diese Tabellen gelöscht
  und neu angelegt (`DROP TABLE IF EXISTS`). Tabellen, die eine fehlgeschlagene Migration
  **neu** angelegt hat, stehen nicht in der Sicherung und bleiben bestehen. Sie müssen von Hand
  gelöscht werden, sonst schlägt die Migration beim nächsten Versuch erneut fehl.
- Die Protokolltabelle `fe_schema_migrations` ist Teil der Sicherung und hat danach wieder den
  Stand vor der Migration.

## Städte (Mandanten) verwalten

```
php bin/tenant.php list                                                  # alle Städte
php bin/tenant.php create --slug=immendingen --name="Feuerwehr Immendingen"
php bin/tenant.php rename --slug=immendingen --name="Freiwillige Feuerwehr Immendingen"
php bin/tenant.php deactivate --slug=immendingen                         # sperren
php bin/tenant.php activate --slug=immendingen                           # entsperren
php bin/tenant.php invite-admin --slug=immendingen --email=kommandant@example.org
php bin/tenant.php invite-admin --slug=immendingen --email=kommandant@example.org --print-link
php bin/tenant.php list --config=PFAD                                    # andere Konfiguration
```

Das Kürzel erscheint in der URL (`/firefighting/<kürzel>/`) und ist nach dem Anlegen
unveränderlich; `rename` ändert nur den Namen. Regeln siehe "Stadt-Kürzel" (Kleinbuchstaben
`a-z`, Ziffern, Bindestrich, höchstens 50 Zeichen, `service` reserviert). Städte werden nicht gelöscht, sondern gesperrt; ihre Daten bleiben erhalten. Sperren
beendet alle Sitzungen der Konten dieser Stadt. Auf dem Server `php85` statt `php` verwenden.

`invite-admin` ist der Notfallweg ohne API (der übliche Weg ist die Städteverwaltung unter
`service`): Für eine neue Adresse entsteht ein Admin-Konto im Status `invited`, für eine offene
Einladung wird der Link erneuert (der alte wird ungültig); andere vorhandene Konten werden
abgelehnt (Exit-Code 1), ebenso eine gesperrte Stadt. Die Mail geht über den konfigurierten
Transport; schlägt der Versand fehl, erscheint ein Hinweis (Exit-Code 0). `--print-link` gibt den
Link zusätzlich auf stdout aus, z. B. wenn keine Mail ankommt. Der Link ist ein Zugang zum Konto:
nur an die eingeladene Person weitergeben, nicht in Tickets oder Chats kopieren.

### Gerätedaten einer Stadt zurücksetzen (nur Testdaten)

```
php bin/tenant.php purge-device-data --slug=immendingen                                         # Probelauf
php bin/tenant.php purge-device-data --slug=immendingen --execute --confirm=immendingen          # löschen
```

Löscht **alle** Geräte, Ereignisse (Historie), Standorte, Quittungen der Buchungen und Hinweise
(samt Verlauf) einer Stadt, z. B. Testdaten vor der Erstbefüllung. Bewusste Ausnahme von „Geräte und Historie werden nie gelöscht“: nur für Städte
ohne echte Daten verwenden. Gerätetypen, Benutzer, Einladungscodes, Sitzungen und die Stadt selbst
bleiben unverändert; andere Städte sind nie betroffen.

- Ohne `--execute`: Probelauf, zeigt die Anzahl Geräte, Ereignisse, Standorte, Buchungen und
  Hinweise; ändert nichts.
- Mit `--execute` muss `--confirm` genau dem Kürzel entsprechen (sonst Abbruch, Exit-Code 2).
- Vorher wird die Datenbank gesichert wie mit `bin/db-dump.php` (alle Tabellen `fe_*`, nach
  `~/htdocs/apps/_backup/`, Zusatz `vor-purge-<kürzel>`). Schlägt die Sicherung fehl, wird nichts
  gelöscht (Exit-Code 1). Sind keine Gerätedaten vorhanden, gibt es weder Sicherung noch Löschung
  (Exit-Code 0).
- Gelöscht wird in einer Transaktion (alles oder nichts). Ausgegeben werden die gelöschten
  Anzahlen und der Pfad der Sicherung. Zurück geht es nur über die Sicherung (siehe „Sicherung
  zurückspielen“; das spielt die ganze Datenbank zurück, nicht nur diese Stadt).
- `service` ist kein gültiges Ziel; gesperrte Städte sind erlaubt.

Auf dem Server:

```
cd ~/htdocs/apps/firefighting-equipment && php85 bin/tenant.php purge-device-data --slug=immendingen
```

## Super-Admins und Benutzer verwalten

```
php bin/user.php superadmin:create --email=chef@example.org --name="Erika Musterfrau"
php bin/user.php superadmin:list                                  # alle Super-Admins
php bin/user.php superadmin:password --email=chef@example.org     # neues Passwort setzen
php bin/user.php superadmin:lock --email=chef@example.org         # sperren
php bin/user.php superadmin:unlock --email=chef@example.org       # entsperren
php bin/user.php superadmin:protect --email=chef@example.org      # schützen
php bin/user.php superadmin:unprotect --email=chef@example.org --confirm=chef@example.org  # Schutz entfernen
php bin/user.php list --tenant=immendingen                        # Konten einer Stadt
php bin/user.php superadmin:list --config=PFAD                    # andere Konfiguration
```

Auf dem Server:

```
cd ~/htdocs/apps/firefighting-equipment && php85 bin/user.php superadmin:create --email=… --name="…"
```

- Das Passwort wird **verdeckt** abgefragt und muss wiederholt werden; es wird nie als Argument
  übergeben. Ein neuer Super-Admin ist sofort aktiv, seine E-Mail-Adresse gilt als bestätigt.
- Die verdeckte Eingabe funktioniert nur in einem Linux-Terminal (z. B. per SSH auf dem Server).
  Unter Windows bricht der Befehl mit einem Hinweis ab; Super-Admins daher auf dem Server anlegen.
- `--password-stdin` liest das Passwort aus der Standardeingabe. Das ist **nur für Tests und
  Skripte** gedacht. Nicht von Hand per `echo "…" | php bin/user.php …` verwenden: Das Passwort
  landet sonst in der Befehlshistorie der Shell.
- Passwortregeln: 10 bis 128 Zeichen, keine Pflicht zu Sonderzeichen. Abgelehnt werden häufige
  Passwörter aus `resources/password-blocklist.txt`, auch mit angehängten Ziffern oder Zeichen
  (z. B. "Feuerwehr2025!"), sowie Passwörter mit Teilen der E-Mail-Adresse, des Namens, des
  Ortsnamens oder des Kürzels der Stadt. Allgemeine Wörter wie "Feuerwehr" im Stadtnamen
  zählen dabei nicht als persönliche Daten.
- Sperren und ein neues Passwort beenden alle Sitzungen des Kontos. Der letzte aktive, nicht
  gesperrte Super-Admin kann nicht gesperrt werden; zuerst einen weiteren anlegen. Super-Admins
  werden nie gelöscht (die Datenbank verhindert es), nur gesperrt.
- **Geschütztes Konto:** `superadmin:protect` schützt einen aktiven, nicht gesperrten Super-Admin.
  Ein geschütztes Konto kann weder gesperrt noch gelöscht noch in der Rolle oder im Namen geändert
  werden, auch nicht über die API; erlaubt bleiben das eigene Passwort (ändern, "Passwort
  vergessen", `superadmin:password`) und die eigene Benachrichtigung. Die Datenbank erzwingt:
  geschützt nur bei Rolle `superadmin`, Status `active` und nicht gesperrt. Gesetzt und entfernt
  wird der Schutz nur hier auf dem Server; `superadmin:unprotect` verlangt zur Bestätigung
  `--confirm=` mit genau derselben Adresse wie `--email=` (sonst Exit-Code 2). `superadmin:list`
  zeigt die Spalte "Geschützt".
- Nach `superadmin:password` bekommt das Konto die Mail "Ihr Passwort wurde geändert". Klappt der
  Versand nicht, erscheint ein Hinweis; das Passwort ist trotzdem geändert.
- `list` zeigt alle Konten einer Stadt (auch gelöschte als "Gelöschter Benutzer #ID"), nie
  Passwort-Hashes.

## E-Mail-Versand

Die Anwendung verschickt nur Klartext-Mails (UTF-8) von
`"Feuerwehr Geräteverwaltung" <feuerwehr-app@prodad.de>`, mit dem Kennzeichen
`Auto-Submitted: auto-generated` (keine Abwesenheitsnotizen). Mails gibt es bisher zu
"Passwort vergessen" und "Ihr Passwort wurde geändert". Kann eine Mail nicht verschickt werden,
steht `mail_failed` im Fehlerprotokoll (ohne Empfänger und ohne Link); die Anfrage selbst läuft
normal weiter.

Konfiguration in `config/config.local.php` (siehe `config/config.example.php`). Ohne Gruppe `mail`
wird über `mail()` (sendmail) versendet; ohne Gruppe `app` beginnen Links mit
`https://www.prodad.de` (auf dem Server weglassen).

```php
'app'  => ['base_url' => 'http://localhost:8000'],                  // Anfang der Links in Mails
'mail' => ['transport' => 'file', 'file_dir' => 'var/mail'],        // lokal: Mails als Dateien
```

Transporte (`mail.transport`):

| Wert       | Versand                                                                     |
|------------|-----------------------------------------------------------------------------|
| `sendmail` | Standard: `mail()` direkt vom Webserver (SPF ja, aber ohne DKIM)              |
| `smtp`     | über das Postfach `feuerwehr-app@prodad.de` bei STRATO, mit DKIM-Signatur     |
| `file`     | lokal: jede Mail als `.eml`-Datei, nichts wird verschickt                    |

### Versand über SMTP (STRATO-Postfach, mit DKIM)

Mails direkt vom Webserver (`sendmail`) tragen keine DKIM-Signatur und landen z. B. bei Outlook.com
im Junk-Ordner. Über das STRATO-Postfach per SMTP signiert STRATO automatisch mit DKIM
(`d=prodad.de`); getestet: `dkim=pass`, `dmarc=pass`, Posteingang.

```php
'mail' => [
    'transport' => 'smtp',
    'smtp' => [
        'username' => 'feuerwehr-app@prodad.de',   // Pflicht, genau die Absenderadresse
        'password' => 'PASSWORT-DES-POSTFACHS',    // Pflicht
        // Optional, Standardwerte:
        // 'host'       => 'smtp.strato.de',
        // 'port'       => 465,
        // 'encryption' => 'ssl',                  // implizites TLS; oder 'starttls' (dann Port 587)
        // 'timeout'    => 10,                     // Sekunden für den ganzen Versand (1–60)
    ],
],
```

- Envelope-Absender (`MAIL FROM`) und `From` sind immer `feuerwehr-app@prodad.de`; ein anderer
  Benutzername wird abgelehnt.
- Das Zertifikat des Servers wird immer geprüft (Aussteller und Hostname, mindestens TLS 1.2); das
  lässt sich nicht abschalten. Angemeldet wird nur über die verschlüsselte Verbindung (AUTH PLAIN,
  sonst LOGIN).
- Das Passwort erscheint nie in Meldungen, Protokollen oder Ausnahmen. Fehlermeldungen nennen
  Schritt, Server und Antwort des Servers; E-Mail-Adressen darin werden durch `<Adresse>` ersetzt.
- Die Gruppe `mail.smtp` wird auch bei einem anderen Transport geprüft, aber nur bei `smtp`
  verwendet. Lokal lässt sich so zwischen `file` und `smtp` umschalten.
- Lokal unter Windows: Meldet `mail-test.php` `certificate verify failed`, fehlt PHP eine Liste
  vertrauenswürdiger Stellen. Dann in der `php.ini` `openssl.cafile` auf eine aktuelle
  `cacert.pem` setzen, nie die Prüfung abschalten.

#### Auf dem Server einrichten

`config/config.local.php` liegt nur auf dem Server und nie im Repository. Das Passwort des Postfachs
nur mit einem Editor eintragen, nicht per `echo` oder `sed` (sonst steht es in der Shell-History):

```
cd ~/htdocs/apps/firefighting-equipment
umask 077
cp -p config/config.local.php ~/htdocs/apps/_backup/config.local.php.vor-smtp-$(date -u +%Y%m%d-%H%M%SZ)
nano config/config.local.php            # Gruppe 'mail' wie oben ergänzen (oder vi)
php85 -l config/config.local.php        # Syntax prüfen
php85 bin/mail-test.php --to=ich@example.org
```

- Steht im Passwort ein `'` oder `\`, im PHP-String als `\'` bzw. `\\` schreiben.
- Die Datei sollte nur für das eigene Konto lesbar sein: `ls -l config/config.local.php` zeigt
  `-rw-------`, sonst `chmod 600 config/config.local.php`. Danach prüfen, dass die Anwendung sie
  noch lesen kann: `https://www.prodad.de/firefighting/service/api/v1/health` liefert
  `{"status":"ok","database":"ok"}`.
- Kommt die Testmail an (Kopfzeilen: `dkim=pass`, `dmarc=pass`), die Kopie in
  `~/htdocs/apps/_backup/` löschen; sie enthält die Zugangsdaten der Datenbank. Das Deploy-Skript
  räumt nur `ff-deploy-*` und `ff-db-*` auf, nicht diese Datei.
- Zurück zu sendmail: in der Gruppe `mail` die Zeile `'transport' => 'smtp'` entfernen (die Gruppe
  `smtp` darf stehen bleiben) und `php85 bin/mail-test.php` erneut aufrufen.
- Änderungen an `config.local.php` brauchen kein Deployment; sie gelten ab der nächsten Anfrage.

### Mails lokal ansehen

Mit `'transport' => 'file'` wird nichts verschickt: Jede Mail landet als Datei
`var/mail/JJJJMMTT-HHMMSSZ-xxxxxxxx.eml` (Zeit in UTC). Der Ordner steht in `.gitignore`; die Dateien
enthalten echte Links mit Tokens, also nicht weitergeben und gelegentlich löschen.

- Ansehen: Doppelklick öffnet die Datei in Outlook bzw. Thunderbird (Datei → Öffnen →
  Gespeicherte Nachricht) mit allen Kopfzeilen.
- Im Texteditor ist der Text quoted-printable kodiert (`=C3=A4` = ä, `=` am Zeilenende =
  Zeile geht weiter). Den Link zum Zurücksetzen findet man trotzdem: `#token=` folgt immer am Stück.
- Neueste Mail in PowerShell: `Get-ChildItem var\mail\*.eml | Sort-Object Name | Select-Object -Last 1`.

### Zustellung prüfen (Testmail)

```
php bin/mail-test.php --to=ich@example.org                   # lokal (Transport laut Konfiguration)
cd ~/htdocs/apps/firefighting-equipment && php85 bin/mail-test.php --to=ich@example.org   # Server
```

Das Skript zeigt Transport, bei SMTP auch den Server (z. B. `smtp.strato.de:465 (ssl)`), Message-ID
und Dauer. Bei einem Fehler zeigt es die Meldung mit Schritt und Antwort des Servers, z. B.
`SMTP-Fehler bei AUTH: 535 5.7.8 Authentication credentials invalid` (falsches Passwort), dazu
Transport, Server und Dauer, nie die Zugangsdaten.

"übergeben" heißt nur, dass der Server (sendmail bzw. der SMTP-Server) die Mail angenommen hat. Ob
sie ankommt und nicht als Spam gilt, zeigt das Postfach: dort die Kopfzeilen bzw. den Quelltext
anzeigen und prüfen, dass SPF, DKIM und DMARC für `prodad.de` bestanden sind
(`Authentication-Results: … spf=pass … dkim=pass … dmarc=pass`) und `Return-Path` auf
`feuerwehr-app@prodad.de` steht.

Die Dauer hilft beim Einstellen der Mindestdauer von "Passwort vergessen"
(`PasswordResetRequestHandler::MIN_DURATION_SECONDS`, derzeit 1,5 s): Sie muss deutlich über der
üblichen Versanddauer liegen, sonst verrät die Antwortzeit, ob ein Konto existiert (im Protokoll
steht dann `password_reset_slow`). SMTP mit TLS und Anmeldung dauert länger als sendmail; nach dem
Umstellen auf `smtp` daher auf dem Server mehrmals messen und den Wert anpassen.

## Tests

```
composer test              # alle Tests
composer test:unit         # nur Unit-Tests (ohne Datenbank)
composer test:integration  # nur Integrationstests (Testdatenbank)
```

### Testdatenbank einrichten

1. In MariaDB eine eigene Datenbank anlegen, deren Name auf `_test` endet:
   ```sql
   CREATE DATABASE firefighting_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. `config/config.example.php` nach `config/config.test.php` kopieren und die Zugangsdaten
   eintragen. Die Datei wird nie committet.

Fehlt `config/config.test.php`, werden die Integrationstests übersprungen. Endet der
Datenbankname nicht auf `_test`, bricht der Testlauf sofort ab.

Fachliche Integrationstests führen die echten Migrationen aus `migrations/` automatisch in
der Testdatenbank aus und leeren vor jedem Test die fachlichen Tabellen (in einer Transaktion).

### Empfohlene Einstellung der lokalen MariaDB

Standardmäßig wartet MariaDB bei jedem Commit, bis das Log auf der Platte ist
(`innodb_flush_log_at_trx_commit = 1`). Unter Windows kostet das rund 9 ms je Commit; die
Integrationstests committen tausende Male. Für die lokale Entwicklungsdatenbank daher in der
`my.ini` (Abschnitt `[mysqld]`) eintragen und MariaDB neu starten:

```ini
innodb_flush_log_at_trx_commit = 2
```

Mit `2` schreibt MariaDB das Log bei jedem Commit nur in den Puffer des Betriebssystems und
spült es etwa einmal pro Sekunde auf die Platte. Bei einem Absturz von Windows (nicht nur von
MariaDB) kann so die letzte Sekunde verloren gehen; für Test- und Entwicklungsdaten ist das
unerheblich. Die Einstellung gilt für den ganzen lokalen Server.

**Nur lokal, nie auf dem Server:** Auf STRATO bleibt die Einstellung des Hosters unverändert;
dort zählt, dass jede bestätigte Änderung (z. B. eine Umbuchung) dauerhaft gespeichert ist. Die
Anwendung setzt die Einstellung nirgends und darf sich nicht auf sie verlassen.

### Testdatenbank zurücksetzen

Wurde eine Migration geändert, nachdem sie in der Testdatenbank schon lief, brechen die
Tests mit einem Prüfsummenfehler ab. Dann die Testdatenbank neu anlegen:

```sql
DROP DATABASE firefighting_test;
CREATE DATABASE firefighting_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
