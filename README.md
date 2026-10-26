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
| GET     | `api/v1/tenant`                       | öffentlich | `{"slug":"immendingen","name":"Feuerwehr …"}`  |
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
| POST    | `api/v1/tenants`                                       | 201 `{"tenant":…,"user":…,"mail_sent":…}` |
| GET     | `api/v1/tenants/{id}`                                  | `{"tenant":…}`                            |
| POST    | `api/v1/tenants/{id}/rename`                           | `{"tenant":…}`                            |
| POST    | `api/v1/tenants/{id}/lock`, `…/unlock`                 | `{"tenant":…}`                            |
| POST    | `api/v1/tenants/{id}/admins`                           | 201 `{"user":…,"mail_sent":…}`            |
| POST    | `api/v1/tenants/{id}/users/{user}/resend-invitation`   | `{"user":…,"mail_sent":…}`                |
| GET     | `api/v1/tenants/{id}/users[?status=…]`                 | `{"users":[…]}` (nur lesend)              |

`api/v1/tenant`, Registrierung, E-Mail-Bestätigung, Einladung annehmen, Einladungscodes und
Benutzerverwaltung gibt es nur unter einer Stadt (unter `service`: 404), die Städteverwaltung nur
unter `service`, alle anderen Routen unter `service` und unter jeder Stadt. Super-Admins melden sich nur unter `service` an, Konten einer Stadt
nur unter ihrer Stadt; ein Cookie gilt nur dort.

Unter einer Stadt antwortet die API nur, wenn die Stadt existiert und aktiv ist. Der Health-Check
prüft die Datenbankverbindung; bei einer Störung antwortet er mit 503 und
`{"status":"error","database":"error"}`. Das Deploy-Skript ruft ihn unter `service` auf.

### Fehler

Alle Fehler haben das Format `{"error":"code","message":"deutscher Text"}`. Manche Fehler enthalten
zusätzlich ein Objekt `details` mit maschinenlesbaren Angaben, z. B. beim Sperren eines Standorts, der
nicht leer ist: `{"error":"rule_violation","message":"…","details":{"devices":2,"sublocations":1}}`.

| HTTP | `error`                    | Bedeutung                                                  |
|------|----------------------------|------------------------------------------------------------|
| 400  | `invalid_request`          | Body kein JSON-Objekt, zu groß (> 16 KiB), Feld fehlt      |
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
| 409  | `version_conflict`         | Gerät inzwischen geändert; aktueller Stand in `details.device` |
| 415  | `unsupported_media_type`   | ändernde Anfrage ohne `Content-Type: application/json`     |
| 422  | `weak_password`            | neues Passwort verletzt die Passwortregeln                 |
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
| POST    | `api/v1/devices/{id}/state`           | angemeldet | `{"device":…}`                              |
| POST    | `api/v1/devices/{id}/retire`          | Admin      | `{"device":…}`                              |
| POST    | `api/v1/devices/{id}/reinstate`       | Admin      | `{"device":…}`                              |
| GET     | `api/v1/devices/{id}/events`          | angemeldet | `{"events":[…],"total":…,"limit":…,"offset":…}` |

- Liste: je Gerät `id`, `identifier`, `device_type` (`id`, `name`), `location` (`id`, `name`,
  `path` wie „HLF 20 / G1“), `status` mit `status_label` (einsatzbereit, in Wartung, defekt,
  ausgesondert), `next_inspection_due`, `version`. Die Einzelansicht zeigt zusätzlich
  `serial_number`, `manufacturer`, `model`, `procured_on`, `note`, `created_at`, `updated_at`.
- Parameter der Liste (alle optional):
  - `device_type_id`, `location_id`; `include_sublocations=1` (nur mit `location_id`) schließt die
    Geräte der Unterstandorte ein. Unbekannte oder fremde IDs ergeben eine leere Liste.
  - `status=ready,defective` (kommagetrennt). Ausgesonderte Geräte (`retired`) erscheinen nur, wenn
    `retired` angegeben ist oder (ohne `status`) mit `include_retired=1`.
  - `q`: Teil von Kennung, Seriennummer, Hersteller oder Modell, ohne Groß-/Kleinschreibung
    (höchstens 100 Zeichen; `%` und `_` sind keine Platzhalter).
  - `sort=identifier` (Standard, natürlich: „M2“ vor „M10“) oder `sort=next_inspection_due`
    (früheste zuerst, Geräte ohne Datum am Ende).
  - `limit` 1–200 (Standard 50), `offset` 0–1000000 (Standard 0); `total` zählt alle Treffer.
