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

`api/v1/tenant` gibt es nur unter einer Stadt (unter `service`: 404), alle anderen Routen unter
`service` und unter jeder Stadt. Super-Admins melden sich nur unter `service` an, Konten einer Stadt
nur unter ihrer Stadt; ein Cookie gilt nur dort.

Unter einer Stadt antwortet die API nur, wenn die Stadt existiert und aktiv ist. Der Health-Check
prüft die Datenbankverbindung; bei einer Störung antwortet er mit 503 und
`{"status":"error","database":"error"}`. Das Deploy-Skript ruft ihn unter `service` auf.

### Fehler

Alle Fehler haben das Format `{"error":"code","message":"deutscher Text"}`:

| HTTP | `error`                    | Bedeutung                                                  |
|------|----------------------------|------------------------------------------------------------|
| 400  | `invalid_request`          | Body kein JSON-Objekt, zu groß (> 16 KiB), Feld fehlt      |
| 400  | `invalid_token`            | Link zum Zurücksetzen ungültig, abgelaufen oder benutzt    |
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
     dem JSON-Body (fehlt es, folgt 400).
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
php bin/tenant.php deactivate --slug=immendingen                         # sperren
php bin/tenant.php activate --slug=immendingen                           # entsperren
php bin/tenant.php list --config=PFAD                                    # andere Konfiguration
```

Das Kürzel erscheint in der URL (`/firefighting/<kürzel>/`) und ist nach dem Anlegen
unveränderlich. Erlaubt sind Kleinbuchstaben `a-z`, Ziffern und Bindestrich (nicht am
Anfang oder Ende, nicht doppelt), höchstens 50 Zeichen; `service` ist reserviert. Städte
werden nicht gelöscht, sondern gesperrt; ihre Daten bleiben erhalten. Auf dem Server
`php85` statt `php` verwenden.

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
der Testdatenbank aus und leeren vor jedem Test die fachlichen Tabellen.

### Testdatenbank zurücksetzen

Wurde eine Migration geändert, nachdem sie in der Testdatenbank schon lief, brechen die
Tests mit einem Prüfsummenfehler ab. Dann die Testdatenbank neu anlegen:

```sql
DROP DATABASE firefighting_test;
CREATE DATABASE firefighting_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