- Kennung: höchstens 30 Zeichen aus Buchstaben, Ziffern, Leerzeichen, `-`, `/`, `.`; Leerraum am
  Rand wird entfernt, mehrere Leerzeichen werden zu einem. Eindeutig je Stadt unter nicht
  ausgesonderten Geräten, ohne Groß-/Kleinschreibung („MA-01“ = „ma-01“), aber „ß“ ≠ „ss“ und
  „é“ ≠ „e“. Nach dem Aussondern ist die Kennung wieder frei.
- Datumsfelder (`procured_on`, `next_inspection_due`) im Format `JJJJ-MM-TT` zwischen 1950-01-01
  und 2099-12-31. Leere Texte werden zu `null`. Nur die Notiz darf Zeilenumbrüche enthalten.
- Anlegen: Pflicht `identifier`, `device_type_id` (aktiver Typ), `location_id` (aktiver Standort);
  optional `status` (`ready` Standard, `maintenance`, `defective`), `serial_number`, `manufacturer`,
  `model` (je 100 Zeichen), `procured_on`, `next_inspection_due`, `note` (1000 Zeichen) und
  `comment` (500 Zeichen, einzeilig) für die Historie.
- Ändern mit erwarteter `version` (aus der letzten Antwort):
  - `…/fields`: nur die mitgesendeten Stammdaten (`identifier`, `device_type_id`, `serial_number`,
    `manufacturer`, `model`, `procured_on`, `note`; `null` leert ein optionales Feld) und optional
    `comment`. Je geändertem Feld ein Eintrag in der Historie mit demselben Kommentar.
  - `…/inspection-due`: `next_inspection_due` (Datum oder `null` zum Entfernen), optional `comment`.
  - `…/state` (auch `user`): `location_id` (neuer Standort) und/oder `status` (`ready`,
    `maintenance`, `defective`), optional `comment`. Umbuchung und Statusänderung ergeben EINEN
    Eintrag in der Historie. Der neue Standort muss aktiv sein.
  - `…/retire` (Admin): nur `version` und optional `comment`. Das Gerät ist danach ausgesondert,
    erscheint nicht mehr in normalen Listen, und seine Kennung ist frei.
  - `…/reinstate` (Admin): `status` (Pflicht, nicht `retired`), optional `location_id` (sonst der
    bisherige Standort), `identifier` (neue Kennung, falls die alte inzwischen vergeben ist),
    `device_type_id` (falls der bisherige Typ deaktiviert ist) und `comment`. Standort und Typ
    müssen aktiv sein.
  - Jede Änderung erhöht `version` um 1. Ändert sich nichts (gleiche Werte), bleibt alles wie es
    ist, auch die Version. Bei `…/state` und `…/reinstate` werden gleiche Teile (z. B. der aktuelle
    Standort) einfach übergangen.
  - Ist die Version veraltet: 409 `version_conflict`, `details.device` enthält den aktuellen Stand.
  - Ausgesonderte Geräte lassen sich nur wieder aufnehmen (alles andere 409 `rule_violation`).
- Historie (`…/events`, auch für ausgesonderte Geräte): neueste zuerst, `limit`/`offset` wie bei der
  Liste. Je Eintrag `type` mit `type_label` (z. B. „Umgebucht“, „Status geändert“, „Ausgesondert“,
  „Wieder aufgenommen“, „Prüfdatum geändert“, „Stammdaten geändert“), `created_at` (UTC), `user`
  (`id`, `name` = Anzeigename bzw. „Gelöschter Benutzer #ID“), `comment` sowie je nach Art
  `location` (`from`/`to` mit `id`, `name`, `path`), `status` (`from`/`to` mit deutscher
  Bezeichnung), `inspection_due` (`from`/`to`) und `field` (`name`, `label`, `old`, `new`); was die
  Art nicht kennt, ist `null`. Standorte erscheinen mit ihrem heutigen Namen (nach dem Umbenennen
  also mit dem neuen).

| Fall                                                                    | Antwort                |
|-------------------------------------------------------------------------|------------------------|
| Feld fehlt, falscher Typ, unbekanntes Feld, Wert verletzt die Regeln    | 400 `invalid_request`  |
| `device_type_id`/`location_id` im Body unbekannt oder aus anderer Stadt | 400 `invalid_request`  |
| `…/fields` ohne Stammdaten, `status` oder `location_id` mitgesendet     | 400 `invalid_request`  |
| `…/state` ohne `location_id` und `status`; `status` = `retired`         | 400 `invalid_request`  |
| Parameter der Liste ungültig (z. B. `limit=500`, `status=kaputt`)       | 400 `invalid_request`  |
| Kennung schon vergeben (auch beim Wiederaufnehmen)                      | 409 `identifier_taken` |
| Version veraltet                                                        | 409 `version_conflict` |
| Standort gesperrt, Gerätetyp deaktiviert, Gerät ausgesondert            | 409 `rule_violation`   |
| Aussondern eines ausgesonderten, Wiederaufnahme eines nicht ausgesonderten Geräts | 409 `rule_violation` |

Lokal ausprobieren (Admin angemeldet wie unter "Lokal starten"):

```
curl.exe -b cookies.txt http://localhost:8000/firefighting/immendingen/api/v1/devices
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/devices?location_id=1&include_sublocations=1&status=ready,maintenance&q=ma&sort=next_inspection_due&limit=20&offset=0"
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/devices?include_retired=1"
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
curl.exe -b cookies.txt -H "Content-Type: application/json" --data-binary "@reinstate.json" http://localhost:8000/firefighting/immendingen/api/v1/devices/1/reinstate
curl.exe -b cookies.txt "http://localhost:8000/firefighting/immendingen/api/v1/devices/1/events?limit=20&offset=0"
```

Hinweis zum letzten Aufruf: Beim zweiten Senden ist das Datum unverändert, die Version wird aber
trotzdem zuerst geprüft, daher der Konflikt.

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
  `without_date`.

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

### Neuen Endpunkt anlegen

1. Handler in `src/Api/V1/` anlegen, der `FirefightingEquipment\Http\RequestHandler` implementiert.
   Er bekommt einen `RequestContext`:
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

Auf dem Server per SSH:

```
cd ~/htdocs/apps/firefighting-equipment && bash scripts/deploy.sh
```

Ablauf:

1. Abbruch, wenn versionierte Dateien auf dem Server geändert sind.
2. Sicherung des Projektordners (ohne `vendor/`) nach
   `~/htdocs/apps/_backup/ff-deploy-JJJJMMTT-HHMMSSZ-<commit>.tar.gz`; die 5 neuesten (nach
   Änderungsdatum der Datei) bleiben, die gerade erzeugte wird nie gelöscht
   (`scripts/prune-deploy-backups.sh`).
3. `git fetch` und `git merge --ff-only origin/main`. Hat der Merge `scripts/deploy.sh` geändert,
   startet sich das Skript sofort mit der neuen Fassung neu und setzt dort fort, ohne Sicherung
   und Merge zu wiederholen. Danach `composer install`.
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

Bei einem Fehler zeigt das Skript außerdem die Befehle für den Rücksprung auf den vorherigen
Commit. Wichtig: Schlagen Sicherung oder Migration fehl, ist der neue Code bereits eingespielt,
die Datenbank aber noch nicht (vollständig) migriert.

Alle Zeitstempel in Dateinamen sind in UTC (Kennzeichen `Z`).

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
unveränderlich; `rename` ändert nur den Namen. Erlaubt sind Kleinbuchstaben `a-z`, Ziffern und
Bindestrich (nicht am Anfang oder Ende, nicht doppelt), höchstens 50 Zeichen; `service` ist
reserviert. Städte werden nicht gelöscht, sondern gesperrt; ihre Daten bleiben erhalten. Sperren
beendet alle Sitzungen der Konten dieser Stadt. Auf dem Server `php85` statt `php` verwenden.

`invite-admin` ist der Notfallweg ohne API (der übliche Weg ist die Städteverwaltung unter
`service`): Für eine neue Adresse entsteht ein Admin-Konto im Status `invited`, für eine offene
Einladung wird der Link erneuert (der alte wird ungültig); andere vorhandene Konten werden
abgelehnt (Exit-Code 1), ebenso eine gesperrte Stadt. Die Mail geht über den konfigurierten
Transport; schlägt der Versand fehl, erscheint ein Hinweis (Exit-Code 0). `--print-link` gibt den
Link zusätzlich auf stdout aus, z. B. wenn keine Mail ankommt. Der Link ist ein Zugang zum Konto:
nur an die eingeladene Person weitergeben, nicht in Tickets oder Chats kopieren.

## Super-Admins und Benutzer verwalten

```
php bin/user.php superadmin:create --email=chef@example.org --name="Erika Musterfrau"
php bin/user.php superadmin:list                                  # alle Super-Admins
php bin/user.php superadmin:password --email=chef@example.org     # neues Passwort setzen
php bin/user.php superadmin:lock --email=chef@example.org         # sperren
php bin/user.php superadmin:unlock --email=chef@example.org       # entsperren
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
  gesperrte Super-Admin kann nicht gesperrt werden; zuerst einen weiteren anlegen.
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
